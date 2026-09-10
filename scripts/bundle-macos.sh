#!/usr/bin/env bash
#
# Nix の無い Mac で動く配布物を dist/ に作る。
#
# AOT バイナリは libphp を動的リンクしており、その参照先も rpath も Nix ストアの
# 絶対パスなので、そのまま渡しても他のマシンでは起動しない。ここでやるのは
#   1. 推移的に必要な dylib を全部集める
#   2. 参照を @rpath / @loader_path に書き換えて配布物の中で閉じさせる
#   3. curl と mbstring の拡張を同梱する（AOT バイナリは拡張ゼロで起動するため）
# の3つ。
#
# 拡張は php.ini の絶対パスでしか指定できず、ini のパスは実行前に環境変数で
# 渡すしかない。だから起動は launcher スクリプト経由になる。
#
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$root"

[ "$(uname -s)" = "Darwin" ] || { echo "bundle-macos.sh は macOS 専用です" >&2; exit 1; }
[ -x ./tfcenv ] || { echo "./tfcenv がありません。先に make build を実行してください" >&2; exit 1; }
[ -n "${PHP_INI_SCAN_DIR:-}" ] || { echo "PHP_INI_SCAN_DIR が未設定です。nix develop の中で実行してください" >&2; exit 1; }

version="$(./tfcenv --version | awk '{print $2}')"
out="dist/tfcenv-darwin-$(uname -m)"
rm -rf "$out"
mkdir -p "$out/libexec" "$out/lib/php"

# --- 同梱する拡張。src/ が使う関数から決まる（json は PHP 8 の組み込み） -------
ini_dir="$PHP_INI_SCAN_DIR"
[ -f "$ini_dir/php.ini" ] || { echo "php.ini が見つかりません: $ini_dir" >&2; exit 1; }
extensions=()
for name in curl mbstring; do
    so="$(grep -o "/nix/store/[^\"]*/${name}\.so" "$ini_dir/php.ini" | head -1)"
    [ -n "$so" ] || { echo "php.ini に $name.so が見つかりません" >&2; exit 1; }
    extensions+=("$so")
done

# --- 推移的閉包を集める ---------------------------------------------------------
# 名前は <ストアのハッシュ>-<basename>。libiconv.2.dylib のように basename が
# 衝突する別物が実在するので、平坦化すると片方が黙って壊れる。
declare -a queue=()
declare -A bundled=()

bundled_name() {
    local p="$1" hash
    hash="$(printf '%s' "$p" | sed -n 's|^/nix/store/\([a-z0-9]\{8\}\).*|\1|p')"
    if [ -z "$hash" ]; then
        # ストア外（vendor の libphpx）。パスから安定した短い印を作る
        hash="local$(printf '%s' "$(dirname "$p")" | cksum | awk '{printf "%04x", $1 % 65536}')"
    fi
    printf '%s-%s' "$hash" "$(basename "$p")"
}

rpaths_of() {
    otool -l "$1" 2>/dev/null | awk '/LC_RPATH/{x=1} x&&/path /{print $2; x=0}'
}

