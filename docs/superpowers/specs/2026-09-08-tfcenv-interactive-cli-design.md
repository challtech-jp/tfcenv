# tfcenv インタラクティブ CLI 設計 (P1)

- 日付: 2026-09-08
- 状態: 承認済み
- 対象: P1（インタラクティブモード）
- 読みやすい版: [2026-09-08-tfcenv-interactive-cli-design.html](./2026-09-08-tfcenv-interactive-cli-design.html)
  （目次・状態バッジ・フロー図つき。本ファイルと同じ内容）

## 背景

Terraform Cloud のワークスペース変数は Web コンソールからしか登録できない。
`acme-terraform` の PR では「変数を追加する PR を出す前に TFC 側へ値を入れておかないと
`plan` が落ちる」という段取りが繰り返し発生していて（T-026 / T-057）、
そのたびにブラウザでワークスペースを開いて手入力している。

`tfcenv` はこの登録作業を CLI に移す。PHP で書き、TypePHP で
ネイティブバイナリに AOT コンパイルする。

## スコープの分解

全体は3つのサブプロジェクトに分かれる。本書は **P1 のみ**を扱う。

| | 内容 | 状態 |
|---|---|---|
| **P1** | インタラクティブモード | 本書 |
| **P2** | JSON 入力モード | 未着手。P1 の中間表現を再利用する |
| **P3** | 静的バイナリ配布（macOS arm64 / Linux x86_64 + CI） | 未着手。PHP ソースには触らない |

P1 → P2 → P3 の順で進める。P3 が PHP ソースを一切変更しないため、
また TypePHP の制約を守って書いたソースは phpmicro / PHAR でもそのまま使えるため、
P1 を先に片付けても手戻りが出ない。

### 非目標（P1）

- JSON / YAML などのファイル入力（P2）
- 静的リンクによる自己完結バイナリ（P3）
- 変数の一覧表示・削除
- Variable Set（ワークスペース変数のみ扱う）
- 複数ワークスペースへの同時登録
- `hcl: true` の変数（リスト・マップ値）。`hcl` 属性は送らず API のデフォルトに任せる
- Terraform Enterprise（self-hosted）対応。ただし API のベース URL は
  `Tfc\Client` のコンストラクタ引数にしておき、深い場所にハードコードはしない

## 検証済みの技術的事実

実装前にスパイクで確定させた事実。再調査を避けるため記録する。

| 項目 | 結果 |
|---|---|
| `json_encode` / `json_decode` / `getenv` | AOT バイナリで組み込みで動く |
| `curl_*` | **`PHP_INI_SCAN_DIR` が必要。** nixpkgs は拡張を共有 `.so` で配り絶対パスを `php.ini` に列挙するが、AOT バイナリは libphp を直接埋め込むため拡張ゼロで起動する。指定すれば動作し、`app.terraform.io/api/v2/ping` に到達して 204 を得た |
| `curl_close()` | PHP 8.5 で deprecated。呼ばない |
| `shell_exec` / `exec` / `proc_open` / `fread` / `fgets` / `stream_set_blocking` / `stream_isatty` | 使える（すべて core） |
| `readline` / `posix_isatty` | **使えない**（拡張）。`stream_isatty` で代替する |
| `stty -g` → `stty raw -echo` → `fread(STDIN, 1)` | pty 上で動作確認済み。生キー読み取りが可能 |
| ANSI エスケープ | 動く |
| `interface` / `trait` | 動く |
| `enum`（backed 値 / メソッド / `match` / `from()` / `->name`） | 動く |
| `readonly` promoted プロパティ | 動く。**可視性の明示が必須** |
| `extends \Exception` | **動く。** `getMessage()` / `getCode()` も保持される。wiki が指摘していた「internal クラス継承禁止」との矛盾は、こちら（継承できる）が正しい |
| `RuntimeException` などの組み込み例外 | 動く |
| `static fn` / `array_map` / 配列関数 | 動く |
| `main()` の名前空間 | **グローバル必須。** 名前空間内に書くと `Ns\main()` 扱いで「`main()` が定義されていない」と拒否される |
| `tpc --full-static` | 使わない。`getFullStaticTargetTriple()` が `<arch>-unknown-linux-musl` をハードコードしており常に Linux/musl 出力になる。さらに必須の SDK（`phpx/full-static/sdk`）が composer 配布物に含まれない |

