#!/usr/bin/env bash
#
# Linux 版の配布物を、Nix の無い素のコンテナで動かして確かめる。
# WSL のメンバーが使うのは Ubuntu なので、それに合わせる。
#
# ここを通らないものは配らない。ホスト側での確認では、Nix の環境変数や
# /nix/store が見えているせいで通ってしまう経路がある。
#
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$root"

arch="${1:-$(uname -m | sed 's/^arm64$/aarch64/')}"
platform="linux/$(printf '%s' "$arch" | sed 's/^x86_64$/amd64/; s/^aarch64$/arm64/')"
bundle="dist/tfcenv-linux-$arch"

[ -d "$bundle" ] || { echo "$bundle がありません。先に scripts/build-linux.sh を実行してください" >&2; exit 1; }

echo "verifying $bundle on ubuntu:24.04 ($platform), no nix"
echo

docker run --rm --platform "$platform" \
    -v "$PWD/$bundle:/opt/tfcenv:ro" \
    -e HOME=/root \
    ubuntu:24.04 \
    bash -euo pipefail -c '
        echo "--- この環境に nix はあるか ---"
        ls /nix >/dev/null 2>&1 && { echo "  /nix が見えている。検証にならない"; exit 1; } || echo "  /nix なし"
        echo "  NIX_SSL_CERT_FILE=${NIX_SSL_CERT_FILE:-<未設定>}"
        echo

        echo "--- 1. 起動する（CA 証明書がまだ無い状態でも） ---"
        /opt/tfcenv/tfcenv --version
        /opt/tfcenv/tfcenv --help | head -1

        echo "--- 2. stderr が空（同梱した拡張のロードに成功している） ---"
        err=$(/opt/tfcenv/tfcenv --version 2>&1 >/dev/null)
        [ -z "$err" ] || { echo "  STDERR: $err"; exit 1; }
        echo "  空"

        echo "--- 3. CA 証明書が無い環境では、通信するコマンドだけが止まる ---"
        out=$(TFC_TOKEN=dummy /opt/tfcenv/tfcenv add -o o -w stg K=v 2>&1) && rc=0 || rc=$?
        echo "$out" | sed "s/^/      /"
        [ "$rc" = 1 ] || { echo "  終了コードが 1 ではありません: $rc"; exit 1; }
        echo "$out" | grep -q "CA certificate" || { echo "  期待した案内が出ていません"; exit 1; }

        echo "--- 4. ca-certificates を入れる ---"
        apt-get -qq update >/dev/null 2>&1
        DEBIAN_FRONTEND=noninteractive apt-get -qq install -y ca-certificates >/dev/null 2>&1
        ca=/etc/ssl/certs/ca-certificates.crt
        echo "  $ca ($(grep -c "BEGIN CERTIFICATE" "$ca") certs)"

        echo "--- 5. 引数の解釈（ネットワーク前で止まる経路） ---"
        TFC_TOKEN=dummy /opt/tfcenv/tfcenv add -o o -w stg --plain K=v 2>&1 | sed "s/^/      /" || true
        TFC_TOKEN=dummy /opt/tfcenv/tfcenv add -o o -w stg 2>&1 | sed "s/^/      /" || true

        echo "--- 6. 対話モードは TTY を要求する ---"
        TFC_TOKEN=dummy /opt/tfcenv/tfcenv add < /dev/null 2>&1 | sed "s/^/      /" || true

        echo "--- 7. ldd: 配布物の外を指していないか ---"
        ld=$(ls /opt/tfcenv/lib/ld-*.so* | head -1)
        "$ld" --library-path /opt/tfcenv/lib --list /opt/tfcenv/libexec/tfcenv \
          | awk "{print \$3}" | grep -v "^/opt/tfcenv/" | grep -v "^$" | sed "s/^/      外部: /" || true
        echo "      （上に何も無ければ全て配布物の中で解決している）"
    '
echo
echo "ok"