# 依存を絶対パスで返す。libphp / libphpx は @rpath 参照なので、
# 対象ファイル自身の LC_RPATH を使って解決しないと閉包から漏れる。
# dylib の otool -L は1行目が自分自身の install name なので、同名は除く。
# "元の文字列<TAB>解決後の絶対パス" を返す。install_name_tool -change は
# ロードコマンドの文字列と完全一致でしか置換しないので、両方が要る。
deps_pairs() {
    local file="$1" self dep base rp
    self="$(basename "$file")"
    otool -L "$file" 2>/dev/null | tail -n +2 | awk '{print $1}' | while read -r dep; do
        base="$(basename "$dep")"
        [ "$base" = "$self" ] && continue
        case "$dep" in
            /nix/store/*) printf '%s\t%s\n' "$dep" "$dep" ;;
            @rpath/*)
                for rp in $(rpaths_of "$file"); do
                    if [ -f "$rp/$base" ]; then printf '%s\t%s\n' "$dep" "$rp/$base"; break; fi
                done
                ;;
        esac
    done
}

deps_of() {
    deps_pairs "$1" | cut -f2
}

for f in ./tfcenv "${extensions[@]}"; do
    while read -r d; do [ -n "$d" ] && queue+=("$d"); done < <(deps_of "$f")
done

while [ ${#queue[@]} -gt 0 ]; do
    dep="${queue[0]}"; queue=("${queue[@]:1}")
    [ -n "${bundled[$dep]:-}" ] && continue
    name="$(bundled_name "$dep")"
    for prev in "${!bundled[@]}"; do
        if [ "${bundled[$prev]}" = "$name" ]; then
            echo "同梱名が衝突しました: $name" >&2
            echo "  $prev" >&2; echo "  $dep" >&2; exit 1
        fi
    done
    bundled[$dep]="$name"
    while read -r d; do [ -n "$d" ] && queue+=("$d"); done < <(deps_of "$dep")
done

# --- コピー（シンボリックリンクは実体に解決する） -------------------------------
cp ./tfcenv "$out/libexec/tfcenv"
for dep in "${!bundled[@]}"; do
    cp -L "$dep" "$out/lib/${bundled[$dep]}"
done
for so in "${extensions[@]}"; do
    cp -L "$so" "$out/lib/php/$(bundled_name "$so")"
done
chmod -R u+w "$out"

# --- 参照の書き換え -------------------------------------------------------------
rewrite() {
    local file="$1" rpath="$2" orig resolved name
    while IFS=$'\t' read -r orig resolved; do
        [ -n "$orig" ] || continue
        name="${bundled[$resolved]:-}"
        [ -n "$name" ] || { echo "閉包から漏れた依存: $resolved ($file)" >&2; exit 1; }
        install_name_tool -change "$orig" "@rpath/$name" "$file"
    done < <(deps_pairs "$file")
    # 既存の rpath は Nix ストアと作業ツリーを指しているので消す
    while read -r old; do
        [ -n "$old" ] && install_name_tool -delete_rpath "$old" "$file" 2>/dev/null || true
    done < <(otool -l "$file" | awk '/LC_RPATH/{f=1} f&&/path /{print $2; f=0}')
    install_name_tool -add_rpath "$rpath" "$file"
}

for dep in "${!bundled[@]}"; do
    f="$out/lib/${bundled[$dep]}"
    install_name_tool -id "@rpath/${bundled[$dep]}" "$f"
    rewrite "$f" "@loader_path"
done
for so in "${extensions[@]}"; do
    rewrite "$out/lib/php/$(bundled_name "$so")" "@loader_path/.."
done
rewrite "$out/libexec/tfcenv" "@executable_path/../lib"

# --- 署名し直す -----------------------------------------------------------------
# arm64 は署名の無い Mach-O を実行できない。install_name_tool は既存の ad-hoc
# 署名を壊すので、書き換えが全部終わってから付け直す。忘れると Killed: 9 になる。
find "$out" -type f \( -name '*.dylib' -o -name '*.so' -o -name tfcenv \) \
    -exec codesign --force --sign - {} + 2>/dev/null

# --- launcher -------------------------------------------------------------------
# 拡張は php.ini に絶対パスでしか書けず、ini の場所は PHP_INI_SCAN_DIR でしか
# 渡せない。展開先が決まるのは利用者の手元なので、起動のたびに ini を書き出す。
# 書き出し先をキャッシュにするのは、/usr/local などに置かれても書けるように。
cat > "$out/tfcenv" <<'LAUNCHER'
#!/bin/sh
set -eu
here=$(cd "$(dirname "$0")" && pwd -P)

# 拡張は php.ini に絶対パスでしか書けないので、展開先が判った実行時に書き出す。
conf="${XDG_CACHE_HOME:-$HOME/.cache}/tfcenv/$(printf '%s' "$here" | tr '/' '-' | tail -c 100)"
mkdir -p "$conf"
for so in "$here"/lib/php/*.so; do echo "extension=$so"; done > "$conf/tfcenv.ini"

# 同梱の libcurl は CA バンドルのパスを持たない。nixpkgs がビルド時に埋めず
# 環境変数に委ねているためで、Nix のあるマシンでは NIX_SSL_CERT_FILE が
# 効いている。配布先にはそれが無く、指定しないと全ての TLS 検証が失敗する。
# 証明書を同梱すると古くなるので、OS が持っている束を探して渡す。
if [ -z "${SSL_CERT_FILE:-}" ]; then
    for ca in /etc/ssl/cert.pem \
              /etc/ssl/certs/ca-certificates.crt \
              /etc/pki/tls/certs/ca-bundle.crt \
              /etc/ssl/ca-bundle.pem; do
        if [ -r "$ca" ]; then SSL_CERT_FILE="$ca"; export SSL_CERT_FILE; break; fi
    done
fi
if [ -z "${SSL_CERT_FILE:-}" ]; then
    echo "tfcenv: could not find a CA certificate bundle on this system." >&2
    echo "  Set SSL_CERT_FILE to one, for example:" >&2
    echo "    export SSL_CERT_FILE=/etc/ssl/cert.pem" >&2
    exit 1
fi

PHP_INI_SCAN_DIR="$conf" exec "$here/libexec/tfcenv" "$@"
LAUNCHER
chmod +x "$out/tfcenv"

cat > "$out/README.txt" <<TXT
tfcenv $version (macOS $(uname -m))

  ./tfcenv --help

PATH に置くなら、この tfcenv だけでなくディレクトリごと移動してください。
中の lib/ を参照して動きます。

  mv $(basename "$out") ~/.local/share/
  ln -sf ~/.local/share/$(basename "$out")/tfcenv ~/.local/bin/tfcenv

Nix も PHP も必要ありません。
TXT

# --- 検証 -----------------------------------------------------------------------
# 壊れた配布物を黙って作らないための関門。ここが通らなければ失敗として終わる。
echo
echo "verifying..."

# 1. Nix ストアへの参照が1つも残っていないこと
leaked=0
while IFS= read -r f; do
    if otool -L "$f" 2>/dev/null | grep -q '/nix/store' \
       || rpaths_of "$f" | grep -q '/nix/store'; then
        echo "  LEAK: $f" >&2
        otool -L "$f" | grep '/nix/store' | sed 's/^/      /' >&2 || true
        rpaths_of "$f" | grep '/nix/store' | sed 's/^/      rpath /' >&2 || true
        leaked=1
    fi
done < <(find "$out" -type f \( -name '*.dylib' -o -name '*.so' -o -path '*/libexec/tfcenv' \))
[ $leaked -eq 0 ] || { echo "  /nix/store への参照が残っています" >&2; exit 1; }
echo "  no /nix/store references"

# 2. Nix の環境変数を一切与えずに起動すること。
#    キャッシュを消してから走らせ、launcher の ini 生成も含めて確かめる。
rm -rf "${XDG_CACHE_HOME:-$HOME/.cache}/tfcenv"
if ! got="$(env -i HOME="$HOME" PATH=/usr/bin:/bin "$out/tfcenv" --version 2>/dev/null)"; then
    echo "  クリーンな環境で起動できませんでした" >&2
    env -i HOME="$HOME" PATH=/usr/bin:/bin "$out/tfcenv" --version >&2 || true
    exit 1
fi
echo "  runs with no nix env: $got"

# 3. 拡張が全部ロードできていること。1つでも失敗すると PHP が stderr に警告を出す。
#    curl.so が読めなければ curl_init が未定義になり、リクエストが1つも送れない。
stderr="$(env -i HOME="$HOME" PATH=/usr/bin:/bin "$out/tfcenv" --version 2>&1 >/dev/null)"
[ -z "$stderr" ] || { echo "  拡張のロードに失敗しています:" >&2; echo "$stderr" >&2; exit 1; }
echo "  extensions load cleanly (curl, mbstring)"

# 4. TLS の CA バンドルが解決できること。
#    同梱の libcurl は CA パスを持たないので、これが無いと全ての TLS が失敗する。
#    --version はリクエストを送らないので 2. と 3. では検出できない。
ca="$(env -i HOME="$HOME" PATH=/usr/bin:/bin sh -c '
    for c in /etc/ssl/cert.pem /etc/ssl/certs/ca-certificates.crt \
             /etc/pki/tls/certs/ca-bundle.crt /etc/ssl/ca-bundle.pem; do
        [ -r "$c" ] && { echo "$c"; break; }
    done')"
[ -n "$ca" ] || { echo "  CA バンドルが見つかりません" >&2; exit 1; }
echo "  CA bundle resolves: $ca ($(grep -c 'BEGIN CERTIFICATE' "$ca") certs)"

# --- アーカイブ -----------------------------------------------------------------
tar -C dist -czf "$out.tar.gz" "$(basename "$out")"

echo
echo "bundled: $out"
du -sh "$out" | awk '{print "  size:  ", $1}'
find "$out" -type f | wc -l | awk '{print "  files: ", $1}'
ls -lh "$out.tar.gz" | awk '{print "  tar:   ", $5, "'"$out"'.tar.gz"}'
