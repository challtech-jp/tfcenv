#!/usr/bin/env bash
# 動いている OS 向けの配布物を作る。中身は OS ごとに別のスクリプト。
# 参照の書き換え方（install_name_tool と patchelf）も、動的リンカの扱いも
# 根本的に違うので、共通化せずに分けている。
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"

case "$(uname -s)" in
    Darwin) exec "$here/bundle-macos.sh" "$@" ;;
    Linux)  exec "$here/bundle-linux.sh" "$@" ;;
    *)      echo "配布物の作成に対応していない OS です: $(uname -s)" >&2; exit 1 ;;
esac