## コマンド

P1 では `add` の1つだけ。

```
tfcenv add        ワークスペース変数を対話的に登録する
tfcenv --help
tfcenv --version
```

サブコマンド構造にしておくことで、P2 で `tfcenv apply -f vars.json` を素直に足せる。

終了コードは成功 `0` / 失敗 `1`。`main()` は `void` 固定なので `exit(1)` で返す。

## 環境変数

| 変数 | 必須 | 用途 |
|---|---|---|
| `TFC_TOKEN` | 必須 | API トークン。これ以外の取得元は持たない |
| `TFC_ORG` | 任意 | organization 入力のデフォルト値 |
| `NO_COLOR` | 任意 | 設定されていれば ANSI 出力を無効化 |

`TFC_TOKEN` が未設定なら、トークン発行ページ
（`https://app.terraform.io/app/settings/tokens`）を案内して `exit(1)`。

## 使用する Terraform Cloud API

すべて `application/vnd.api+json`、`Authorization: Bearer $TFC_TOKEN`。
ベース URL は `https://app.terraform.io/api/v2`。

| 目的 | エンドポイント |
|---|---|
| ワークスペース一覧 | `GET /organizations/:org/workspaces?page[size]=100&page[number]=N` |
| 既存変数の一覧 | `GET /workspaces/:ws_id/vars` |
| 変数の作成 | `POST /workspaces/:ws_id/vars` |
| 変数の更新 | `PATCH /workspaces/:ws_id/vars/:var_id` |

ページングは `meta.pagination.next-page` が `null` になるまで辿る。
TFC のデフォルトページサイズは 20、最大 100。

ワークスペース ID は一覧のレスポンスに含まれるので、
名前から ID を引くための追加リクエストは行わない。

**`sensitive: true` の変数は API から値を読み戻せない。**
そのため衝突時に見せられるのはメタデータ（category / sensitive / description）だけで、
現在値は表示できない。

### 実装時に確認する事項

**`sensitive: true` の変数を `PATCH` で `false` に戻せるかは未検証。**
トークンがないため確認できていない。戻せない場合は 422 が返るので、
既存のエラー経路で「TFC では sensitive を解除できない」と伝える。
`Update the value and attributes` で sensitive を Yes → No にしたときに通る経路なので、
実装時に実物で確かめる。

## フロー

```
1. TFC_TOKEN 確認              未設定 → 発行URL を案内して exit(1)
2. stream_isatty(STDIN)        非TTY → 「対話モードは TTY が必要」と伝えて exit(1)
3. organization                TFC_ORG があればデフォルト値、なければ入力
4. ワークスペース一覧を取得     ページングを辿る → 絞り込みピッカーで選択
5. 既存変数の一覧を取得         衝突判定用に key => Variable で保持
6. 変数ループ
     key 入力
       既存になければ  → 新規として属性をすべて入力
           category 選択     terraform（既定） / env
           sensitive 選択
           value 入力        sensitive が Yes なら非表示入力、No ならそのまま表示
           description 入力  任意。Enter で省略
       既存にあれば    → 警告し、既存のメタデータ
                          （category / sensitive / description）を表示して選択させる
           Update the value                … value のみ入力し直す。
                                             他の属性は既存の値を保持する
           Update the value and attributes … category / sensitive / value / description を
                                             順に聞く。value 以外は既存の値がデフォルトで
                                             入っていて Enter で維持できる
           Skip this variable              … バッチから外す
           Use a different key             … key 入力に戻る（再度衝突判定）
     「もう1件追加しますか?」  Yes → ループ先頭 / No → 次へ
7. 確認画面
     create / update の内訳と件数
     sensitive な値はマスクして表示。非 sensitive はそのまま表示
     Yes / No。No なら何も送らず exit(0)
8. 適用
     1件ずつ POST / PATCH し、結果を逐次表示
     1件失敗しても中断せず残りを続ける
9. 集計
     成功・失敗の件数を出す。失敗が1件でもあれば exit(1)
```

