# リリース手順

macOS と Linux でそれぞれネイティブにビルドし、**同じタグの Release に2つの
成果物を添付**する。Mac から Linux 用は作れない（TypePHP のターゲットトリプルが
ホスト依存で、`ld64` は ELF を吐けない）。

**両者が同じコミットからビルドすること**が唯一の落とし穴。ずれても
`--version` は同じ値を返すので誰も気づかない。だから WSL 側は必ず
**タグをチェックアウト**する。片方だけ上がった状態を見せないよう、
両方揃うまで draft のままにする。

以下 `X.Y.Z` は実際のバージョンに読み替える。

---

## 0. 事前確認（Mac）

```bash
cd ~/workspaces/tools/tfcenv
git switch main && git pull
git status --short            # 空であること
```

`src/Version.php` の `Version::STRING` がこれから打つタグと一致しているか確認する。
食い違うと `--version` が嘘をつく。違えば直してコミットしてから進む。

## 1. ビルドして配布物を作る（Mac）

```bash
nix develop -c make clean
nix develop -c make build
nix develop -c make bundle
```

`make clean` から始めるのは必須。ソースを削除しても make が再ビルドしないため、
消したコードを含むバイナリに `make build` が成功と報告することがある。

最後に4項目の検証が出る。1つでも欠けたらアーカイブは作られない。

```
verifying...
  no /nix/store references
  runs with no nix env: tfcenv X.Y.Z
  extensions load cleanly (curl, mbstring)
  CA bundle resolves: /etc/ssl/cert.pem (128 certs)
```

バージョンを目視で確認する。

```bash
./dist/tfcenv-darwin-arm64/tfcenv --version
```

## 2. タグを打つ（Mac）

```bash
git tag -a vX.Y.Z -m "tfcenv X.Y.Z"
git push origin vX.Y.Z
```

## 3. draft で Release を作り、Mac 版を添付（Mac）

```bash
gh release create vX.Y.Z \
  --repo challtech-jp/tfcenv \
  --draft \
  --title "vX.Y.Z" \
  --notes-file <リリースノート.md> \
  dist/tfcenv-darwin-arm64.tar.gz
```

## 4. 同じタグに移動する（WSL）

初回のみ:

```bash
git clone https://github.com/challtech-jp/tfcenv.git
cd tfcenv
gh auth login          # gh が未認証なら
```

毎回:

```bash
cd ~/tfcenv
git fetch --tags
git checkout vX.Y.Z    # main ではなくタグ。ここがずれると中身の違う同一バージョンができる
git rev-parse --short HEAD   # Mac 側の `git rev-parse --short vX.Y.Z^{commit}` と一致すること
```

## 5. ビルドして配布物を作る（WSL）

```bash
nix develop            # 初回は PHP を embed + ZTS でソースビルドする。15〜30分
make clean && make build && make bundle
```

`make bundle` は OS を見て振り分けるので Mac と同じコマンド。Linux では
動的リンカを同梱してランチャーから起動する形になる。

Docker があれば、Nix の無い素の環境で確かめられる:

```bash
./scripts/verify-linux.sh x86_64
```

## 6. 同じ Release に Linux 版を追加（WSL）

```bash
gh release upload vX.Y.Z dist/tfcenv-linux-x86_64.tar.gz --repo challtech-jp/tfcenv
```

## 7. 公開する（どちらからでも）

添付が2つ揃っているか確認してから外す。

```bash
gh release view vX.Y.Z --repo challtech-jp/tfcenv
gh release edit vX.Y.Z --draft=false --repo challtech-jp/tfcenv
```

## 8. 実物で疎通確認（推奨）

`--version` と `--help` はネットワークを触らないので、TLS や CA 証明書の
問題を見逃す。実際にリクエストが飛ぶ経路を1回だけ通す。存在しない
ワークスペースを指定すれば GET が1本飛ぶだけで、何も書き込まない。

```bash
tfcenv add -o <組織名> -w no-such-workspace-xyz KEY=v
```

| 出力 | 意味 |
|---|---|
| `The workspace "..." does not exist in ...` | TLS も認証も通っている |
| `Could not reach Terraform Cloud: SSL certificate problem...` | CA バンドルが解決できていない |
| `Terraform Cloud returned HTTP 401` | TLS は通っている。トークンが渡っていない |

---

## メンバーへの案内

インストールは1行。更新も同じコマンド。

```bash
curl -fsSL https://raw.githubusercontent.com/challtech-jp/tfcenv/main/install.sh | sh
```

ブラウザから落とすと macOS の Gatekeeper が検疫属性を付けて開けなくなる。
`curl` 経由なら付かないので、案内するときはこのコマンドを渡すこと。

WSL で `ca-certificates` が入っていない場合は通信するコマンドが止まる。
`sudo apt install ca-certificates` と案内が出る。
