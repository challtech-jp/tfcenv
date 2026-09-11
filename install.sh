#!/bin/sh
#
# tfcenv をインストールする。
#
#   curl -fsSL https://raw.githubusercontent.com/challtech-jp/tfcenv/main/install.sh | sh
#
# 環境変数:
#   TFCENV_VERSION   入れるタグ（既定: 最新のリリース）
#   TFCENV_PREFIX    展開先の親ディレクトリ（既定: ~/.local/share）
#   TFCENV_BIN       リンクを置く場所（既定: ~/.local/bin）
#
set -eu

REPO=challtech-jp/tfcenv
VERSION="${TFCENV_VERSION:-}"
PREFIX="${TFCENV_PREFIX:-$HOME/.local/share}"
BINDIR="${TFCENV_BIN:-$HOME/.local/bin}"

die() { echo "tfcenv: $*" >&2; exit 1; }

command -v curl >/dev/null 2>&1 || die "curl が必要です。"
command -v tar  >/dev/null 2>&1 || die "tar が必要です。"

# --- どの成果物か ----------------------------------------------------------
os=$(uname -s | tr 'A-Z' 'a-z')
arch=$(uname -m)
case "$os-$arch" in
    darwin-arm64)  target=darwin-arm64 ;;
    linux-x86_64)  target=linux-x86_64 ;;
    darwin-x86_64) die "Intel Mac 版は配布していません。" ;;
    linux-aarch64) die "Linux arm64 版は配布していません。" ;;
    *)             die "対応していない環境です: $os $arch" ;;
esac
asset="tfcenv-$target.tar.gz"

if [ -n "$VERSION" ]; then
    url="https://github.com/$REPO/releases/download/$VERSION/$asset"
else
    # GitHub は latest/download/<asset> を最新リリースへ転送する。
    # API を叩いて JSON を解析する必要がない。
    url="https://github.com/$REPO/releases/latest/download/$asset"
fi

# --- 取得 ------------------------------------------------------------------
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT INT TERM

echo "tfcenv: $asset を取得します"
curl -fsSL --retry 3 -o "$tmp/$asset" "$url" \
    || die "取得できませんでした: $url
  リリースにこの成果物が無いか、まだ公開されていない可能性があります。"

tar xzf "$tmp/$asset" -C "$tmp" || die "アーカイブを展開できませんでした。"
src="$tmp/tfcenv-$target"
[ -x "$src/tfcenv" ] || die "アーカイブの中身が想定と違います: $asset"

# --- 入れ替える前に、確かめられることは全部確かめる ------------------------
# 既存のインストールに触る前にやる。順序を逆にすると、壊れた成果物を置いてから
# 気づくことになり、動いていたものを潰す。
version=$("$src/tfcenv" --version 2>/dev/null) \
    || die "取得したバイナリがこの環境で起動しませんでした。"

# PATH に置くのはリンクなので、リンク経由でも起動できなければ意味がない。
# 配布物の launcher が自分の場所を見失う不具合が実際にあった経路。
mkdir -p "$tmp/link"
ln -s "$src/tfcenv" "$tmp/link/tfcenv"
"$tmp/link/tfcenv" --version >/dev/null 2>&1 || die "この成果物はシンボリックリンク
  経由で起動できません。v0.1.0 の既知の不具合です。何も変更していません。
  TFCENV_VERSION=v0.1.1 以降を指定してください。"

# --- 入れ替える ------------------------------------------------------------
mkdir -p "$PREFIX" "$BINDIR"
dest="$PREFIX/tfcenv-$target"
rm -rf "$dest.old"
[ -d "$dest" ] && mv "$dest" "$dest.old"
mv "$src" "$dest"
rm -rf "$dest.old"

# 実体は lib/ を隣に置いたまま動くので、PATH にはリンクだけを置く。
ln -sf "$dest/tfcenv" "$BINDIR/tfcenv"

echo "tfcenv: $version"
echo "tfcenv: $dest に展開し、$BINDIR/tfcenv からリンクしました"

case ":$PATH:" in
    *":$BINDIR:"*) ;;
    *)
        echo
        echo "  $BINDIR が PATH にありません。シェルの設定に足してください:"
        echo "    export PATH=\"\$HOME/.local/bin:\$PATH\""
        ;;
esac

echo
echo "  tfcenv --help"