再実行しても安全である。手順 5 で既存変数を取り直すため、
前回の部分失敗のあとに同じ操作を繰り返しても衝突として正しく検出される。

### 入力順序についての注記

`sensitive` を `value` より先に聞く。ブレスト時のモックアップは
`value` → `category` → `sensitive` の順だったが、それでは
「まだ聞いていない `sensitive` の値に基づいて `value` の入力を非表示にする」
という不可能な順序になるため、実装可能な順序に入れ替えた。

非 sensitive な値をわざわざ隠すのはタイプミスの確認ができず不便なので、
「常に非表示にする」という解決は採らない。

衝突時の更新は2段構えにする。既定の `Update the value` は `value` だけを聞き直し、
`category` / `sensitive` / `description` は既存の値を保持する。
「値を差し替えたいだけ」という一番多いケースを1キーで済ませるため。

属性も変えたいときは `Update the value and attributes` を選ぶ。この経路では
`category` / `sensitive` / `description` に既存の値がデフォルトとして入っていて、
Enter でそのまま維持できる。`value` だけは API から読み戻せないためデフォルトを置けず、
常に入力が必要になる。

### ピッカーのキー割り当て

| キー | 動作 |
|---|---|
| 印字可能文字 | 絞り込み文字列に追加 |
| Backspace | 絞り込み文字列から1文字削除 |
| ↑ / ↓、`Ctrl-P` / `Ctrl-N` | 候補の選択移動 |
| Enter | 選択を確定 |
| `Ctrl-C` | 端末を復元して `exit(1)` |

矢印キーは `ESC [ A` / `ESC [ B` の3バイト列として届くため、
`ESC` を読んだら続きを読んでシーケンスとして解釈する。

## 中間表現

対話で組み立てるデータは次の形にする。これは P2 の JSON スキーマとそのまま同じ形であり、
P2 は「JSON からこの構造を作る入力アダプタ」を足すだけで済む。

```
organization: string
workspace:    string            (名前。ID は実行時に解決する)
variables:    [
  {
    key:         string
    value:       string
    category:    "terraform" | "env"
    sensitive:   bool
    description: string          (空文字列可)
  },
  ...
]
```

`AddCommand` の責務は「対話でこの構造を作る」ことだけに限る。
確認画面と適用処理は入力方法に依存しない共通コンポーネントに置く。

## モジュール構成

```
src/
  main.php                       グローバル main(int $argc, array $argv): void。薄く保つ
  Cli/Application.php            引数解析・コマンド振り分け・トップレベルの例外境界
  Cli/AddCommand.php             フローの手順書
  Terminal/Terminal.php          stty の退避と復元・生キー読み取り・TTY 判定
  Terminal/Prompt.php            text / password / confirm / select
  Terminal/Picker.php            絞り込み付きリスト選択
  Terminal/Style.php             ANSI。NO_COLOR と非TTY で自動的に無効化
  Tfc/HttpTransport.php          interface。テストで差し替える境界
  Tfc/CurlTransport.php          curl による実装
  Tfc/Client.php                 JSON:API の組み立てとエラー整形
  Tfc/WorkspaceRepository.php    一覧取得・名前から ID の解決
  Tfc/VariableRepository.php     一覧取得・create・update
  Tfc/Variable.php               値オブジェクト
  Tfc/Category.php               enum terraform | env
  Tfc/TfcException.php           extends \Exception。HTTP ステータスと detail を保持
tests/                           PHPUnit。project.yml の sources は src のみなので AOT 対象外
```

設計の要点は `HttpTransport` と `Terminal` をインタフェースにしていることで、
この2つを差し替えれば対話フロー全体をテストから駆動できる。

