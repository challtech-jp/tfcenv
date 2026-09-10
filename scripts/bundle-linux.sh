#!/usr/bin/env bash
#
# Nix の無い Linux で動く配布物を dist/ に作る。コンテナの中で実行される。
#
# macOS 版と違うのは1点だけだが、その1点が重い。**動的リンカ自身が Nix ストアに
# ある。** ELF の PT_INTERP は $ORIGIN を解釈しないので、実行ファイルに相対パスを
# 書き込む手が使えない。ローダを同梱して launcher から明示的に起動する
# （nix bundle が採っているのと同じ形）。
#
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$root"

[ "$(uname -s)" = "Linux" ] || { echo "bundle-linux.sh は Linux 専用です" >&2; exit 1; }
[ -x ./tfcenv ] || { echo "./tfcenv がありません。先に make build を実行してください" >&2; exit 1; }
[ -n "${PHP_INI_SCAN_DIR:-}" ] || { echo "PHP_INI_SCAN_DIR が未設定です。nix develop の中で実行してください" >&2; exit 1; }
command -v patchelf >/dev/null || { echo "patchelf がありません" >&2; exit 1; }

# Linux では tpc がリンク時に rpath を焼かないので、ビルド直後のバイナリは
# 作業ツリーの中でも libphpx.so / libphp.so を見つけられない。ldd による閉包の
# 収集も同じ理由で "not found" になるため、探索パスを明示する。
# 配布物の側はこのあと $ORIGIN に付け替えるので、ここだけの話。
export LD_LIBRARY_PATH="$root/vendor/swoole/phpx/lib:${PHP_HOME:-/nonexistent}/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"

version="$(./tfcenv --version | awk '{print $2}')"
arch="$(uname -m)"
out="dist/tfcenv-linux-$arch"
rm -rf "$out"
mkdir -p "$out/libexec" "$out/lib/php"

# --- 同梱する拡張。src/ が使う関数から決まる（json は PHP 8 の組み込み） -------
[ -f "$PHP_INI_SCAN_DIR/php.ini" ] || { echo "php.ini が見つかりません: $PHP_INI_SCAN_DIR" >&2; exit 1; }
extensions=()
for name in curl mbstring; do
    so="$(grep -o "/nix/store/[^\"]*/${name}\.so" "$PHP_INI_SCAN_DIR/php.ini" | head -1)"
    [ -n "$so" ] || { echo "php.ini に $name.so が見つかりません" >&2; exit 1; }
    extensions+=("$so")
done

# --- 推移的閉包 -----------------------------------------------------------------
# ldd は再帰的に解決済みの一覧を出す。拡張は dlopen されるので、バイナリの ldd
# だけでは libcurl や libonig が漏れる。拡張の分も足す。
closure() {
    ldd "$1" 2>/dev/null | awk '
        /=>/ && $3 ~ /^\// { print $3 }
        !/=>/ && $1 ~ /^\// { print $1 }   # ローダ行（ld-linux-*.so => なし）
    '
}

# 解決できない依存があれば止める。"not found" のまま進むと、その1つが
# 欠けた配布物が黙って出来上がる。
for f in ./tfcenv "${extensions[@]}"; do
    missing="$(ldd "$f" 2>/dev/null | awk '/not found/ {print $1}')"
    [ -z "$missing" ] || { echo "解決できない依存 ($f):" >&2; echo "$missing" >&2; exit 1; }
done

libs=()
while read -r l; do [ -n "$l" ] && libs+=("$l"); done < <(
    { for f in ./tfcenv "${extensions[@]}"; do closure "$f"; done; } | sort -u
)

# basename が衝突すると SONAME での解決が壊れる。Linux では滅多に起きないが、
# 起きたときに黙って片方が消える方が怖いので止める。
dupes="$(printf '%s\n' "${libs[@]}" | xargs -n1 basename | sort | uniq -d)"
[ -z "$dupes" ] || { echo "basename が衝突しています:" >&2; echo "$dupes" >&2; exit 1; }

loader=""
for l in "${libs[@]}"; do
    case "$(basename "$l")" in ld-linux*|ld64.so*|ld-musl*) loader="$(basename "$l")" ;; esac
done
[ -n "$loader" ] || { echo "動的リンカを閉包から特定できませんでした" >&2; exit 1; }

# --- コピー ---------------------------------------------------------------------
cp ./tfcenv "$out/libexec/tfcenv"
for l in "${libs[@]}"; do cp -L "$l" "$out/lib/$(basename "$l")"; done
for so in "${extensions[@]}"; do cp -L "$so" "$out/lib/php/$(basename "$so")"; done
chmod -R u+w "$out"

