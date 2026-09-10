#!/usr/bin/env bash
#
# Linux 版の配布物を Docker の中で作る。
#
# クロスコンパイルではなくコンテナの中でのネイティブビルド。macOS からは
# TypePHP の --full-static も普通のリンクも駆動できないため（トリプルが
# ホスト依存、ld64 は ELF を吐けない）、Linux は Linux で作る。
#
# 使い方:
#   scripts/build-linux.sh                # ホストと同じアーキ
#   scripts/build-linux.sh linux/amd64    # WSL 向け
#
# /nix は名前付きボリュームに残す。embed + ZTS の PHP は nixpkgs の
# バイナリキャッシュに無くソースからのビルドになるので、消すと毎回やり直しになる。
#
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$root"

platform="${1:-linux/$(uname -m | sed 's/^x86_64$/amd64/; s/^arm64$/arm64/; s/^aarch64$/arm64/')}"
tag="$(printf '%s' "$platform" | tr '/' '-')"
volume="tfcenv-nix-$tag"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# ホストのビルド成果物（Mach-O のオブジェクト、dylib）を持ち込まないよう、
# git が知っているファイルだけを渡す。
git archive --format=tar HEAD | tar -x -C "$work"
# コミットしていない変更も反映する（スクリプトを直しながら回すため）
git diff HEAD --binary | (cd "$work" && git apply --allow-empty - 2>/dev/null || true)
cp -R scripts "$work/"

echo "platform : $platform"
echo "volume   : $volume  (nix store は残る)"
echo "workdir  : $work"
echo

docker run --rm --platform "$platform" \
    -v "$volume:/nix" \
    -v "$work:/src" \
    -w /src \
    -e NIX_CONFIG=$'experimental-features = nix-command flakes\nfilter-syscalls = false' \
    nixos/nix:latest \
    bash -euo pipefail -c '
        nix develop --command bash -euo pipefail -c "
            composer install --no-interaction --no-progress
            make build
            ./scripts/bundle-linux.sh
        "
    '

mkdir -p dist
cp -R "$work"/dist/tfcenv-linux-* dist/ 2>/dev/null || true
echo
ls -lh dist/tfcenv-linux-*.tar.gz 2>/dev/null || echo "配布物が生成されませんでした"