## TypePHP 制約の反映

上の「検証済みの技術的事実」に加えて、コードスタイルに落とす規則。

- `main()` は `src/main.php` にグローバル名前空間で置く。クラスは名前空間付きの別ファイルに書く
- **`toArray()` / `toString()` / `toInt()` / `toAny()` / `toRef()` をメソッド名に使わない。**
  TypePHP はこれらを予約キーワードメソッドとして通常のメソッドより先に解決するため、
  同名メソッドは意図した意味で呼ばれない。DTO の変換は `toPayload()` などにする
- `switch` を使わず `match` を使う。非 `int`/`bool` の `switch` は
  全 case を `return` / `break` / `continue` / `exit` / `throw` で明示終端する必要があるため
- コンストラクタ promotion は可視性を明示する（`private readonly string $x`）。
  PHP 8.5 の暗黙 public 形式 `final string $x` は受け付けられない
- `declare(strict_types=1)` は書かない。TypePHP は常に strict なので冗長
- グローバルスコープに実行文を書かない（宣言・`use`・`declare`・定数定義のみ）
- 全ソースファイルを UTF-8 にする
- バージョン番号はグローバル定数ではなくクラス定数で持つ

## エラーハンドリングと安全性

- `TfcException` に HTTP ステータスと JSON:API の `errors[].detail` を載せる。
  `Application` がトップレベルで catch し、1行のメッセージを出して `exit(1)`
- curl のネットワークエラー（名前解決失敗・タイムアウト等）も `TfcException` に正規化して同じ経路に流す
- **`TFC_TOKEN` の値を出力しない。** エラーメッセージ・デバッグ出力・例外のいずれにも載せない
- `sensitive: true` の値は確認画面・進捗表示・エラーのすべてでマスクする
- **Ctrl+C 対策。** raw モード中に SIGINT で落ちると端末が raw のまま残り、
  シェルが使えなくなる。`pcntl_async_signals(true)` + `pcntl_signal(SIGINT, ...)` で
  `stty` を復元してから終了する。`register_shutdown_function` は SIGINT では走らないので不十分。
  `pcntl` は拡張なので **P3 の静的ビルドに含める拡張リストに入る**（static-php-cli は対応している）。
  実装時に pcntl が AOT バイナリで使えないと判明した場合は、
  ピッカーを raw モード不要の番号選択メニューに落とすことでフォールバックする

## テスト戦略

TDD で進める。

- `tests/` を PHPUnit でインタプリタ実行し、`composer test` に登録する。
  `project.yml` の `sources` は `src` だけなので `tests/` は AOT に含まれない
- `HttpTransport` のフェイクで TFC API の応答を再現する。少なくとも
  正常系 / 422（キー衝突）/ 401（トークン不正）/ ページングの継続 を用意する
- `Terminal` のフェイクにキー列を流し、対話フローの分岐（update / skip / rename、
  確認画面での No、途中の入力ミス）を検証する
- AOT バイナリに対しては `--version` と `--help` のスモークテストを `make` に追加する

## P3 に引き渡す未解決事項

P1 の実装では解決しないが、P3 の着手時に潰す必要がある項目。

- static-php-cli の embed ビルドが `bin/php-config` を出力するか
- 静的 PHP を ZTS / NTS どちらで作るか（PHPX は `php-config` から自動検出するので揃えれば可）
- 拡張が引く静的依存（`libcurl.a` / `libssl.a` / `libz.a` など）を
  `tpc` の `-l` / `-L` でどう渡すか
- P1 が必要とする拡張の一覧: **`curl`, `mbstring`, `pcntl`**。
  `json` は PHP 8 では常に組み込みなので不要（スパイクでも
  `PHP_INI_SCAN_DIR` なしで動いた）。`mbstring` は description に
  日本語が入るため、確認画面の桁揃えに `mb_strwidth()` が必要になる
- Linux x86_64 のビルドは Apple Silicon 上の qemu では非現実的に遅いため、
  リリースは GitHub Actions で行う