# --- rpath の書き換え -----------------------------------------------------------
# ローダを明示起動するので --library-path でも解決できるが、rpath も揃えておく。
# 拡張は dlopen 経由なので、自分の隣ではなく1つ上の lib/ を見る必要がある。
for l in "$out"/lib/*; do
    [ -f "$l" ] || continue
    # 動的リンカ自身は触らない。ld.so は自分をブートストラップする特殊な ELF で、
    # patchelf がプログラムヘッダを動かすと初期化前に落ちる（LD_DEBUG すら
    # 出力されない segfault になる）。そもそも rpath を必要としない。
    [ "$(basename "$l")" = "$loader" ] && continue
    patchelf --set-rpath '$ORIGIN' "$l" 2>/dev/null || true
done
for so in "$out"/lib/php/*.so; do
    patchelf --set-rpath '$ORIGIN/..' "$so"
done
patchelf --set-rpath '$ORIGIN/../lib' "$out/libexec/tfcenv"

# --- launcher -------------------------------------------------------------------
# PT_INTERP は $ORIGIN を解釈しないので、ローダを直接起動して実行ファイルを渡す。
# 拡張の ini と CA バンドルの事情は macOS 版と同じ。
cat > "$out/tfcenv" <<LAUNCHER
#!/bin/sh
set -eu

# シンボリックリンク経由で起動されても配布物の場所を見失わないよう、
# \$0 のリンクを辿ってから実体のディレクトリを求める。PATH に置くのは
# ~/.local/bin/tfcenv へのリンクなので、dirname "\$0" だとそちらを指してしまう。
self="\$0"
while [ -L "\$self" ]; do
    link=\$(readlink "\$self")
    case "\$link" in
        /*) self="\$link" ;;
        *)  self="\$(dirname "\$self")/\$link" ;;
    esac
done
here=\$(cd "\$(dirname "\$self")" && pwd -P)

# 拡張は php.ini に絶対パスでしか書けないので、展開先が判った実行時に書き出す。
conf="\${XDG_CACHE_HOME:-\$HOME/.cache}/tfcenv/\$(printf '%s' "\$here" | tr '/' '-' | tail -c 100)"
mkdir -p "\$conf"
for so in "\$here"/lib/php/*.so; do echo "extension=\$so"; done > "\$conf/tfcenv.ini"

# 同梱の libcurl は CA バンドルのパスを持たない。nixpkgs がビルド時に埋めず
# 環境変数に委ねているためで、配布先には NIX_SSL_CERT_FILE が無い。
# 指定しないと全ての TLS 検証が失敗する。OS が持つ束を探して渡す。
if [ -z "\${SSL_CERT_FILE:-}" ]; then
    for ca in /etc/ssl/certs/ca-certificates.crt \\
              /etc/pki/tls/certs/ca-bundle.crt \\
              /etc/ssl/ca-bundle.pem \\
              /etc/ssl/cert.pem; do
        if [ -r "\$ca" ]; then SSL_CERT_FILE="\$ca"; export SSL_CERT_FILE; break; fi
    done
fi
# 束が無いときに止めるのは、実際に通信するコマンドのときだけ。--help と
# --version は TLS を使わないので、証明書が無い環境でも読めないと困る。
if [ -z "\${SSL_CERT_FILE:-}" ]; then
    case "\${1:-}" in
        --help|-h|--version|-v|"") ;;
        *)
            echo "tfcenv: could not find a CA certificate bundle on this system." >&2
            echo "  Install your distribution's CA certificates, for example:" >&2
            echo "    sudo apt install ca-certificates" >&2
            exit 1
            ;;
    esac
fi

# ELF の PT_INTERP は \$ORIGIN を解釈しないため、ローダを明示的に起動する。
PHP_INI_SCAN_DIR="\$conf" exec "\$here/lib/$loader" \\
    --library-path "\$here/lib" "\$here/libexec/tfcenv" "\$@"
LAUNCHER
chmod +x "$out/tfcenv"

cat > "$out/README.txt" <<TXT
tfcenv $version (Linux $arch)

  ./tfcenv --help

PATH に置くなら、この tfcenv だけでなくディレクトリごと移動してください。
中の lib/ を参照して動きます。

  mv $(basename "$out") ~/.local/share/
  ln -sf ~/.local/share/$(basename "$out")/tfcenv ~/.local/bin/tfcenv

Nix も PHP も必要ありません。
TXT

# --- 検証 -----------------------------------------------------------------------
echo
echo "verifying..."

# 見るのは rpath だけ。PT_INTERP は Nix ストアのままで構わない — launcher が
# ローダを明示起動するので参照されないからで、これは設計の一部。
# （glibc の libc.so.6 自身も PT_INTERP を持つので、そちらも巻き込まない）
leaked=0
while IFS= read -r f; do
    rp="$(patchelf --print-rpath "$f" 2>/dev/null || true)"
    case "$rp" in
        */nix/store*) echo "  LEAK: $f"; echo "      rpath $rp"; leaked=1 ;;
    esac
done < <(find "$out" -type f \( -name '*.so' -o -name '*.so.*' -o -path '*/libexec/tfcenv' \))
[ $leaked -eq 0 ] || { echo "  /nix/store を指す rpath が残っています" >&2; exit 1; }
echo "  no /nix/store rpaths"

interp="$(patchelf --print-interpreter "$out/libexec/tfcenv" 2>/dev/null || true)"
echo "  PT_INTERP (bypassed by the launcher): ${interp:-none}"
[ -x "$out/lib/$loader" ] || { echo "  ローダが同梱されていません" >&2; exit 1; }
echo "  loader bundled: $loader"

# シンボリックリンク経由でも動くこと。PATH に置くのはリンクなので、
# ここが壊れていると案内どおりに入れた人だけが起動できない。
lntmp="$(mktemp -d)"
ln -s "$PWD/$out/tfcenv" "$lntmp/tfcenv"
if ! "$lntmp/tfcenv" --version >/dev/null 2>&1; then
    echo "  シンボリックリンク経由で起動できません" >&2
    "$lntmp/tfcenv" --version >&2 || true
    rm -rf "$lntmp"; exit 1
fi
rm -rf "$lntmp"
echo "  runs through a symlink too"

tar -C dist -czf "$out.tar.gz" "$(basename "$out")"

echo
echo "bundled: $out"
du -sh "$out" | awk '{print "  size:  ", $1}'
find "$out" -type f | wc -l | awk '{print "  files: ", $1}'
ls -lh "$out.tar.gz" | awk '{print "  tar:   ", $5}'
