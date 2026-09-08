# tfcenv インタラクティブ CLI (P1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `tfcenv add` を実装し、Terraform Cloud のワークスペース変数を対話的に登録・更新できるようにする。

**Architecture:** 3層。`Tfc/` が HTTP と JSON:API、`Terminal/` が端末入出力、`Cli/` が手順とコマンド。`HttpTransport` と `Terminal` の2つのインタフェースを差し替えることで、ネットワークも端末も使わずに対話フロー全体をテストから駆動する。確認画面と適用処理は入力方法に依存しない共通コンポーネントに置き、P2（JSON 入力）がそのまま再利用する。

**Tech Stack:** PHP 8.5（Nix、embed SAPI + ZTS）/ TypePHP 0.7 AOT / PHPUnit 13.3 / ext-curl / ext-mbstring

**Spec:** `docs/superpowers/specs/2026-09-08-tfcenv-interactive-cli-design.md`
（HTML 版: 同ディレクトリの `.html`）

---

## Global Constraints

すべてのタスクの要件に、暗黙にこの節が含まれる。

**TypePHP の言語制約**（spec「TypePHP 制約の反映」より）

- `src/` に置くコードは**素の PHP だけで書く**。TypePHP 独自機能（ユニバーサルメソッド `$s->upper()` / `$arr->count()`、`std::` コンテナ、`#[Native]`、`bigInt` などの高精度型）を**一切使わない**。テストはインタプリタで走るのでこれらは未定義になり、さらにユニバーサルメソッドは型によって破壊的・非破壊的が変わる。標準関数（`strtoupper()` / `count()`）を使う。
- グローバル関数を `str_` / `array_` / `int_` / `float_` / `bool_` / `stream_` / `bigint_` / `decimal_` / `bigfloat_` で始める名前で定義しない。TypePHP がこれを拡張メソッドとして自動発見してしまう。
- `main()` は `src/main.php` にグローバル名前空間で定義する。`main(int $argc, array $argv): void` のみ。返り値は `void` 固定なので終了コードは `exit()` で返す。
- グローバルスコープに実行文を書かない。`src/main.php` も `use` と `function main()` の宣言だけ。
- **メソッド名に `toInt` / `toString` / `toFloat` / `toArray` / `toAny` / `toRef` を使わない**（大文字小文字は区別しない）。予約キーワードメソッドとして通常メソッドより先に解決される。本計画は `payload()` / `displayValue()` のように `to` + 型名を避けた名前で統一している。
- `switch` を使わず `match` を使う。
- コンストラクタ promotion は可視性を明示する（`public readonly string $x` / `private readonly Client $client`）。PHP 8.5 の暗黙 public 形式 `final string $x` は受け付けられない。
- デフォルト値付き引数を必須引数より前に置かない。
- `declare(strict_types=1)` は書かない（TypePHP は常に strict なので冗長）。
- 全ソースファイルを UTF-8 にする。
- 検証済みで使えるもの: `enum`（backed 値・メソッド・`match`・`from()`・`->name`）、`readonly` promoted プロパティ、`interface`、`trait`、`extends \Exception`（`getMessage()` / `getCode()` 保持）、`static fn`、配列関数、`json_encode` / `json_decode`、`getenv`、`shell_exec` / `exec` / `proc_open` / `fread` / `fgets` / `stream_set_blocking` / `stream_isatty`。
- 検証済みで使えないもの: `readline`、`posix_isatty`。`stream_isatty` で代替する。

**名前空間とパス**

- PSR-4: `Tfcenv\` → `src/`、`Tfcenv\Tests\` → `tests/`
- 例: `Tfcenv\Tfc\Client` → `src/Tfc/Client.php`

**API**

- ベース URL は `https://app.terraform.io/api/v2`（`Client` のコンストラクタ引数のデフォルト値）
- ヘッダは常に `Authorization: Bearer $TFC_TOKEN` と `Content-Type: application/vnd.api+json`
- ページサイズは 100（TFC の最大）。`meta.pagination.next-page` が `null` になるまで辿る
- `sensitive: true` の変数は値を読み戻せない

**環境変数**

- `TFC_TOKEN`（必須）/ `TFC_ORG`（任意・organization のデフォルト値）/ `NO_COLOR`（任意・設定されていれば ANSI 無効）

**安全性**

- `TFC_TOKEN` の値をどこにも出力しない。エラーメッセージ・例外メッセージ・進捗表示に載せない
- `sensitive: true` の値は確認画面・進捗表示・エラーのすべてでマスクする

**終了コード**

- 成功 `0` / 失敗 `1`

**コミット**

- 各タスクの最後に1コミット。メッセージは英語、`git commit` の本文末尾に共著者行は付けない（このリポジトリの既存コミットはエージェント作業のみ共著者を付けている。人が実行する場合は不要）

---

## Spec との差分

計画を書く過程で判明した2点。実装前に spec 側も直すこと。

### 1. `pcntl` は不要（拡張リストが1つ減る）

spec は「raw モード中に SIGINT で落ちると端末が raw のまま残る」ので `pcntl_signal` が必要、と書いている。**これは誤り。** `stty raw` は `isig` を無効化するため、raw モード中の `Ctrl-C` は SIGINT にならず **0x03 バイトとして `fread()` に届く**。したがって raw モード中に SIGINT で落ちる経路は存在しない。

実際に危ないのは `stty -echo` によるパスワード入力側で、こちらは `isig` が生きているため `Ctrl-C` が SIGINT になり、echo が戻らないまま終了する。

**対策:** 非表示入力も raw モード（`stty raw -echo`）で行い、`0x03` を自前で処理する。これで `pcntl` 依存が消え、P1 が必要とする拡張は **`curl` と `mbstring` の2つ**になる。P3 の静的ビルドもその分軽くなる。

保険として `register_shutdown_function()` で端末モードを復元する。これは未捕捉例外や `exit()` では走るので、`SIGINT` に依存しない範囲を確実にカバーする。`SIGTERM` / `SIGKILL` で殺された場合は端末が raw のまま残るが、これは受け入れる（`reset` で戻る）。

### 2. spec のファイル一覧に無いファイルを追加する

spec の「モジュール構成」に対し、その設計意図（「`HttpTransport` と `Terminal` をインタフェースにする」「確認画面と適用処理は入力方法に依存しない共通コンポーネントに置く」）を満たすために必要なファイルを足している。

| 追加 | 理由 |
|---|---|
| `src/Terminal/SttyTerminal.php` | `Terminal` をインタフェースにするので実装が別ファイルになる |
| `src/Terminal/KeyMap.php` | バイト列 → キー名の純粋関数。実 STDIN なしでテストするため切り出す |
| `src/Terminal/CancelledException.php` | `Ctrl-C` を例外で上に伝える |
| `src/Tfc/HttpRequest.php` / `HttpResponse.php` | `HttpTransport` の入出力型 |
| `src/Tfc/Workspace.php` | id と name の値オブジェクト |
| `src/Cli/ChangeOp.php` / `Change.php` / `ChangeSet.php` | spec の「中間表現」を型にしたもの。P2 が再利用する |
| `src/Cli/Confirmation.php` / `Applier.php` / `ApplyResult.php` | 「入力方法に依存しない共通コンポーネント」の実体 |
| `src/Version.php` | `--version` の値をクラス定数で持つ（グローバル定数を避ける） |

---

## File Structure

```
src/
  main.php                        グローバル main()。Application を組んで exit() するだけ
  Version.php                     final class Version { const STRING }
  Cli/
    Application.php               argv 解析・env 読み取り・依存の組み立て・例外境界
    AddCommand.php                対話フローの手順書。ChangeSet を作る
    ChangeOp.php                  enum create | update
    Change.php                    ChangeOp + Variable
    ChangeSet.php                 organization + Workspace + Change[]
    Confirmation.php              ChangeSet を表示して Yes/No を取る（入力方法に非依存）
    Applier.php                   ChangeSet を1件ずつ適用して集計（入力方法に非依存）
    ApplyResult.php               succeeded / failed
  Terminal/
    Terminal.php                  interface。端末 I/O の境界
    SttyTerminal.php              stty + fread による実装
    KeyMap.php                    バイト列 → キー名の純粋変換
    CancelledException.php        Ctrl-C
    Style.php                     ANSI。NO_COLOR と非TTY で無効化
    Prompt.php                    text / hidden / confirm / select
    Picker.php                    絞り込み付きリスト選択
  Tfc/
    Category.php                  enum terraform | env
    Variable.php                  値オブジェクト + JSON:API ペイロード生成
    TfcException.php              extends \Exception。HTTP ステータスを code に持つ
    HttpRequest.php               method / url / headers / body
    HttpResponse.php              status / body
    HttpTransport.php             interface
    CurlTransport.php             curl による実装
    Client.php                    URL とヘッダの組み立て・JSON:API のエラー整形
    Workspace.php                 id / name
    WorkspaceRepository.php       一覧取得（ページング）
    VariableRepository.php        一覧取得 / create / update

tests/
  Support/FakeTransport.php       HttpTransport のフェイク。応答をキューで返し、要求を記録する
  Support/FakeTerminal.php        Terminal のフェイク。キー列と行入力をキューで流し、出力を記録する
  Tfc/CategoryTest.php
  Tfc/VariableTest.php
  Tfc/TfcExceptionTest.php
  Tfc/ClientTest.php
  Tfc/CurlTransportTest.php
  Tfc/WorkspaceRepositoryTest.php
  Tfc/VariableRepositoryTest.php
  Terminal/KeyMapTest.php
  Terminal/StyleTest.php
  Terminal/PromptTest.php
  Terminal/PickerTest.php
  Cli/ChangeSetTest.php
  Cli/ConfirmationTest.php
  Cli/ApplierTest.php
  Cli/AddCommandTest.php
  Cli/ApplicationTest.php

phpunit.xml                       PHPUnit 設定
```

`project.yml` の `sources` は `src` のみなので `tests/` は AOT に含まれない。

---

### Task 1: テスト基盤と値オブジェクト

PHPUnit を入れ、`composer test` / `make test` を通し、`Category` / `Variable` / `TfcException` を実装する。

**Files:**
- Create: `phpunit.xml`
- Create: `src/Tfc/Category.php`
- Create: `src/Tfc/Variable.php`
- Create: `src/Tfc/TfcException.php`
- Create: `tests/Tfc/CategoryTest.php`
- Create: `tests/Tfc/VariableTest.php`
- Create: `tests/Tfc/TfcExceptionTest.php`
- Modify: `composer.json`（`require-dev` に phpunit、`autoload-dev`、`scripts.test`）
- Modify: `Makefile`（`test` ターゲット）

**Interfaces:**
- Consumes: なし
- Produces:
  - `Tfcenv\Tfc\Category`: `enum Category: string { case Terraform = 'terraform'; case Env = 'env'; }`、`label(): string`
  - `Tfcenv\Tfc\Variable`: `__construct(public readonly string $key, public readonly string $value, public readonly Category $category, public readonly bool $sensitive, public readonly string $description, public readonly ?string $id = null)`、`static fromApi(array $resource): self`、`payload(): array`、`updateDocument(): array`、`withValue(string $value): self`、`withId(string $id): self`、`displayValue(): string`
  - `Tfcenv\Tfc\TfcException`: `static fromResponse(int $status, string $body): self`、`static fromTransport(string $detail): self`、`status(): int`

- [ ] **Step 1: PHPUnit を入れて composer.json を更新する**

```bash
nix develop --command composer require --dev --no-interaction "phpunit/phpunit:^13.3"
```

続けて `composer.json` を編集する。`autoload-dev` を追加し、`scripts` に `test` を足す。

```json
    "autoload": {
        "psr-4": {
            "Tfcenv\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tfcenv\\Tests\\": "tests/"
        }
    },
```

`scripts` に追記（既存の `tfcenv` / `phpx` / `compile` / `clean` / `distclean` / `doctor` は残す）:

```json
        "test": "@php vendor/bin/phpunit"
```

`scripts-descriptions` にも追記:

```json
        "test": "Run the PHPUnit suite through the PHP interpreter"
```

- [ ] **Step 2: phpunit.xml を作る**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true"
         failOnNotice="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="tfcenv">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

`.gitignore` に1行足す:

```
/.phpunit.cache/
```

- [ ] **Step 3: Makefile に test ターゲットを足す**

`.PHONY` の行に `test` を追加し、`run` ターゲットの下に足す。

```make
## test - run the PHPUnit suite through the interpreter
test: vendor/autoload.php
	@$(RUN) test
```

- [ ] **Step 4: 失敗するテストを書く**

`tests/Tfc/CategoryTest.php`:

```php
<?php

namespace Tfcenv\Tests\Tfc;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tfc\Category;

final class CategoryTest extends TestCase
{
    public function testBackedValuesMatchTheApi(): void
    {
        $this->assertSame('terraform', Category::Terraform->value);
        $this->assertSame('env', Category::Env->value);
    }

    public function testFromParsesApiValues(): void
    {
        $this->assertSame(Category::Terraform, Category::from('terraform'));
        $this->assertSame(Category::Env, Category::from('env'));
    }

    public function testLabelDescribesTheCategory(): void
    {
        $this->assertSame('Terraform variable', Category::Terraform->label());
        $this->assertSame('Environment variable', Category::Env->label());
    }
}
```

`tests/Tfc/TfcExceptionTest.php`:

```php
<?php

namespace Tfcenv\Tests\Tfc;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tfc\TfcException;

final class TfcExceptionTest extends TestCase
{
    public function testFromResponseUsesTheJsonApiDetail(): void
    {
        $body = '{"errors":[{"status":"422","title":"invalid attribute","detail":"Key has already been taken"}]}';

        $e = TfcException::fromResponse(422, $body);

        $this->assertSame(422, $e->status());
        $this->assertStringContainsString('Key has already been taken', $e->getMessage());
    }

    public function testFromResponseJoinsMultipleDetails(): void
    {
        $body = '{"errors":[{"detail":"first"},{"detail":"second"}]}';

        $e = TfcException::fromResponse(422, $body);

        $this->assertStringContainsString('first', $e->getMessage());
        $this->assertStringContainsString('second', $e->getMessage());
    }

    public function testFromResponseFallsBackToTheStatusWhenTheBodyIsNotJsonApi(): void
    {
        $e = TfcException::fromResponse(502, '<html>bad gateway</html>');

        $this->assertSame(502, $e->status());
        $this->assertStringContainsString('502', $e->getMessage());
        $this->assertStringNotContainsString('<html>', $e->getMessage());
    }

    public function testFromTransportHasNoHttpStatus(): void
    {
        $e = TfcException::fromTransport('Could not resolve host: app.terraform.io');

        $this->assertSame(0, $e->status());
        $this->assertStringContainsString('Could not resolve host', $e->getMessage());
    }
}
```

`tests/Tfc/VariableTest.php`:

```php
<?php

namespace Tfcenv\Tests\Tfc;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tfc\Category;
use Tfcenv\Tfc\Variable;

final class VariableTest extends TestCase
{
    public function testPayloadBuildsTheCreateDocument(): void
    {
        $v = new Variable('partner_client_id', 'abc123', Category::Terraform, true, 'OAuth クライアントID');

        $this->assertSame([
            'data' => [
                'type' => 'vars',
                'attributes' => [
                    'key' => 'partner_client_id',
                    'value' => 'abc123',
                    'category' => 'terraform',
                    'sensitive' => true,
                    'description' => 'OAuth クライアントID',
                ],
            ],
        ], $v->payload());
    }

    public function testUpdateDocumentCarriesTheIdAndEveryAttribute(): void
    {
        $v = new Variable('k', 'v2', Category::Env, false, 'desc', 'var-Ab3xY9');

        $this->assertSame([
            'data' => [
                'type' => 'vars',
                'id' => 'var-Ab3xY9',
                'attributes' => [
                    'key' => 'k',
                    'value' => 'v2',
                    'category' => 'env',
                    'sensitive' => false,
                    'description' => 'desc',
                ],
            ],
        ], $v->updateDocument());
    }

    public function testFromApiReadsAResourceObject(): void
    {
        $v = Variable::fromApi([
            'id' => 'var-Ab3xY9',
            'type' => 'vars',
            'attributes' => [
                'key' => 'google_tag_manager_container_id',
                'value' => 'GTM-XXXXXXX',
                'category' => 'terraform',
                'sensitive' => false,
                'description' => 'GTM コンテナ ID',
            ],
        ]);

        $this->assertSame('var-Ab3xY9', $v->id);
        $this->assertSame('google_tag_manager_container_id', $v->key);
        $this->assertSame('GTM-XXXXXXX', $v->value);
        $this->assertSame(Category::Terraform, $v->category);
        $this->assertFalse($v->sensitive);
        $this->assertSame('GTM コンテナ ID', $v->description);
    }

    public function testFromApiTreatsAMissingSensitiveValueAsEmpty(): void
    {
        $v = Variable::fromApi([
            'id' => 'var-1',
            'attributes' => [
                'key' => 'secret',
                'value' => null,
                'category' => 'terraform',
                'sensitive' => true,
                'description' => null,
            ],
        ]);

        $this->assertSame('', $v->value);
        $this->assertSame('', $v->description);
        $this->assertTrue($v->sensitive);
    }

    public function testDisplayValueMasksSensitiveValues(): void
    {
        $secret = new Variable('k', 'super-secret', Category::Terraform, true, '');
        $plain = new Variable('k', 'GTM-XXXXXXX', Category::Terraform, false, '');

        $this->assertSame('••••••••', $secret->displayValue());
        $this->assertStringNotContainsString('super-secret', $secret->displayValue());
        $this->assertSame('GTM-XXXXXXX', $plain->displayValue());
    }

    public function testWithValueAndWithIdReturnNewInstances(): void
    {
        $v = new Variable('k', 'v1', Category::Terraform, false, 'd');

        $withValue = $v->withValue('v2');
        $withId = $v->withId('var-9');

        $this->assertSame('v1', $v->value);
        $this->assertNull($v->id);
        $this->assertSame('v2', $withValue->value);
        $this->assertSame('var-9', $withId->id);
        $this->assertSame('k', $withValue->key);
    }
}
```

- [ ] **Step 5: テストが落ちることを確認する**

```bash
nix develop --command make test
```

Expected: FAIL。`Tfcenv\Tfc\Category` などが存在しないため `Error: Class "Tfcenv\Tfc\Category" not found`。

- [ ] **Step 6: Category を実装する**

`src/Tfc/Category.php`:

```php
<?php

namespace Tfcenv\Tfc;

enum Category: string
{
    case Terraform = 'terraform';
    case Env = 'env';

    public function label(): string
    {
        return match ($this) {
            Category::Terraform => 'Terraform variable',
            Category::Env => 'Environment variable',
        };
    }
}
```

- [ ] **Step 7: TfcException を実装する**

`src/Tfc/TfcException.php`:

```php
<?php

namespace Tfcenv\Tfc;

final class TfcException extends \Exception
{
    public static function fromResponse(int $status, string $body): self
    {
        $details = self::details($body);

        if ($details === '') {
            return new self(sprintf('Terraform Cloud returned HTTP %d', $status), $status);
        }

        return new self(sprintf('Terraform Cloud returned HTTP %d: %s', $status, $details), $status);
    }

    public static function fromTransport(string $detail): self
    {
        return new self(sprintf('Could not reach Terraform Cloud: %s', $detail), 0);
    }

    public function status(): int
    {
        return (int) $this->getCode();
    }

    /**
     * JSON:API のエラー配列から detail を取り出して連結する。
     * JSON:API でない本文（HTML のエラーページなど）は捨てる。
     */
    private static function details(string $body): string
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || !isset($decoded['errors']) || !is_array($decoded['errors'])) {
            return '';
        }

        $messages = [];

        foreach ($decoded['errors'] as $error) {
            if (!is_array($error)) {
                continue;
            }
            $text = $error['detail'] ?? $error['title'] ?? null;
            if (is_string($text) && $text !== '') {
                $messages[] = $text;
            }
        }

        return implode('; ', $messages);
    }
}
```

- [ ] **Step 8: Variable を実装する**

`src/Tfc/Variable.php`:

```php
<?php

namespace Tfcenv\Tfc;

final class Variable
{
    private const MASK = '••••••••';

    public function __construct(
        public readonly string $key,
        public readonly string $value,
        public readonly Category $category,
        public readonly bool $sensitive,
        public readonly string $description,
        public readonly ?string $id = null,
    ) {
    }

    /**
     * TFC の vars リソースオブジェクトから作る。
     * sensitive な変数は value が返らないので空文字列になる。
     */
    public static function fromApi(array $resource): self
    {
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];

        return new self(
            (string) ($attributes['key'] ?? ''),
            (string) ($attributes['value'] ?? ''),
            Category::from((string) ($attributes['category'] ?? 'terraform')),
            (bool) ($attributes['sensitive'] ?? false),
            (string) ($attributes['description'] ?? ''),
            isset($resource['id']) ? (string) $resource['id'] : null,
        );
    }

    public function payload(): array
    {
        return [
            'data' => [
                'type' => 'vars',
                'attributes' => $this->attributes(),
            ],
        ];
    }

    public function updateDocument(): array
    {
        return [
            'data' => [
                'type' => 'vars',
                'id' => (string) $this->id,
                'attributes' => $this->attributes(),
            ],
        ];
    }

    public function withValue(string $value): self
    {
        return new self($this->key, $value, $this->category, $this->sensitive, $this->description, $this->id);
    }

    public function withId(string $id): self
    {
        return new self($this->key, $this->value, $this->category, $this->sensitive, $this->description, $id);
    }

    public function displayValue(): string
    {
        return $this->sensitive ? self::MASK : $this->value;
    }

    private function attributes(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'category' => $this->category->value,
            'sensitive' => $this->sensitive,
            'description' => $this->description,
        ];
    }
}
```

- [ ] **Step 9: テストが通ることを確認する**

```bash
nix develop --command make test
```

Expected: PASS。`OK (13 tests, ...)` のように全件成功。

- [ ] **Step 10: AOT ビルドが壊れていないことを確認する**

```bash
nix develop --command make build && ./tfcenv
```

Expected: `Build successful: tfcenv` と、既存のスモークテスト出力。新しいクラスも `src/` にあるので一緒にコンパイルされる。ここでコンパイルが落ちたら Global Constraints の TypePHP 制約に違反している。

- [ ] **Step 11: コミット**

```bash
git add -A
git commit -m "Add the PHPUnit harness and the TFC value objects

Category, Variable and TfcException are the types every later layer passes
around. Variable carries the JSON:API document shapes so no other class has
to know them, and masks its own value when the variable is sensitive.

Method names avoid the to+Type pattern: TypePHP resolves toArray, toString
and friends as reserved keyword methods ahead of ordinary ones."
```

---

### Task 2: HTTP 境界と Client

`HttpTransport` インタフェースと、それを使う `Client`。テスト用フェイクもここで作る。

**Files:**
- Create: `src/Tfc/HttpRequest.php`
- Create: `src/Tfc/HttpResponse.php`
- Create: `src/Tfc/HttpTransport.php`
- Create: `src/Tfc/Client.php`
- Create: `tests/Support/FakeTransport.php`
- Create: `tests/Tfc/ClientTest.php`

**Interfaces:**
- Consumes: `Tfcenv\Tfc\TfcException`（Task 1）
- Produces:
  - `Tfcenv\Tfc\HttpRequest`: `__construct(public readonly string $method, public readonly string $url, public readonly array $headers, public readonly ?string $body = null)`
  - `Tfcenv\Tfc\HttpResponse`: `__construct(public readonly int $status, public readonly string $body)`
  - `Tfcenv\Tfc\HttpTransport`: `send(HttpRequest $request): HttpResponse`（transport 障害時は `TfcException` を投げる）
  - `Tfcenv\Tfc\Client`: `__construct(private readonly HttpTransport $transport, private readonly string $token, private readonly string $baseUrl = 'https://app.terraform.io/api/v2')`、`get(string $path): array`、`post(string $path, array $document): array`、`patch(string $path, array $document): array`
  - `Tfcenv\Tests\Support\FakeTransport`: `queue(int $status, string $body): void`、`failWith(string $detail): void`、`requests(): array`（`HttpRequest[]`）、`lastRequest(): HttpRequest`。コンストラクタは持たない

- [ ] **Step 1: 失敗するテストを書く**

`tests/Support/FakeTransport.php`:

```php
<?php

namespace Tfcenv\Tests\Support;

use Tfcenv\Tfc\HttpRequest;
use Tfcenv\Tfc\HttpResponse;
use Tfcenv\Tfc\HttpTransport;
use Tfcenv\Tfc\TfcException;

/**
 * 応答をキューで返し、受け取った要求を記録する HttpTransport。
 * キューが空のまま呼ばれたらテストの組み立てミスなので即座に失敗させる。
 */
final class FakeTransport implements HttpTransport
{
    /** @var HttpResponse[] */
    private array $responses = [];

    /** @var HttpRequest[] */
    private array $requests = [];

    private ?string $transportError = null;

    public function queue(int $status, string $body): void
    {
        $this->responses[] = new HttpResponse($status, $body);
    }

    public function failWith(string $detail): void
    {
        $this->transportError = $detail;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        if ($this->transportError !== null) {
            throw TfcException::fromTransport($this->transportError);
        }

        if ($this->responses === []) {
            throw new \RuntimeException(sprintf(
                'FakeTransport got an unexpected %s %s with no queued response',
                $request->method,
                $request->url,
            ));
        }

        return array_shift($this->responses);
    }

    /** @return HttpRequest[] */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): HttpRequest
    {
        if ($this->requests === []) {
            throw new \RuntimeException('FakeTransport received no requests');
        }

        return $this->requests[count($this->requests) - 1];
    }
}
```

`tests/Tfc/ClientTest.php`:

```php
<?php

namespace Tfcenv\Tests\Tfc;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tests\Support\FakeTransport;
use Tfcenv\Tfc\Client;
use Tfcenv\Tfc\TfcException;

final class ClientTest extends TestCase
{
    public function testGetBuildsTheUrlAndHeaders(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, '{"data":[]}');
        $client = new Client($transport, 'tok-secret', 'https://app.terraform.io/api/v2');

        $client->get('/organizations/acme/workspaces');

        $request = $transport->lastRequest();
        $this->assertSame('GET', $request->method);
        $this->assertSame('https://app.terraform.io/api/v2/organizations/acme/workspaces', $request->url);
        $this->assertContains('Authorization: Bearer tok-secret', $request->headers);
        $this->assertContains('Content-Type: application/vnd.api+json', $request->headers);
        $this->assertNull($request->body);
    }

    public function testGetDecodesTheDocument(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, '{"data":[{"id":"ws-1"}]}');
        $client = new Client($transport, 'tok');

        $document = $client->get('/x');

        $this->assertSame([['id' => 'ws-1']], $document['data']);
    }

    public function testPostSendsTheEncodedDocument(): void
    {
        $transport = new FakeTransport();
        $transport->queue(201, '{"data":{"id":"var-1"}}');
        $client = new Client($transport, 'tok');

        $client->post('/workspaces/ws-1/vars', ['data' => ['type' => 'vars']]);

        $request = $transport->lastRequest();
        $this->assertSame('POST', $request->method);
        $this->assertSame('{"data":{"type":"vars"}}', $request->body);
    }

    public function testPatchUsesThePatchMethod(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, '{"data":{"id":"var-1"}}');
        $client = new Client($transport, 'tok');

        $client->patch('/workspaces/ws-1/vars/var-1', ['data' => []]);

        $this->assertSame('PATCH', $transport->lastRequest()->method);
    }

    public function testJapaneseTextSurvivesEncodingUnescaped(): void
    {
        $transport = new FakeTransport();
        $transport->queue(201, '{}');
        $client = new Client($transport, 'tok');

        $client->post('/x', ['description' => '連携先認証基盤']);

        $this->assertSame('{"description":"連携先認証基盤"}', $transport->lastRequest()->body);
    }

    public function testEmptyBodyDecodesToAnEmptyDocument(): void
    {
        $transport = new FakeTransport();
        $transport->queue(204, '');
        $client = new Client($transport, 'tok');

        $this->assertSame([], $client->get('/ping'));
    }

    public function testNonSuccessStatusBecomesATfcException(): void
    {
        $transport = new FakeTransport();
        $transport->queue(422, '{"errors":[{"detail":"Key has already been taken"}]}');
        $client = new Client($transport, 'tok');

        try {
            $client->post('/workspaces/ws-1/vars', ['data' => []]);
            $this->fail('expected a TfcException');
        } catch (TfcException $e) {
            $this->assertSame(422, $e->status());
            $this->assertStringContainsString('Key has already been taken', $e->getMessage());
        }
    }

    public function testTheTokenNeverAppearsInAnErrorMessage(): void
    {
        $transport = new FakeTransport();
        $transport->queue(401, '{"errors":[{"detail":"unauthorized"}]}');
        $client = new Client($transport, 'tok-do-not-leak');

        try {
            $client->get('/x');
            $this->fail('expected a TfcException');
        } catch (TfcException $e) {
            $this->assertStringNotContainsString('tok-do-not-leak', $e->getMessage());
        }
    }

    public function testMalformedJsonBecomesATfcException(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, 'not json at all');
        $client = new Client($transport, 'tok');

        $this->expectException(TfcException::class);
        $client->get('/x');
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
nix develop --command make test
```

Expected: FAIL。`Class "Tfcenv\Tfc\Client" not found`。

- [ ] **Step 3: HttpRequest / HttpResponse / HttpTransport を実装する**

`src/Tfc/HttpRequest.php`:

```php
<?php

namespace Tfcenv\Tfc;

final class HttpRequest
{
    /**
     * @param string[] $headers "Name: value" 形式。curl の CURLOPT_HTTPHEADER にそのまま渡せる形
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body = null,
    ) {
    }
}
```

`src/Tfc/HttpResponse.php`:

```php
<?php

namespace Tfcenv\Tfc;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }
}
```

`src/Tfc/HttpTransport.php`:

```php
<?php

namespace Tfcenv\Tfc;

interface HttpTransport
{
    /**
     * HTTP ステータスは呼び手が解釈する。ここが投げるのは
     * 接続できなかった場合だけ。
     *
     * @throws TfcException 名前解決失敗・接続拒否・タイムアウトなど
     */
    public function send(HttpRequest $request): HttpResponse;
}
```

- [ ] **Step 4: Client を実装する**

`src/Tfc/Client.php`:

```php
<?php

namespace Tfcenv\Tfc;

final class Client
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $token,
        private readonly string $baseUrl = 'https://app.terraform.io/api/v2',
    ) {
    }

    public function get(string $path): array
    {
        return $this->send('GET', $path, null);
    }

    public function post(string $path, array $document): array
    {
        return $this->send('POST', $path, $this->encode($document));
    }

    public function patch(string $path, array $document): array
    {
        return $this->send('PATCH', $path, $this->encode($document));
    }

    private function send(string $method, string $path, ?string $body): array
    {
        $request = new HttpRequest($method, $this->baseUrl . $path, $this->headers(), $body);
        $response = $this->transport->send($request);

        if ($response->status < 200 || $response->status >= 300) {
            throw TfcException::fromResponse($response->status, $response->body);
        }

        return $this->decode($response->body);
    }

    /** @return string[] */
    private function headers(): array
    {
        return [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/vnd.api+json',
        ];
    }

    private function encode(array $document): string
    {
        // 説明文に日本語が入るので、エスケープせずそのまま送る
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new TfcException('Could not encode the request document as JSON', 0);
        }

        return $json;
    }

    private function decode(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new TfcException('Terraform Cloud returned a response that is not a JSON document', 0);
        }

        return $decoded;
    }
}
```

- [ ] **Step 5: テストが通ることを確認する**

```bash
nix develop --command make test
```

Expected: PASS。

- [ ] **Step 6: コミット**

```bash
git add -A
git commit -m "Add the HTTP boundary and the JSON:API client

HttpTransport is the seam every later test drives: FakeTransport queues
responses and records requests, so nothing below this line needs the network.

Client owns the base URL, the bearer header and the vnd.api+json content type,
turns any non-2xx into a TfcException, and encodes with JSON_UNESCAPED_UNICODE
because variable descriptions are written in Japanese."
```

---

### Task 3: CurlTransport

`HttpTransport` の実装。ネットワークを必要としない形でテストする。

**Files:**
- Create: `src/Tfc/CurlTransport.php`
- Create: `tests/Tfc/CurlTransportTest.php`

**Interfaces:**
- Consumes: `HttpRequest` / `HttpResponse` / `HttpTransport` / `TfcException`（Task 2）
- Produces: `Tfcenv\Tfc\CurlTransport`: `__construct(private readonly int $timeoutSeconds = 30)`、`send(HttpRequest $request): HttpResponse`

- [ ] **Step 1: 失敗するテストを書く**

接続拒否は DNS もネットワークも要らず決定的に起きるので、これを transport エラーの検証に使う。

`tests/Tfc/CurlTransportTest.php`:

```php
<?php

namespace Tfcenv\Tests\Tfc;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tfc\CurlTransport;
use Tfcenv\Tfc\HttpRequest;
use Tfcenv\Tfc\TfcException;

final class CurlTransportTest extends TestCase
{
    public function testCurlIsAvailable(): void
    {
        // AOT バイナリでは PHP_INI_SCAN_DIR が無いと curl 拡張が入らない。
        // ここが落ちたら実行環境の設定漏れ。
        $this->assertTrue(function_exists('curl_init'), 'ext-curl is required');
    }

    public function testAConnectionFailureBecomesATfcException(): void
    {
        $transport = new CurlTransport(2);
        // ポート 1 は待ち受けていないので必ず接続拒否になる。
        $request = new HttpRequest('GET', 'http://127.0.0.1:1/', []);

        try {
            $transport->send($request);
            $this->fail('expected a TfcException');
        } catch (TfcException $e) {
            $this->assertSame(0, $e->status());
            $this->assertStringContainsString('Could not reach Terraform Cloud', $e->getMessage());
        }
    }

    #[\PHPUnit\Framework\Attributes\Group('network')]
    public function testItReachesTheTerraformCloudPingEndpoint(): void
    {
        $transport = new CurlTransport(10);
        $request = new HttpRequest('GET', 'https://app.terraform.io/api/v2/ping', []);

        $response = $transport->send($request);

        $this->assertSame(204, $response->status);
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
nix develop --command make test
```

Expected: FAIL。`Class "Tfcenv\Tfc\CurlTransport" not found`。

- [ ] **Step 3: CurlTransport を実装する**

`src/Tfc/CurlTransport.php`:

```php
<?php

namespace Tfcenv\Tfc;

final class CurlTransport implements HttpTransport
{
    public function __construct(
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $handle = curl_init($request->url);

        if ($handle === false) {
            throw TfcException::fromTransport('could not initialise curl');
        }

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $request->method);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $request->headers);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, $this->timeoutSeconds);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);

        if ($request->body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        // curl_close() は PHP 8.5 で deprecated。ハンドルは GC に任せる。

        if ($body === false) {
            throw TfcException::fromTransport($error === '' ? 'unknown curl failure' : $error);
        }

        return new HttpResponse($status, (string) $body);
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
nix develop --command make test
```

Expected: PASS（`network` グループのテストも通る。オフラインで作業しているなら
`nix develop --command composer test -- --exclude-group network` で除外できる）。

- [ ] **Step 5: AOT バイナリで curl が動くことを確認する**

```bash
nix develop --command make build && ./tfcenv
```

Expected: `Build successful: tfcenv` とスモークテスト出力。ここではまだ curl を呼ばないが、
`CurlTransport` がコンパイルを通ることを確認する。

- [ ] **Step 6: コミット**

```bash
git add -A
git commit -m "Add the curl-backed HTTP transport

Connection failures are normalised into TfcException so the layers above only
ever handle one error type. The offline test points at 127.0.0.1:1, which is
refused deterministically without touching the network; the live ping test is
tagged 'network' so it can be excluded.

curl_close() is deprecated in PHP 8.5, so the handle is left to the collector."
```

---

### Task 4: Workspace と WorkspaceRepository

ページングを辿ってワークスペース一覧を取る。

**Files:**
- Create: `src/Tfc/Workspace.php`
- Create: `src/Tfc/WorkspaceRepository.php`
- Create: `tests/Tfc/WorkspaceRepositoryTest.php`

**Interfaces:**
- Consumes: `Client`（Task 2）、`FakeTransport`（Task 2）
- Produces:
  - `Tfcenv\Tfc\Workspace`: `__construct(public readonly string $id, public readonly string $name)`
  - `Tfcenv\Tfc\WorkspaceRepository`: `__construct(private readonly Client $client)`、`listAll(string $organization): array`（`Workspace[]`、name の昇順）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Tfc/WorkspaceRepositoryTest.php`:

```php
<?php

namespace Tfcenv\Tests\Tfc;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tests\Support\FakeTransport;
use Tfcenv\Tfc\Client;
use Tfcenv\Tfc\WorkspaceRepository;

final class WorkspaceRepositoryTest extends TestCase
{
    public function testItAsksForTheLargestPageSize(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, $this->page([], null));
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $repository->listAll('acme');

        $url = $transport->lastRequest()->url;
        $this->assertStringContainsString('/organizations/acme/workspaces', $url);
        $this->assertStringContainsString('page%5Bsize%5D=100', $url);
        $this->assertStringContainsString('page%5Bnumber%5D=1', $url);
    }

    public function testItFollowsPaginationUntilNextPageIsNull(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, $this->page([['ws-1', 'alpha-core-prod']], 2));
        $transport->queue(200, $this->page([['ws-2', 'alpha-core-stg']], 3));
        $transport->queue(200, $this->page([['ws-3', 'beta-core-prod']], null));
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $workspaces = $repository->listAll('acme');

        $this->assertCount(3, $workspaces);
        $this->assertCount(3, $transport->requests());
        $this->assertStringContainsString('page%5Bnumber%5D=3', $transport->lastRequest()->url);
    }

    public function testItReturnsIdAndNameSortedByName(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, $this->page([
            ['ws-2', 'alpha-core-stg'],
            ['ws-1', 'alpha-core-prod'],
        ], null));
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $workspaces = $repository->listAll('acme');

        $this->assertSame('alpha-core-prod', $workspaces[0]->name);
        $this->assertSame('ws-1', $workspaces[0]->id);
        $this->assertSame('alpha-core-stg', $workspaces[1]->name);
    }

    public function testAnOrganizationWithNoWorkspacesReturnsAnEmptyList(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, $this->page([], null));
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $this->assertSame([], $repository->listAll('acme'));
    }

    /**
     * @param array<array{0:string,1:string}> $workspaces
     */
    private function page(array $workspaces, ?int $nextPage): string
    {
        $data = [];

        foreach ($workspaces as $workspace) {
            $data[] = [
                'id' => $workspace[0],
                'type' => 'workspaces',
                'attributes' => ['name' => $workspace[1]],
            ];
        }

        return json_encode([
            'data' => $data,
            'meta' => ['pagination' => ['next-page' => $nextPage]],
        ], JSON_UNESCAPED_UNICODE);
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
nix develop --command make test
```

Expected: FAIL。`Class "Tfcenv\Tfc\WorkspaceRepository" not found`。

- [ ] **Step 3: Workspace を実装する**

`src/Tfc/Workspace.php`:

```php
<?php

namespace Tfcenv\Tfc;

final class Workspace
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
    }
}
```

- [ ] **Step 4: WorkspaceRepository を実装する**

`src/Tfc/WorkspaceRepository.php`:

```php
<?php

namespace Tfcenv\Tfc;

final class WorkspaceRepository
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly Client $client,
    ) {
    }

    /**
     * organization の全ワークスペースを name の昇順で返す。
     * ページングは meta.pagination.next-page が null になるまで辿る。
     *
     * @return Workspace[]
     */
    public function listAll(string $organization): array
    {
        $workspaces = [];
        $page = 1;

        while (true) {
            $path = sprintf(
                '/organizations/%s/workspaces?%s',
                rawurlencode($organization),
                http_build_query(['page' => ['size' => self::PAGE_SIZE, 'number' => $page]]),
            );

            $document = $this->client->get($path);
            $resources = is_array($document['data'] ?? null) ? $document['data'] : [];

            foreach ($resources as $resource) {
                if (!is_array($resource)) {
                    continue;
                }
                $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
                $workspaces[] = new Workspace(
                    (string) ($resource['id'] ?? ''),
                    (string) ($attributes['name'] ?? ''),
                );
            }

            $next = $document['meta']['pagination']['next-page'] ?? null;

            if ($next === null) {
                break;
            }

            $page = (int) $next;
        }

        usort($workspaces, static fn(Workspace $a, Workspace $b): int => strcmp($a->name, $b->name));

        return $workspaces;
    }
}
```

- [ ] **Step 5: テストが通ることを確認する**

```bash
nix develop --command make test
```

Expected: PASS。

- [ ] **Step 6: コミット**

```bash
git add -A
git commit -m "List an organization's workspaces, following pagination

The picker needs every workspace, not the first page of twenty, so the
repository asks for TFC's maximum page size and walks next-page until it comes
back null. Results are sorted by name because the picker shows them as typed."
```

---

### Task 5: VariableRepository

既存変数の取得と、作成・更新。

**Files:**
- Create: `src/Tfc/VariableRepository.php`
- Create: `tests/Tfc/VariableRepositoryTest.php`

**Interfaces:**
- Consumes: `Client`（Task 2）、`Variable` / `Category`（Task 1）
- Produces: `Tfcenv\Tfc\VariableRepository`: `__construct(private readonly Client $client)`、`listFor(string $workspaceId): array`（`array<string, Variable>`、キーは変数の `key`）、`create(string $workspaceId, Variable $variable): Variable`、`update(string $workspaceId, Variable $variable): Variable`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Tfc/VariableRepositoryTest.php`:

```php
<?php

namespace Tfcenv\Tests\Tfc;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tests\Support\FakeTransport;
use Tfcenv\Tfc\Category;
use Tfcenv\Tfc\Client;
use Tfcenv\Tfc\TfcException;
use Tfcenv\Tfc\Variable;
use Tfcenv\Tfc\VariableRepository;

final class VariableRepositoryTest extends TestCase
{
    public function testListForKeysTheResultByVariableKey(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, json_encode([
            'data' => [
                [
                    'id' => 'var-1',
                    'attributes' => [
                        'key' => 'partner_client_id',
                        'value' => null,
                        'category' => 'terraform',
                        'sensitive' => true,
                        'description' => 'OAuth クライアントID',
                    ],
                ],
                [
                    'id' => 'var-2',
                    'attributes' => [
                        'key' => 'gtm_container_id',
                        'value' => 'GTM-XXXXXXX',
                        'category' => 'terraform',
                        'sensitive' => false,
                        'description' => '',
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));
        $repository = new VariableRepository(new Client($transport, 'tok'));

        $existing = $repository->listFor('ws-1');

        $this->assertSame('/workspaces/ws-1/vars', str_replace('https://app.terraform.io/api/v2', '', $transport->lastRequest()->url));
        $this->assertArrayHasKey('partner_client_id', $existing);
        $this->assertArrayHasKey('gtm_container_id', $existing);
        $this->assertSame('var-1', $existing['partner_client_id']->id);
        $this->assertTrue($existing['partner_client_id']->sensitive);
        $this->assertSame('', $existing['partner_client_id']->value);
        $this->assertSame('GTM-XXXXXXX', $existing['gtm_container_id']->value);
    }

    public function testListForOnAWorkspaceWithNoVariablesReturnsAnEmptyMap(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, '{"data":[]}');
        $repository = new VariableRepository(new Client($transport, 'tok'));

        $this->assertSame([], $repository->listFor('ws-1'));
    }

    public function testCreatePostsTheVariableAndReturnsItWithTheAssignedId(): void
    {
        $transport = new FakeTransport();
        $transport->queue(201, json_encode([
            'data' => [
                'id' => 'var-new',
                'attributes' => [
                    'key' => 'partner_client_id',
                    'value' => null,
                    'category' => 'terraform',
                    'sensitive' => true,
                    'description' => 'OAuth クライアントID',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));
        $repository = new VariableRepository(new Client($transport, 'tok'));
        $variable = new Variable('partner_client_id', 'abc', Category::Terraform, true, 'OAuth クライアントID');

        $created = $repository->create('ws-1', $variable);

        $request = $transport->lastRequest();
        $this->assertSame('POST', $request->method);
        $this->assertStringEndsWith('/workspaces/ws-1/vars', $request->url);
        $this->assertStringContainsString('"key":"partner_client_id"', (string) $request->body);
        $this->assertStringContainsString('"sensitive":true', (string) $request->body);
        $this->assertSame('var-new', $created->id);
    }

    public function testUpdatePatchesTheVariableById(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, json_encode([
            'data' => [
                'id' => 'var-1',
                'attributes' => [
                    'key' => 'k',
                    'value' => null,
                    'category' => 'terraform',
                    'sensitive' => true,
                    'description' => '',
                ],
            ],
        ]));
        $repository = new VariableRepository(new Client($transport, 'tok'));
        $variable = new Variable('k', 'new-value', Category::Terraform, true, '', 'var-1');

        $repository->update('ws-1', $variable);

        $request = $transport->lastRequest();
        $this->assertSame('PATCH', $request->method);
        $this->assertStringEndsWith('/workspaces/ws-1/vars/var-1', $request->url);
        $this->assertStringContainsString('"id":"var-1"', (string) $request->body);
        $this->assertStringContainsString('"value":"new-value"', (string) $request->body);
    }

    public function testUpdateRefusesAVariableWithoutAnId(): void
    {
        $transport = new FakeTransport();
        $repository = new VariableRepository(new Client($transport, 'tok'));
        $variable = new Variable('k', 'v', Category::Terraform, false, '');

        $this->expectException(TfcException::class);
        $this->expectExceptionMessage('without an id');

        $repository->update('ws-1', $variable);
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
nix develop --command make test
```

Expected: FAIL。`Class "Tfcenv\Tfc\VariableRepository" not found`。

- [ ] **Step 3: VariableRepository を実装する**

`src/Tfc/VariableRepository.php`:

```php
<?php

namespace Tfcenv\Tfc;

final class VariableRepository
{
    public function __construct(
        private readonly Client $client,
    ) {
    }

    /**
     * ワークスペースの既存変数を key で引ける形で返す。
     * sensitive な変数は value が返らないので空文字列になる。
     *
     * @return array<string, Variable>
     */
    public function listFor(string $workspaceId): array
    {
        $document = $this->client->get(sprintf('/workspaces/%s/vars', rawurlencode($workspaceId)));
        $resources = is_array($document['data'] ?? null) ? $document['data'] : [];

        $variables = [];

        foreach ($resources as $resource) {
            if (!is_array($resource)) {
                continue;
            }
            $variable = Variable::fromApi($resource);
            if ($variable->key !== '') {
                $variables[$variable->key] = $variable;
            }
        }

        return $variables;
    }

    public function create(string $workspaceId, Variable $variable): Variable
    {
        $document = $this->client->post(
            sprintf('/workspaces/%s/vars', rawurlencode($workspaceId)),
            $variable->payload(),
        );

        return $this->fromDocument($document, $variable);
    }

    public function update(string $workspaceId, Variable $variable): Variable
    {
        if ($variable->id === null || $variable->id === '') {
            throw new TfcException(
                sprintf('Cannot update the variable "%s" without an id', $variable->key),
                0,
            );
        }

        $document = $this->client->patch(
            sprintf('/workspaces/%s/vars/%s', rawurlencode($workspaceId), rawurlencode($variable->id)),
            $variable->updateDocument(),
        );

        return $this->fromDocument($document, $variable);
    }

    /**
     * 応答の resource object を読む。空応答なら送った側の値をそのまま返す。
     */
    private function fromDocument(array $document, Variable $fallback): Variable
    {
        $resource = $document['data'] ?? null;

        if (!is_array($resource)) {
            return $fallback;
        }

        return Variable::fromApi($resource);
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
nix develop --command make test
```

Expected: PASS。

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "Read, create and update workspace variables

listFor keys the result by variable key because that is what the collision
check needs on every key the user types. Update refuses a variable with no id
rather than sending a PATCH to a path ending in a slash."
```

---

### Task 6: Terminal 抽象・KeyMap・Style

端末 I/O の境界と、バイト列からキー名への変換、ANSI の有効・無効判定。

**Files:**
- Create: `src/Terminal/Terminal.php`
- Create: `src/Terminal/KeyMap.php`
- Create: `src/Terminal/CancelledException.php`
- Create: `src/Terminal/Style.php`
- Create: `tests/Support/FakeTerminal.php`
- Create: `tests/Terminal/KeyMapTest.php`
- Create: `tests/Terminal/StyleTest.php`

**Interfaces:**
- Consumes: なし
- Produces:
  - `Tfcenv\Terminal\Terminal`（interface）: `isTty(): bool`、`width(): int`、`write(string $text): void`、`writeError(string $text): void`、`readLine(): string`、`readKey(): string`、`enterRawMode(): void`、`restoreMode(): void`
  - `Tfcenv\Terminal\KeyMap`: `const UP = 'up'` / `DOWN = 'down'` / `ENTER = 'enter'` / `BACKSPACE = 'backspace'` / `CANCEL = 'cancel'` / `EOF = 'eof'`、`static fromBytes(string $bytes): string`、`static isPrintable(string $key): bool`
  - `Tfcenv\Terminal\CancelledException`: `extends \Exception`
  - `Tfcenv\Terminal\Style`: `__construct(private readonly bool $enabled)`、`static detect(Terminal $terminal, ?string $noColor): self`、`accent(string $s): string`、`ok(string $s): string`、`bad(string $s): string`、`warn(string $s): string`、`dim(string $s): string`、`bold(string $s): string`
  - `Tfcenv\Tests\Support\FakeTerminal`: `__construct(bool $tty = true)`、`queueLine(string $line): void`、`queueKeys(string ...$keys): void`、`queueTyping(string $text): void`（1文字ずつキー列として流す）、`output(): string`、`errorOutput(): string`、`rawModeEntered(): int`、`rawModeRestored(): int`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Terminal/KeyMapTest.php`:

```php
<?php

namespace Tfcenv\Tests\Terminal;

use PHPUnit\Framework\TestCase;
use Tfcenv\Terminal\KeyMap;

final class KeyMapTest extends TestCase
{
    public function testArrowKeysArriveAsThreeByteEscapeSequences(): void
    {
        $this->assertSame(KeyMap::UP, KeyMap::fromBytes("\e[A"));
        $this->assertSame(KeyMap::DOWN, KeyMap::fromBytes("\e[B"));
    }

    public function testEmacsStyleMovementKeys(): void
    {
        $this->assertSame(KeyMap::UP, KeyMap::fromBytes("\x10"));
        $this->assertSame(KeyMap::DOWN, KeyMap::fromBytes("\x0e"));
    }

    public function testEnterArrivesAsCarriageReturnInRawMode(): void
    {
        $this->assertSame(KeyMap::ENTER, KeyMap::fromBytes("\r"));
        $this->assertSame(KeyMap::ENTER, KeyMap::fromBytes("\n"));
    }

    public function testBackspaceArrivesAsDeleteOrBackspace(): void
    {
        $this->assertSame(KeyMap::BACKSPACE, KeyMap::fromBytes("\x7f"));
        $this->assertSame(KeyMap::BACKSPACE, KeyMap::fromBytes("\x08"));
    }

    public function testCtrlCIsAByteInRawModeNotASignal(): void
    {
        // stty raw は isig を落とすので、Ctrl-C は SIGINT にならず 0x03 として届く。
        $this->assertSame(KeyMap::CANCEL, KeyMap::fromBytes("\x03"));
    }

    public function testAnEmptyReadIsEof(): void
    {
        $this->assertSame(KeyMap::EOF, KeyMap::fromBytes(''));
    }

    public function testPrintableCharactersPassThrough(): void
    {
        $this->assertSame('b', KeyMap::fromBytes('b'));
        $this->assertSame('_', KeyMap::fromBytes('_'));
        $this->assertSame('-', KeyMap::fromBytes('-'));
    }

    public function testUnknownEscapeSequencesAreIgnorable(): void
    {
        // 左右矢印や Home などは使わないので、印字可能でもない中立な値になる。
        $key = KeyMap::fromBytes("\e[C");
        $this->assertNotSame(KeyMap::UP, $key);
        $this->assertNotSame(KeyMap::DOWN, $key);
        $this->assertFalse(KeyMap::isPrintable($key));
    }

    public function testIsPrintableSeparatesTextFromControlKeys(): void
    {
        $this->assertTrue(KeyMap::isPrintable('a'));
        $this->assertTrue(KeyMap::isPrintable('9'));
        $this->assertFalse(KeyMap::isPrintable(KeyMap::UP));
        $this->assertFalse(KeyMap::isPrintable(KeyMap::ENTER));
        $this->assertFalse(KeyMap::isPrintable(KeyMap::CANCEL));
        $this->assertFalse(KeyMap::isPrintable(KeyMap::EOF));
    }
}
```

`tests/Support/FakeTerminal.php`:

```php
<?php

namespace Tfcenv\Tests\Support;

use Tfcenv\Terminal\KeyMap;
use Tfcenv\Terminal\Terminal;

/**
 * 入力をキューで流し、出力を文字列に溜める Terminal。
 * これと FakeTransport の2つで対話フロー全体を駆動できる。
 */
final class FakeTerminal implements Terminal
{
    /** @var string[] */
    private array $lines = [];

    /** @var string[] */
    private array $keys = [];

    private string $output = '';
    private string $errorOutput = '';
    private int $entered = 0;
    private int $restored = 0;

    public function __construct(
        private readonly bool $tty = true,
    ) {
    }

    public function queueLine(string $line): void
    {
        $this->lines[] = $line;
    }

    public function queueKeys(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->keys[] = $key;
        }
    }

    /** 1文字ずつのキー列として文字列を流し込む */
    public function queueTyping(string $text): void
    {
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $this->keys[] = $text[$i];
        }
    }

    public function isTty(): bool
    {
        return $this->tty;
    }

    public function width(): int
    {
        return 80;
    }

    public function write(string $text): void
    {
        $this->output .= $text;
    }

    public function writeError(string $text): void
    {
        $this->errorOutput .= $text;
    }

    public function readLine(): string
    {
        if ($this->lines === []) {
            return '';
        }

        return array_shift($this->lines);
    }

    public function readKey(): string
    {
        if ($this->keys === []) {
            return KeyMap::EOF;
        }

        return array_shift($this->keys);
    }

    public function enterRawMode(): void
    {
        $this->entered++;
    }

    public function restoreMode(): void
    {
        $this->restored++;
    }

    public function output(): string
    {
        return $this->output;
    }

    public function errorOutput(): string
    {
        return $this->errorOutput;
    }

    public function rawModeEntered(): int
    {
        return $this->entered;
    }

    public function rawModeRestored(): int
    {
        return $this->restored;
    }
}
```

`tests/Terminal/StyleTest.php`:

```php
<?php

namespace Tfcenv\Tests\Terminal;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tests\Support\FakeTerminal;
use Tfcenv\Terminal\Style;

final class StyleTest extends TestCase
{
    public function testItEmitsAnsiWhenEnabled(): void
    {
        $style = new Style(true);

        $this->assertStringContainsString("\e[", $style->accent('x'));
        $this->assertStringContainsString('x', $style->accent('x'));
        $this->assertStringEndsWith("\e[0m", $style->accent('x'));
    }

    public function testItPassesTextThroughWhenDisabled(): void
    {
        $style = new Style(false);

        $this->assertSame('x', $style->accent('x'));
        $this->assertSame('x', $style->ok('x'));
        $this->assertSame('x', $style->bad('x'));
        $this->assertSame('x', $style->warn('x'));
        $this->assertSame('x', $style->dim('x'));
        $this->assertSame('x', $style->bold('x'));
    }

    public function testDetectEnablesColourOnATty(): void
    {
        $style = Style::detect(new FakeTerminal(true), null);

        $this->assertStringContainsString("\e[", $style->accent('x'));
    }

    public function testDetectDisablesColourWhenNotATty(): void
    {
        $style = Style::detect(new FakeTerminal(false), null);

        $this->assertSame('x', $style->accent('x'));
    }

    public function testDetectRespectsNoColor(): void
    {
        // NO_COLOR は「設定されていれば」有効。空文字列でも設定扱い。
        $this->assertSame('x', Style::detect(new FakeTerminal(true), '')->accent('x'));
        $this->assertSame('x', Style::detect(new FakeTerminal(true), '1')->accent('x'));
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
nix develop --command make test
```

Expected: FAIL。`Class "Tfcenv\Terminal\KeyMap" not found`。

- [ ] **Step 3: Terminal インタフェースと CancelledException を実装する**

`src/Terminal/Terminal.php`:

```php
<?php

namespace Tfcenv\Terminal;

interface Terminal
{
    public function isTty(): bool;

    /** 端末の桁数。取得できないときは 80 */
    public function width(): int;

    public function write(string $text): void;

    public function writeError(string $text): void;

    /** 行入力（cooked モード）。EOF なら空文字列 */
    public function readLine(): string;

    /**
     * raw モードでの1キー入力。KeyMap の定数か印字可能な1文字を返す。
     * 呼ぶ前に enterRawMode() しておくこと。
     */
    public function readKey(): string;

    public function enterRawMode(): void;

    public function restoreMode(): void;
}
```

`src/Terminal/CancelledException.php`:

```php
<?php

namespace Tfcenv\Terminal;

/** 利用者が Ctrl-C で操作を打ち切った */
final class CancelledException extends \Exception
{
}
```

- [ ] **Step 4: KeyMap を実装する**

`src/Terminal/KeyMap.php`:

```php
<?php

namespace Tfcenv\Terminal;

/**
 * 端末から読んだバイト列をキー名に落とす純粋な変換。
 * 実 STDIN を使わずにテストできるよう、読み取りとは分けてある。
 */
final class KeyMap
{
    public const UP = 'up';
    public const DOWN = 'down';
    public const ENTER = 'enter';
    public const BACKSPACE = 'backspace';
    public const CANCEL = 'cancel';
    public const EOF = 'eof';
    public const UNKNOWN = 'unknown';

    public static function fromBytes(string $bytes): string
    {
        return match ($bytes) {
            '' => self::EOF,
            "\e[A" => self::UP,
            "\e[B" => self::DOWN,
            "\x10" => self::UP,      // Ctrl-P
            "\x0e" => self::DOWN,    // Ctrl-N
            "\r", "\n" => self::ENTER,
            "\x7f", "\x08" => self::BACKSPACE,
            "\x03" => self::CANCEL,  // Ctrl-C。raw モードでは SIGINT ではなくバイトで届く
            "\x04" => self::EOF,     // Ctrl-D
            default => self::classify($bytes),
        };
    }

    public static function isPrintable(string $key): bool
    {
        if (strlen($key) !== 1) {
            return false;
        }

        $code = ord($key);

        return $code >= 0x20 && $code !== 0x7f;
    }

    private static function classify(string $bytes): string
    {
        if (self::isPrintable($bytes)) {
            return $bytes;
        }

        return self::UNKNOWN;
    }
}
```

- [ ] **Step 5: Style を実装する**

`src/Terminal/Style.php`:

```php
<?php

namespace Tfcenv\Terminal;

final class Style
{
    private const RESET = "\e[0m";

    public function __construct(
        private readonly bool $enabled,
    ) {
    }

    /**
     * 非 TTY か NO_COLOR が設定されていれば色を切る。
     * NO_COLOR は「設定されているか」だけを見る規約なので、空文字列でも無効化する。
     */
    public static function detect(Terminal $terminal, ?string $noColor): self
    {
        return new self($terminal->isTty() && $noColor === null);
    }

    public function accent(string $text): string
    {
        return $this->wrap("\e[35m", $text);
    }

    public function ok(string $text): string
    {
        return $this->wrap("\e[32m", $text);
    }

    public function bad(string $text): string
    {
        return $this->wrap("\e[31m", $text);
    }

    public function warn(string $text): string
    {
        return $this->wrap("\e[33m", $text);
    }

    public function dim(string $text): string
    {
        return $this->wrap("\e[2m", $text);
    }

    public function bold(string $text): string
    {
        return $this->wrap("\e[1m", $text);
    }

    private function wrap(string $code, string $text): string
    {
        if (!$this->enabled) {
            return $text;
        }

        return $code . $text . self::RESET;
    }
}
```

- [ ] **Step 6: テストが通ることを確認する**

```bash
nix develop --command make test
```

Expected: PASS。

- [ ] **Step 7: コミット**

```bash
git add -A
git commit -m "Add the terminal seam, key mapping and ANSI styling

Terminal is the second interface the tests drive; FakeTerminal queues lines and
keys and records output, so no test needs a pty.

KeyMap is split out as a pure byte-sequence to key-name conversion. It records
the fact that matters most for the design: stty raw clears isig, so Ctrl-C
arrives as 0x03 rather than as SIGINT, which is why no signal handling is
needed anywhere."
```

---

### Task 7: SttyTerminal（実物）

`stty` と `fread` による `Terminal` の実装。

**Files:**
- Create: `src/Terminal/SttyTerminal.php`
- Create: `tests/Terminal/SttyTerminalTest.php`

**Interfaces:**
- Consumes: `Terminal` / `KeyMap`（Task 6）
- Produces: `Tfcenv\Terminal\SttyTerminal`: `__construct()`、`Terminal` の全メソッド

- [ ] **Step 1: 失敗するテストを書く**

実 STDIN を使う部分はユニットテストできないので、テストは「壊れていないこと」に絞る。
raw モードの往復は Step 5 の手動確認で見る。

`tests/Terminal/SttyTerminalTest.php`:

```php
<?php

namespace Tfcenv\Tests\Terminal;

use PHPUnit\Framework\TestCase;
use Tfcenv\Terminal\SttyTerminal;

final class SttyTerminalTest extends TestCase
{
    public function testTheFunctionsItNeedsAreAllAvailable(): void
    {
        // readline と posix_isatty は拡張なので AOT バイナリには無い。
        // 使っていないことをここで固定する。
        foreach (['shell_exec', 'fread', 'fgets', 'stream_isatty', 'stream_set_blocking'] as $function) {
            $this->assertTrue(function_exists($function), $function . ' is required');
        }
    }

    public function testItReportsAWidth(): void
    {
        $terminal = new SttyTerminal();

        $this->assertGreaterThan(0, $terminal->width());
    }

    public function testIsTtyDoesNotThrowUnderTheTestRunner(): void
    {
        $terminal = new SttyTerminal();

        // PHPUnit 下では TTY でないことが多い。値ではなく型だけ確かめる。
        $this->assertIsBool($terminal->isTty());
    }

    public function testRestoringWithoutEnteringIsHarmless(): void
    {
        $terminal = new SttyTerminal();

        $terminal->restoreMode();
        $terminal->restoreMode();

        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
nix develop --command make test
```

Expected: FAIL。`Class "Tfcenv\Terminal\SttyTerminal" not found`。

- [ ] **Step 3: SttyTerminal を実装する**

`src/Terminal/SttyTerminal.php`:

```php
<?php

namespace Tfcenv\Terminal;

final class SttyTerminal implements Terminal
{
    private ?string $savedMode = null;

    private bool $shutdownHookInstalled = false;

    public function isTty(): bool
    {
        return stream_isatty(STDIN) && stream_isatty(STDOUT);
    }

    public function width(): int
    {
        $columns = trim((string) shell_exec('stty size 2>/dev/null'));

        if ($columns !== '') {
            $parts = explode(' ', $columns);
            if (isset($parts[1]) && (int) $parts[1] > 0) {
                return (int) $parts[1];
            }
        }

        return 80;
    }

    public function write(string $text): void
    {
        fwrite(STDOUT, $text);
    }

    public function writeError(string $text): void
    {
        fwrite(STDERR, $text);
    }

    public function readLine(): string
    {
        $line = fgets(STDIN);

        if ($line === false) {
            return '';
        }

        return rtrim($line, "\r\n");
    }

    /**
     * raw モードで1キー読む。ESC を読んだら続きを非ブロッキングで拾って
     * エスケープシーケンスとして解釈する。
     */
    public function readKey(): string
    {
        $byte = fread(STDIN, 1);

        if ($byte === false || $byte === '') {
            return KeyMap::EOF;
        }

        if ($byte !== "\e") {
            return KeyMap::fromBytes($byte);
        }

        // ESC [ A のような3バイト列。単独の ESC もありうるので非ブロッキングで読む。
        stream_set_blocking(STDIN, false);
        $rest = (string) fread(STDIN, 2);
        stream_set_blocking(STDIN, true);

        return KeyMap::fromBytes($byte . $rest);
    }

    public function enterRawMode(): void
    {
        if ($this->savedMode !== null) {
            return;
        }

        $saved = trim((string) shell_exec('stty -g 2>/dev/null'));

        if ($saved === '') {
            // stty が使えない環境。raw モードには入らない。
            return;
        }

        $this->savedMode = $saved;
        $this->installShutdownHook();

        // raw は isig も落とすので Ctrl-C は SIGINT にならず 0x03 として読める。
        shell_exec('stty raw -echo 2>/dev/null');
    }

    public function restoreMode(): void
    {
        if ($this->savedMode === null) {
            return;
        }

        shell_exec('stty ' . $this->savedMode . ' 2>/dev/null');
        $this->savedMode = null;
    }

    /**
     * 未捕捉例外や exit() で raw モードのまま抜けるのを防ぐ。
     * SIGTERM / SIGKILL では走らないが、そこは受け入れる。
     */
    private function installShutdownHook(): void
    {
        if ($this->shutdownHookInstalled) {
            return;
        }

        $this->shutdownHookInstalled = true;
        register_shutdown_function([$this, 'restoreMode']);
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
nix develop --command make test
```

Expected: PASS。

- [ ] **Step 5: AOT バイナリで raw モードと shutdown hook が動くことを手で確認する**

一時的な確認用ファイルを作る。

```bash
cat > rawcheck.php <<'PHP'
<?php

use Tfcenv\Terminal\KeyMap;
use Tfcenv\Terminal\SttyTerminal;

function main(): void
{
    $t = new SttyTerminal();
    $t->write("isTty: " . ($t->isTty() ? 'yes' : 'no') . "\n");
    $t->write("width: " . $t->width() . "\n");
    $t->write("press a key (Ctrl-C to test cancel): ");
    $t->enterRawMode();
    $key = $t->readKey();
    $t->restoreMode();
    $t->write("\nkey: " . $key . "\n");
}
PHP
cp rawcheck.php src/rawcheck.php
nix develop --command make build
./tfcenv
```

Expected: 矢印キーで `up` / `down`、Enter で `enter`、`Ctrl-C` で `cancel` と表示され、
そのあとシェルが正常に使える（エコーが戻っている）。
`Ctrl-C` で `cancel` が返るなら、Global Constraints の「raw モードでは SIGINT にならない」が実機で確認できたことになる。

確認できたら消す。

```bash
rm -f rawcheck.php src/rawcheck.php
nix develop --command make clean
```

- [ ] **Step 6: コミット**

```bash
git add -A
git commit -m "Add the stty-backed terminal

Raw mode saves the current stty settings, and a shutdown function restores
them so an uncaught exception cannot leave the terminal unusable. That covers
exceptions and exit but not SIGTERM, which is an accepted gap.

Reading a key handles the escape sequence case by switching STDIN to
non-blocking for the two bytes that follow ESC, so a lone ESC does not hang."
```

---

### Task 8: Prompt

text / hidden / confirm / select の4種類の入力。

**Files:**
- Create: `src/Terminal/Prompt.php`
- Create: `tests/Terminal/PromptTest.php`

**Interfaces:**
- Consumes: `Terminal` / `KeyMap` / `Style` / `CancelledException`（Task 6）、`FakeTerminal`（Task 6）
- Produces: `Tfcenv\Terminal\Prompt`: `__construct(private readonly Terminal $terminal, private readonly Style $style)`、`text(string $label, string $default = ''): string`、`hidden(string $label): string`、`confirm(string $label, bool $default = true): bool`、`select(string $label, array $options, string $default = ''): string`（`$options` は `value => label`、返り値は選ばれた value）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Terminal/PromptTest.php`:

```php
<?php

namespace Tfcenv\Tests\Terminal;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tests\Support\FakeTerminal;
use Tfcenv\Terminal\CancelledException;
use Tfcenv\Terminal\KeyMap;
use Tfcenv\Terminal\Prompt;
use Tfcenv\Terminal\Style;

final class PromptTest extends TestCase
{
    public function testTextReturnsWhatWasTyped(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('acme');
        $prompt = new Prompt($terminal, new Style(false));

        $this->assertSame('acme', $prompt->text('Organization'));
        $this->assertStringContainsString('Organization', $terminal->output());
    }

    public function testTextFallsBackToTheDefaultOnEmptyInput(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('');
        $prompt = new Prompt($terminal, new Style(false));

        $this->assertSame('acme', $prompt->text('Organization', 'acme'));
    }

    public function testTextShowsTheDefaultInTheLabel(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('');
        $prompt = new Prompt($terminal, new Style(false));

        $prompt->text('Organization', 'acme');

        $this->assertStringContainsString('[acme]', $terminal->output());
    }

    public function testTextTrimsSurroundingWhitespace(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('  partner_client_id  ');
        $prompt = new Prompt($terminal, new Style(false));

        $this->assertSame('partner_client_id', $prompt->text('Key'));
    }

    public function testHiddenReadsInRawModeAndNeverEchoesTheValue(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('s3cret');
        $terminal->queueKeys(KeyMap::ENTER);
        $prompt = new Prompt($terminal, new Style(false));

        $value = $prompt->hidden('Value');

        $this->assertSame('s3cret', $value);
        $this->assertStringNotContainsString('s3cret', $terminal->output());
        $this->assertSame(1, $terminal->rawModeEntered());
        $this->assertSame(1, $terminal->rawModeRestored());
    }

    public function testHiddenSupportsBackspace(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('abx');
        $terminal->queueKeys(KeyMap::BACKSPACE);
        $terminal->queueTyping('c');
        $terminal->queueKeys(KeyMap::ENTER);
        $prompt = new Prompt($terminal, new Style(false));

        $this->assertSame('abc', $prompt->hidden('Value'));
    }

    public function testHiddenCancelsOnCtrlCAndRestoresTheTerminal(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('ab');
        $terminal->queueKeys(KeyMap::CANCEL);
        $prompt = new Prompt($terminal, new Style(false));

        try {
            $prompt->hidden('Value');
            $this->fail('expected a CancelledException');
        } catch (CancelledException $e) {
            $this->assertSame(1, $terminal->rawModeRestored());
        }
    }

    public function testConfirmAcceptsYesAndNo(): void
    {
        $yes = new FakeTerminal();
        $yes->queueLine('y');
        $no = new FakeTerminal();
        $no->queueLine('n');

        $this->assertTrue((new Prompt($yes, new Style(false)))->confirm('Apply?'));
        $this->assertFalse((new Prompt($no, new Style(false)))->confirm('Apply?'));
    }

    public function testConfirmUsesTheDefaultOnEmptyInput(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('');
        $terminal->queueLine('');

        $prompt = new Prompt($terminal, new Style(false));

        $this->assertTrue($prompt->confirm('Apply?', true));
        $this->assertFalse($prompt->confirm('Apply?', false));
    }

    public function testConfirmRepromptsOnUnrecognisedInput(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('maybe');
        $terminal->queueLine('yes');
        $prompt = new Prompt($terminal, new Style(false));

        $this->assertTrue($prompt->confirm('Apply?'));
    }

    public function testSelectMovesWithArrowKeysAndReturnsTheChosenValue(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::ENTER);
        $prompt = new Prompt($terminal, new Style(false));

        $chosen = $prompt->select('What now?', [
            'update' => 'Update the value',
            'attrs' => 'Update the value and attributes',
            'skip' => 'Skip this variable',
        ]);

        $this->assertSame('attrs', $chosen);
    }

    public function testSelectStartsOnTheDefaultOption(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::ENTER);
        $prompt = new Prompt($terminal, new Style(false));

        $chosen = $prompt->select('Category', [
            'terraform' => 'terraform',
            'env' => 'env',
        ], 'env');

        $this->assertSame('env', $chosen);
    }

    public function testSelectDoesNotWrapPastTheEnds(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::UP, KeyMap::UP, KeyMap::ENTER);
        $prompt = new Prompt($terminal, new Style(false));

        $chosen = $prompt->select('Category', [
            'terraform' => 'terraform',
            'env' => 'env',
        ]);

        $this->assertSame('terraform', $chosen);
    }

    public function testSelectCancelsOnCtrlC(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::CANCEL);
        $prompt = new Prompt($terminal, new Style(false));

        $this->expectException(CancelledException::class);
        $prompt->select('Category', ['terraform' => 'terraform']);
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
nix develop --command make test
```

Expected: FAIL。`Class "Tfcenv\Terminal\Prompt" not found`。

- [ ] **Step 3: Prompt を実装する**

`src/Terminal/Prompt.php`:

```php
<?php

namespace Tfcenv\Terminal;

final class Prompt
{
    public function __construct(
        private readonly Terminal $terminal,
        private readonly Style $style,
    ) {
    }

    public function text(string $label, string $default = ''): string
    {
        $suffix = $default === '' ? '' : ' ' . $this->style->dim('[' . $default . ']');
        $this->terminal->write($this->style->accent('?') . ' ' . $label . $suffix . ' ');

        $answer = trim($this->terminal->readLine());

        return $answer === '' ? $default : $answer;
    }

    /**
     * 入力を表示せずに1行読む。raw モードで読むので Ctrl-C は
     * SIGINT ではなくバイトとして届き、端末を必ず復元できる。
     */
    public function hidden(string $label): string
    {
        $this->terminal->write($this->style->accent('?') . ' ' . $label . ' ');
        $this->terminal->enterRawMode();

        $value = '';

        try {
            while (true) {
                $key = $this->terminal->readKey();

                if ($key === KeyMap::CANCEL) {
                    throw new CancelledException('cancelled');
                }

                if ($key === KeyMap::ENTER || $key === KeyMap::EOF) {
                    break;
                }

                if ($key === KeyMap::BACKSPACE) {
                    if ($value !== '') {
                        $value = substr($value, 0, -1);
                        $this->terminal->write("\x08 \x08");
                    }
                    continue;
                }

                if (KeyMap::isPrintable($key)) {
                    $value .= $key;
                    $this->terminal->write('*');
                }
            }
        } finally {
            $this->terminal->restoreMode();
        }

        $this->terminal->write("\n");

        return $value;
    }

    public function confirm(string $label, bool $default = true): bool
    {
        $hint = $default ? '[Y/n]' : '[y/N]';

        while (true) {
            $this->terminal->write($this->style->accent('?') . ' ' . $label . ' ' . $this->style->dim($hint) . ' ');
            $answer = strtolower(trim($this->terminal->readLine()));

            if ($answer === '') {
                return $default;
            }

            if ($answer === 'y' || $answer === 'yes') {
                return true;
            }

            if ($answer === 'n' || $answer === 'no') {
                return false;
            }

            $this->terminal->write($this->style->dim('  y or n を入力してください') . "\n");
        }
    }

    /**
     * 上下キーで選ぶメニュー。
     *
     * @param array<string,string> $options value => 表示ラベル
     * @return string 選ばれた value
     */
    public function select(string $label, array $options, string $default = ''): string
    {
        $values = array_keys($options);

        if ($values === []) {
            throw new \InvalidArgumentException('select() needs at least one option');
        }

        $cursor = 0;
        $defaultIndex = array_search($default, $values, true);

        if ($defaultIndex !== false) {
            $cursor = (int) $defaultIndex;
        }

        $this->terminal->write($this->style->accent('?') . ' ' . $label . "\n");
        $this->terminal->enterRawMode();

        try {
            while (true) {
                $this->renderOptions($options, $cursor);
                $key = $this->terminal->readKey();

                if ($key === KeyMap::CANCEL) {
                    throw new CancelledException('cancelled');
                }

                if ($key === KeyMap::ENTER || $key === KeyMap::EOF) {
                    return $values[$cursor];
                }

                if ($key === KeyMap::UP && $cursor > 0) {
                    $cursor--;
                }

                if ($key === KeyMap::DOWN && $cursor < count($values) - 1) {
                    $cursor++;
                }

                $this->clearLines(count($values));
            }
        } finally {
            $this->terminal->restoreMode();
        }
    }

    /**
     * @param array<string,string> $options
     */
    private function renderOptions(array $options, int $cursor): void
    {
        $index = 0;

        foreach ($options as $optionLabel) {
            $marker = $index === $cursor ? $this->style->accent('>') : ' ';
            $text = $index === $cursor ? $this->style->bold($optionLabel) : $optionLabel;
            $this->terminal->write('  ' . $marker . ' ' . $text . "\r\n");
            $index++;
        }
    }

    private function clearLines(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->terminal->write("\e[1A\e[2K");
        }
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
nix develop --command make test
```

Expected: PASS。

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "Add the four prompt types

Hidden input reads in raw mode rather than through stty -echo. Raw mode clears
isig, so Ctrl-C arrives as a byte and the finally block always restores the
terminal; stty -echo would have left SIGINT enabled and echo switched off if
the user interrupted a password.

The tests assert the secret never reaches the output stream."
```

---

### Task 9: Picker

絞り込み付きのリスト選択。

**Files:**
- Create: `src/Terminal/Picker.php`
- Create: `tests/Terminal/PickerTest.php`

**Interfaces:**
- Consumes: `Terminal` / `KeyMap` / `Style` / `CancelledException`（Task 6）
- Produces: `Tfcenv\Terminal\Picker`: `__construct(private readonly Terminal $terminal, private readonly Style $style, private readonly int $visibleRows = 8)`、`pick(string $label, array $items): string`（`$items` は `value => label`）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Terminal/PickerTest.php`:

```php
<?php

namespace Tfcenv\Tests\Terminal;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tests\Support\FakeTerminal;
use Tfcenv\Terminal\CancelledException;
use Tfcenv\Terminal\KeyMap;
use Tfcenv\Terminal\Picker;
use Tfcenv\Terminal\Style;

final class PickerTest extends TestCase
{
    /** @return array<string,string> */
    private function workspaces(): array
    {
        return [
            'ws-1' => 'alpha-core-prod',
            'ws-2' => 'alpha-core-stg',
            'ws-3' => 'alpha-core-worker-prod',
            'ws-4' => 'beta-core-prod',
            'ws-5' => 'medical-records-prod',
        ];
    }

    public function testEnterPicksTheFirstItemWhenNothingIsTyped(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $this->assertSame('ws-1', $picker->pick('Workspace', $this->workspaces()));
    }

    public function testTypingNarrowsTheCandidates(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('beta');
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $this->assertSame('ws-4', $picker->pick('Workspace', $this->workspaces()));
    }

    public function testFilteringIsCaseInsensitiveAndMatchesAnywhere(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('WORKER');
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $this->assertSame('ws-3', $picker->pick('Workspace', $this->workspaces()));
    }

    public function testArrowKeysMoveWithinTheFilteredList(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('alpha');
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $this->assertSame('ws-2', $picker->pick('Workspace', $this->workspaces()));
    }

    public function testBackspaceWidensTheFilterAgain(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('beta');
        foreach (range(1, 6) as $ignored) {
            $terminal->queueKeys(KeyMap::BACKSPACE);
        }
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $this->assertSame('ws-1', $picker->pick('Workspace', $this->workspaces()));
    }

    public function testTheCursorResetsWhenTheFilterChanges(): void
    {
        $terminal = new FakeTerminal();
        // core で4件に絞って2番目(ws-2)へ移動したあと、さらに絞り込むと
        // 候補の並びが変わるのでカーソルは先頭に戻る。
        // 戻らなければ 2番目の beta-core-prod(ws-4) が選ばれてしまう。
        $terminal->queueTyping('core');
        $terminal->queueKeys(KeyMap::DOWN);
        $terminal->queueTyping('-p');
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $this->assertSame('ws-1', $picker->pick('Workspace', $this->workspaces()));
    }

    public function testEnterOnAnEmptyResultKeepsWaiting(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('zzz');
        $terminal->queueKeys(KeyMap::ENTER);        // 候補ゼロなので確定しない
        $terminal->queueKeys(KeyMap::BACKSPACE, KeyMap::BACKSPACE, KeyMap::BACKSPACE);
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $this->assertSame('ws-1', $picker->pick('Workspace', $this->workspaces()));
    }

    public function testItShowsHowManyOfHowManyAreVisible(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('alpha');
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $picker->pick('Workspace', $this->workspaces());

        $this->assertStringContainsString('3/5', $terminal->output());
    }

    public function testCtrlCCancelsAndRestoresTheTerminal(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::CANCEL);
        $picker = new Picker($terminal, new Style(false));

        try {
            $picker->pick('Workspace', $this->workspaces());
            $this->fail('expected a CancelledException');
        } catch (CancelledException $e) {
            $this->assertSame(1, $terminal->rawModeRestored());
        }
    }

    public function testAnEmptyItemListIsRejected(): void
    {
        $picker = new Picker(new FakeTerminal(), new Style(false));

        $this->expectException(\InvalidArgumentException::class);
        $picker->pick('Workspace', []);
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
nix develop --command make test
```

Expected: FAIL。`Class "Tfcenv\Terminal\Picker" not found`。

- [ ] **Step 3: Picker を実装する**

`src/Terminal/Picker.php`:

```php
<?php

namespace Tfcenv\Terminal;

final class Picker
{
    public function __construct(
        private readonly Terminal $terminal,
        private readonly Style $style,
        private readonly int $visibleRows = 8,
    ) {
    }

    /**
     * 絞り込み付きのリスト選択。
     *
     * @param array<string,string> $items value => 表示ラベル。絞り込みはラベルに対して行う
     * @return string 選ばれた value
     * @throws CancelledException Ctrl-C
     */
    public function pick(string $label, array $items): string
    {
        if ($items === []) {
            throw new \InvalidArgumentException('pick() needs at least one item');
        }

        $filter = '';
        $cursor = 0;
        $renderedLines = 0;

        $this->terminal->write($this->style->accent('?') . ' ' . $label . "\n");
        $this->terminal->enterRawMode();

        try {
            while (true) {
                $matches = $this->filter($items, $filter);
                $cursor = $this->clamp($cursor, count($matches));

                $this->clearLines($renderedLines);
                $renderedLines = $this->render($filter, $matches, $cursor, count($items));

                $key = $this->terminal->readKey();

                if ($key === KeyMap::CANCEL) {
                    throw new CancelledException('cancelled');
                }

                if ($key === KeyMap::EOF) {
                    if ($matches === []) {
                        throw new CancelledException('no candidate to select');
                    }
                    return array_keys($matches)[$cursor];
                }

                if ($key === KeyMap::ENTER) {
                    if ($matches !== []) {
                        return array_keys($matches)[$cursor];
                    }
                    continue;
                }

                if ($key === KeyMap::UP) {
                    $cursor = $cursor > 0 ? $cursor - 1 : 0;
                    continue;
                }

                if ($key === KeyMap::DOWN) {
                    $cursor = $cursor + 1;
                    continue;
                }

                if ($key === KeyMap::BACKSPACE) {
                    if ($filter !== '') {
                        $filter = substr($filter, 0, -1);
                        $cursor = 0;
                    }
                    continue;
                }

                if (KeyMap::isPrintable($key)) {
                    $filter .= $key;
                    // 絞り込みが変われば候補の並びが変わるのでカーソルを先頭へ戻す
                    $cursor = 0;
                }
            }
        } finally {
            $this->terminal->restoreMode();
        }
    }

    /**
     * @param array<string,string> $items
     * @return array<string,string>
     */
    private function filter(array $items, string $filter): array
    {
        if ($filter === '') {
            return $items;
        }

        $needle = strtolower($filter);
        $matches = [];

        foreach ($items as $value => $itemLabel) {
            if (str_contains(strtolower($itemLabel), $needle)) {
                $matches[$value] = $itemLabel;
            }
        }

        return $matches;
    }

    private function clamp(int $cursor, int $count): int
    {
        if ($count === 0) {
            return 0;
        }

        if ($cursor < 0) {
            return 0;
        }

        if ($cursor > $count - 1) {
            return $count - 1;
        }

        return $cursor;
    }

    /**
     * @param array<string,string> $matches
     * @return int 描いた行数
     */
    private function render(string $filter, array $matches, int $cursor, int $total): int
    {
        $this->terminal->write('  ' . $this->style->dim('filter:') . ' ' . $filter . "\r\n");
        $lines = 1;

        $labels = array_values($matches);
        $window = $this->window($cursor, count($labels));

        foreach ($window as $index) {
            $marker = $index === $cursor ? $this->style->accent('>') : ' ';
            $text = $index === $cursor ? $this->style->bold($labels[$index]) : $labels[$index];
            $this->terminal->write('  ' . $marker . ' ' . $text . "\r\n");
            $lines++;
        }

        if ($matches === []) {
            $this->terminal->write('  ' . $this->style->warn('no match') . "\r\n");
            $lines++;
        }

        $this->terminal->write(
            '  ' . $this->style->dim(sprintf('%d/%d shown, type to filter', count($matches), $total)) . "\r\n",
        );
        $lines++;

        return $lines;
    }

    /**
     * カーソルが常に見える範囲のインデックス列を返す。
     *
     * @return int[]
     */
    private function window(int $cursor, int $count): array
    {
        if ($count === 0) {
            return [];
        }

        $start = 0;

        if ($count > $this->visibleRows && $cursor >= $this->visibleRows) {
            $start = $cursor - $this->visibleRows + 1;
        }

        $end = min($count, $start + $this->visibleRows);
        $indexes = [];

        for ($i = $start; $i < $end; $i++) {
            $indexes[] = $i;
        }

        return $indexes;
    }

    private function clearLines(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->terminal->write("\e[1A\e[2K");
        }
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
nix develop --command make test
```

Expected: PASS。

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "Add the filtering list picker

Typing narrows by substring, case-insensitively, and resets the cursor because
the ordering underneath it changed. Enter on an empty result set does nothing
rather than picking a candidate that is not there.

A window keeps the cursor visible when the organization has more workspaces
than fit on screen."
```

---

### Task 10: 中間表現・確認画面・適用

入力方法に依存しない共通層。P2 の JSON モードがそのまま再利用する。

**Files:**
- Create: `src/Cli/ChangeOp.php`
- Create: `src/Cli/Change.php`
- Create: `src/Cli/ChangeSet.php`
- Create: `src/Cli/ApplyResult.php`
- Create: `src/Cli/Confirmation.php`
- Create: `src/Cli/Applier.php`
- Create: `tests/Cli/ChangeSetTest.php`
- Create: `tests/Cli/ConfirmationTest.php`
- Create: `tests/Cli/ApplierTest.php`

**Interfaces:**
- Consumes: `Variable` / `Category`（Task 1）、`Workspace`（Task 4）、`VariableRepository`（Task 5）、`Terminal` / `Style`（Task 6）、`Prompt`（Task 8）、`TfcException`（Task 1）
- Produces:
  - `Tfcenv\Cli\ChangeOp`: `enum ChangeOp: string { case Create = 'create'; case Update = 'update'; }`
  - `Tfcenv\Cli\Change`: `__construct(public readonly ChangeOp $op, public readonly Variable $variable)`
  - `Tfcenv\Cli\ChangeSet`: `__construct(public readonly string $organization, public readonly Workspace $workspace, public readonly array $changes)`、`isEmpty(): bool`、`countOf(ChangeOp $op): int`
  - `Tfcenv\Cli\ApplyResult`: `__construct(public readonly int $succeeded, public readonly int $failed)`、`hasFailures(): bool`
  - `Tfcenv\Cli\Confirmation`: `__construct(private readonly Terminal $terminal, private readonly Style $style, private readonly Prompt $prompt)`、`ask(ChangeSet $set): bool`
  - `Tfcenv\Cli\Applier`: `__construct(private readonly VariableRepository $variables, private readonly Terminal $terminal, private readonly Style $style)`、`apply(ChangeSet $set): ApplyResult`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Cli/ChangeSetTest.php`:

```php
<?php

namespace Tfcenv\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tfcenv\Cli\Change;
use Tfcenv\Cli\ChangeOp;
use Tfcenv\Cli\ChangeSet;
use Tfcenv\Tfc\Category;
use Tfcenv\Tfc\Variable;
use Tfcenv\Tfc\Workspace;

final class ChangeSetTest extends TestCase
{
    public function testItCountsCreatesAndUpdatesSeparately(): void
    {
        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), [
            new Change(ChangeOp::Create, new Variable('a', 'v', Category::Terraform, false, '')),
            new Change(ChangeOp::Create, new Variable('b', 'v', Category::Terraform, false, '')),
            new Change(ChangeOp::Update, new Variable('c', 'v', Category::Terraform, false, '', 'var-3')),
        ]);

        $this->assertSame(2, $set->countOf(ChangeOp::Create));
        $this->assertSame(1, $set->countOf(ChangeOp::Update));
        $this->assertFalse($set->isEmpty());
    }

    public function testAnEmptySetReportsItself(): void
    {
        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), []);

        $this->assertTrue($set->isEmpty());
        $this->assertSame(0, $set->countOf(ChangeOp::Create));
    }
}
```

`tests/Cli/ConfirmationTest.php`:

```php
<?php

namespace Tfcenv\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tfcenv\Cli\Change;
use Tfcenv\Cli\ChangeOp;
use Tfcenv\Cli\ChangeSet;
use Tfcenv\Cli\Confirmation;
use Tfcenv\Tests\Support\FakeTerminal;
use Tfcenv\Terminal\Prompt;
use Tfcenv\Terminal\Style;
use Tfcenv\Tfc\Category;
use Tfcenv\Tfc\Variable;
use Tfcenv\Tfc\Workspace;

final class ConfirmationTest extends TestCase
{
    private function set(): ChangeSet
    {
        return new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), [
            new Change(ChangeOp::Create, new Variable('partner_client_id', 'super-secret', Category::Terraform, true, 'OAuth クライアントID')),
            new Change(ChangeOp::Update, new Variable('gtm_container_id', 'GTM-XXXXXXX', Category::Terraform, false, 'GTM コンテナ ID', 'var-2')),
        ]);
    }

    private function confirmation(FakeTerminal $terminal): Confirmation
    {
        $style = new Style(false);

        return new Confirmation($terminal, $style, new Prompt($terminal, $style));
    }

    public function testItShowsTheWorkspaceAndTheOperationBreakdown(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('y');

        $this->confirmation($terminal)->ask($this->set());

        $output = $terminal->output();
        $this->assertStringContainsString('acme/alpha-core-prod', $output);
        $this->assertStringContainsString('create', $output);
        $this->assertStringContainsString('update', $output);
        $this->assertStringContainsString('partner_client_id', $output);
        $this->assertStringContainsString('gtm_container_id', $output);
    }

    public function testItMasksSensitiveValuesAndShowsPlainOnes(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('y');

        $this->confirmation($terminal)->ask($this->set());

        $output = $terminal->output();
        $this->assertStringNotContainsString('super-secret', $output);
        $this->assertStringContainsString('••••••••', $output);
        $this->assertStringContainsString('GTM-XXXXXXX', $output);
    }

    public function testItReturnsTheAnswer(): void
    {
        $yes = new FakeTerminal();
        $yes->queueLine('y');
        $no = new FakeTerminal();
        $no->queueLine('n');

        $this->assertTrue($this->confirmation($yes)->ask($this->set()));
        $this->assertFalse($this->confirmation($no)->ask($this->set()));
    }

    public function testAnEmptySetIsNotWorthAsking(): void
    {
        $terminal = new FakeTerminal();
        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), []);

        $this->assertFalse($this->confirmation($terminal)->ask($set));
        $this->assertStringContainsString('nothing to apply', $terminal->output());
    }
}
```

`tests/Cli/ApplierTest.php`:

```php
<?php

namespace Tfcenv\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tfcenv\Cli\Applier;
use Tfcenv\Cli\Change;
use Tfcenv\Cli\ChangeOp;
use Tfcenv\Cli\ChangeSet;
use Tfcenv\Tests\Support\FakeTerminal;
use Tfcenv\Tests\Support\FakeTransport;
use Tfcenv\Terminal\Style;
use Tfcenv\Tfc\Category;
use Tfcenv\Tfc\Client;
use Tfcenv\Tfc\Variable;
use Tfcenv\Tfc\VariableRepository;
use Tfcenv\Tfc\Workspace;

final class ApplierTest extends TestCase
{
    private function applier(FakeTransport $transport, FakeTerminal $terminal): Applier
    {
        return new Applier(
            new VariableRepository(new Client($transport, 'tok')),
            $terminal,
            new Style(false),
        );
    }

    public function testItPostsCreatesAndPatchesUpdates(): void
    {
        $transport = new FakeTransport();
        $transport->queue(201, '{"data":{"id":"var-1","attributes":{"key":"a","category":"terraform","sensitive":false,"description":""}}}');
        $transport->queue(200, '{"data":{"id":"var-2","attributes":{"key":"b","category":"terraform","sensitive":false,"description":""}}}');
        $terminal = new FakeTerminal();

        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), [
            new Change(ChangeOp::Create, new Variable('a', 'v', Category::Terraform, false, '')),
            new Change(ChangeOp::Update, new Variable('b', 'v', Category::Terraform, false, '', 'var-2')),
        ]);

        $result = $this->applier($transport, $terminal)->apply($set);

        $this->assertSame(2, $result->succeeded);
        $this->assertSame(0, $result->failed);
        $this->assertFalse($result->hasFailures());
        $this->assertSame('POST', $transport->requests()[0]->method);
        $this->assertSame('PATCH', $transport->requests()[1]->method);
    }

    public function testAFailureDoesNotStopTheRest(): void
    {
        $transport = new FakeTransport();
        $transport->queue(422, '{"errors":[{"detail":"Key has already been taken"}]}');
        $transport->queue(201, '{"data":{"id":"var-2","attributes":{"key":"b","category":"terraform","sensitive":false,"description":""}}}');
        $terminal = new FakeTerminal();

        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), [
            new Change(ChangeOp::Create, new Variable('a', 'v', Category::Terraform, false, '')),
            new Change(ChangeOp::Create, new Variable('b', 'v', Category::Terraform, false, '')),
        ]);

        $result = $this->applier($transport, $terminal)->apply($set);

        $this->assertSame(1, $result->succeeded);
        $this->assertSame(1, $result->failed);
        $this->assertTrue($result->hasFailures());
        $this->assertCount(2, $transport->requests());
        $this->assertStringContainsString('Key has already been taken', $terminal->output());
    }

    public function testItNeverPrintsASensitiveValue(): void
    {
        $transport = new FakeTransport();
        $transport->queue(422, '{"errors":[{"detail":"nope"}]}');
        $terminal = new FakeTerminal();

        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), [
            new Change(ChangeOp::Create, new Variable('k', 'super-secret', Category::Terraform, true, '')),
        ]);

        $this->applier($transport, $terminal)->apply($set);

        $this->assertStringNotContainsString('super-secret', $terminal->output());
    }

    public function testItReportsProgressPerVariable(): void
    {
        $transport = new FakeTransport();
        $transport->queue(201, '{"data":{"id":"var-1","attributes":{"key":"a","category":"terraform","sensitive":false,"description":""}}}');
        $terminal = new FakeTerminal();

        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), [
            new Change(ChangeOp::Create, new Variable('a', 'v', Category::Terraform, false, '')),
        ]);

        $this->applier($transport, $terminal)->apply($set);

        $this->assertStringContainsString('a', $terminal->output());
        $this->assertStringContainsString('1 succeeded', $terminal->output());
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
nix develop --command make test
```

Expected: FAIL。`Class "Tfcenv\Cli\ChangeSet" not found`。

- [ ] **Step 3: 中間表現の型を実装する**

`src/Cli/ChangeOp.php`:

```php
<?php

namespace Tfcenv\Cli;

enum ChangeOp: string
{
    case Create = 'create';
    case Update = 'update';
}
```

`src/Cli/Change.php`:

```php
<?php

namespace Tfcenv\Cli;

use Tfcenv\Tfc\Variable;

final class Change
{
    public function __construct(
        public readonly ChangeOp $op,
        public readonly Variable $variable,
    ) {
    }
}
```

`src/Cli/ChangeSet.php`:

```php
<?php

namespace Tfcenv\Cli;

use Tfcenv\Tfc\Workspace;

/**
 * 適用する変更のまとまり。対話でも JSON でも、入力方法によらずこの形にしてから
 * 確認と適用に渡す。
 */
final class ChangeSet
{
    /**
     * @param Change[] $changes
     */
    public function __construct(
        public readonly string $organization,
        public readonly Workspace $workspace,
        public readonly array $changes,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    public function countOf(ChangeOp $op): int
    {
        $count = 0;

        foreach ($this->changes as $change) {
            if ($change->op === $op) {
                $count++;
            }
        }

        return $count;
    }
}
```

`src/Cli/ApplyResult.php`:

```php
<?php

namespace Tfcenv\Cli;

final class ApplyResult
{
    public function __construct(
        public readonly int $succeeded,
        public readonly int $failed,
    ) {
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
```

- [ ] **Step 4: Confirmation を実装する**

`src/Cli/Confirmation.php`:

```php
<?php

namespace Tfcenv\Cli;

use Tfcenv\Terminal\Prompt;
use Tfcenv\Terminal\Style;
use Tfcenv\Terminal\Terminal;

final class Confirmation
{
    public function __construct(
        private readonly Terminal $terminal,
        private readonly Style $style,
        private readonly Prompt $prompt,
    ) {
    }

    public function ask(ChangeSet $set): bool
    {
        if ($set->isEmpty()) {
            $this->terminal->write($this->style->dim('nothing to apply') . "\n");

            return false;
        }

        $this->terminal->write("\n");
        $this->terminal->write($this->style->bold($set->organization . '/' . $set->workspace->name) . "\n");

        foreach ($set->changes as $change) {
            $this->terminal->write(sprintf(
                '  %s  %s = %s  %s%s',
                $this->label($change->op),
                $change->variable->key,
                $change->variable->displayValue(),
                $this->style->dim($this->attributes($change)),
                "\n",
            ));
        }

        $this->terminal->write("\n");

        return $this->prompt->confirm(sprintf(
            'Apply %d change(s)? (create %d / update %d)',
            count($set->changes),
            $set->countOf(ChangeOp::Create),
            $set->countOf(ChangeOp::Update),
        ));
    }

    private function label(ChangeOp $op): string
    {
        return match ($op) {
            ChangeOp::Create => $this->style->ok('create'),
            ChangeOp::Update => $this->style->warn('update'),
        };
    }

    private function attributes(Change $change): string
    {
        $parts = [$change->variable->category->value];
        $parts[] = $change->variable->sensitive ? 'sensitive' : 'plain';

        if ($change->variable->description !== '') {
            $parts[] = '"' . $change->variable->description . '"';
        }

        return '(' . implode(', ', $parts) . ')';
    }
}
```

- [ ] **Step 5: Applier を実装する**

`src/Cli/Applier.php`:

```php
<?php

namespace Tfcenv\Cli;

use Tfcenv\Terminal\Style;
use Tfcenv\Terminal\Terminal;
use Tfcenv\Tfc\TfcException;
use Tfcenv\Tfc\VariableRepository;

final class Applier
{
    public function __construct(
        private readonly VariableRepository $variables,
        private readonly Terminal $terminal,
        private readonly Style $style,
    ) {
    }

    /**
     * 1件ずつ適用する。1件失敗しても中断せず残りを続け、最後に集計を出す。
     */
    public function apply(ChangeSet $set): ApplyResult
    {
        $succeeded = 0;
        $failed = 0;

        foreach ($set->changes as $change) {
            try {
                match ($change->op) {
                    ChangeOp::Create => $this->variables->create($set->workspace->id, $change->variable),
                    ChangeOp::Update => $this->variables->update($set->workspace->id, $change->variable),
                };

                $succeeded++;
                $this->terminal->write(sprintf(
                    '  %s %s %s%s',
                    $this->style->ok('✓'),
                    $change->op->value,
                    $change->variable->key,
                    "\n",
                ));
            } catch (TfcException $e) {
                $failed++;
                // 例外メッセージには value を載せていないのでそのまま出せる
                $this->terminal->write(sprintf(
                    '  %s %s %s%s      %s%s',
                    $this->style->bad('✗'),
                    $change->op->value,
                    $change->variable->key,
                    "\n",
                    $e->getMessage(),
                    "\n",
                ));
            }
        }

        $this->terminal->write(sprintf("\n%d succeeded, %d failed\n", $succeeded, $failed));

        return new ApplyResult($succeeded, $failed);
    }
}
```

- [ ] **Step 6: テストが通ることを確認する**

```bash
nix develop --command make test
```

Expected: PASS。

- [ ] **Step 7: コミット**

```bash
git add -A
git commit -m "Add the change set, the confirmation screen and the applier

These three carry no knowledge of how the changes were gathered, which is what
lets P2 feed the same objects from a JSON file. ChangeSet is the intermediate
representation the spec describes.

Applying continues past a failure and reports per variable, so one rejected key
does not block the rest of a batch. Sensitive values are printed through
displayValue(), and the tests assert the raw value never reaches the output."
```

---

### Task 11: AddCommand

対話フローの本体。

**Files:**
- Create: `src/Cli/AddCommand.php`
- Create: `tests/Cli/AddCommandTest.php`

**Interfaces:**
- Consumes: 前タスクのすべて
- Produces: `Tfcenv\Cli\AddCommand`: `__construct(private readonly WorkspaceRepository $workspaces, private readonly VariableRepository $variables, private readonly Terminal $terminal, private readonly Style $style, private readonly Prompt $prompt, private readonly Picker $picker, private readonly Confirmation $confirmation, private readonly Applier $applier, private readonly string $defaultOrganization)`、`run(): int`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Cli/AddCommandTest.php`:

```php
<?php

namespace Tfcenv\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tfcenv\Cli\AddCommand;
use Tfcenv\Cli\Applier;
use Tfcenv\Cli\Confirmation;
use Tfcenv\Tests\Support\FakeTerminal;
use Tfcenv\Tests\Support\FakeTransport;
use Tfcenv\Terminal\KeyMap;
use Tfcenv\Terminal\Picker;
use Tfcenv\Terminal\Prompt;
use Tfcenv\Terminal\Style;
use Tfcenv\Tfc\Client;
use Tfcenv\Tfc\VariableRepository;
use Tfcenv\Tfc\WorkspaceRepository;

final class AddCommandTest extends TestCase
{
    private function command(FakeTransport $transport, FakeTerminal $terminal, string $org = 'acme'): AddCommand
    {
        $client = new Client($transport, 'tok');
        $style = new Style(false);
        $prompt = new Prompt($terminal, $style);
        $variables = new VariableRepository($client);

        return new AddCommand(
            new WorkspaceRepository($client),
            $variables,
            $terminal,
            $style,
            $prompt,
            new Picker($terminal, $style),
            new Confirmation($terminal, $style, $prompt),
            new Applier($variables, $terminal, $style),
            $org,
        );
    }

    private function queueWorkspaces(FakeTransport $transport): void
    {
        $transport->queue(200, json_encode([
            'data' => [
                ['id' => 'ws-1', 'attributes' => ['name' => 'alpha-core-prod']],
                ['id' => 'ws-2', 'attributes' => ['name' => 'alpha-core-stg']],
            ],
            'meta' => ['pagination' => ['next-page' => null]],
        ]));
    }

    public function testItRefusesToRunWithoutATty(): void
    {
        $transport = new FakeTransport();
        $terminal = new FakeTerminal(false);

        $exit = $this->command($transport, $terminal)->run();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('TTY', $terminal->errorOutput());
        $this->assertSame([], $transport->requests());
    }

    public function testItCreatesANewVariable(): void
    {
        $transport = new FakeTransport();
        $this->queueWorkspaces($transport);
        $transport->queue(200, '{"data":[]}');                       // 既存変数なし
        $transport->queue(201, '{"data":{"id":"var-1","attributes":{"key":"partner_client_id","category":"terraform","sensitive":true,"description":"OAuth"}}}');

        $terminal = new FakeTerminal();
        $terminal->queueLine('acme');                           // organization
        $terminal->queueKeys(KeyMap::ENTER);                         // workspace picker: alpha-core-prod
        $terminal->queueLine('partner_client_id');                     // key
        $terminal->queueKeys(KeyMap::ENTER);                         // category: terraform
        $terminal->queueKeys(KeyMap::ENTER);                         // sensitive: Yes（既定）
        $terminal->queueTyping('abc123');                            // value（非表示）
        $terminal->queueKeys(KeyMap::ENTER);
        $terminal->queueLine('OAuth');                               // description
        $terminal->queueLine('n');                                   // もう1件? No
        $terminal->queueLine('y');                                   // 確認画面 Yes

        $exit = $this->command($transport, $terminal)->run();

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('abc123', $terminal->output());
        $create = $transport->requests()[2];
        $this->assertSame('POST', $create->method);
        $this->assertStringContainsString('"key":"partner_client_id"', (string) $create->body);
    }

    public function testItDetectsACollisionAndUpdatesOnlyTheValue(): void
    {
        $transport = new FakeTransport();
        $this->queueWorkspaces($transport);
        $transport->queue(200, json_encode([
            'data' => [[
                'id' => 'var-9',
                'attributes' => [
                    'key' => 'partner_client_id',
                    'value' => null,
                    'category' => 'terraform',
                    'sensitive' => true,
                    'description' => '既存の説明',
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE));
        $transport->queue(200, '{"data":{"id":"var-9","attributes":{"key":"partner_client_id","category":"terraform","sensitive":true,"description":"既存の説明"}}}');

        $terminal = new FakeTerminal();
        $terminal->queueLine('acme');
        $terminal->queueKeys(KeyMap::ENTER);                         // workspace
        $terminal->queueLine('partner_client_id');                     // key（衝突する）
        $terminal->queueKeys(KeyMap::ENTER);                         // What now? -> Update the value
        $terminal->queueTyping('newsecret');                         // value
        $terminal->queueKeys(KeyMap::ENTER);
        $terminal->queueLine('n');                                   // もう1件? No
        $terminal->queueLine('y');                                   // 確認画面 Yes

        $exit = $this->command($transport, $terminal)->run();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('already exists', $terminal->output());

        $update = $transport->requests()[2];
        $this->assertSame('PATCH', $update->method);
        $this->assertStringContainsString('/vars/var-9', $update->url);
        $this->assertStringContainsString('"value":"newsecret"', (string) $update->body);
        // 属性は既存のまま
        $this->assertStringContainsString('"description":"既存の説明"', (string) $update->body);
        $this->assertStringContainsString('"sensitive":true', (string) $update->body);
    }

    public function testItCanUpdateTheAttributesToo(): void
    {
        $transport = new FakeTransport();
        $this->queueWorkspaces($transport);
        $transport->queue(200, json_encode([
            'data' => [[
                'id' => 'var-9',
                'attributes' => [
                    'key' => 'gtm_container_id',
                    'value' => 'GTM-OLD',
                    'category' => 'terraform',
                    'sensitive' => false,
                    'description' => '古い説明',
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE));
        $transport->queue(200, '{"data":{"id":"var-9","attributes":{"key":"gtm_container_id","category":"env","sensitive":false,"description":"新しい説明"}}}');

        $terminal = new FakeTerminal();
        $terminal->queueLine('acme');
        $terminal->queueKeys(KeyMap::ENTER);                         // workspace
        $terminal->queueLine('gtm_container_id');                    // key（衝突する）
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::ENTER);           // What now? -> Update the value and attributes
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::ENTER);           // category: env に変更
        $terminal->queueKeys(KeyMap::ENTER);                         // sensitive: No（既存のまま）
        $terminal->queueLine('GTM-NEW');                             // value（非 sensitive なので行入力）
        $terminal->queueLine('新しい説明');                            // description
        $terminal->queueLine('n');                                   // もう1件? No
        $terminal->queueLine('y');                                   // 確認画面 Yes

        $exit = $this->command($transport, $terminal)->run();

        $this->assertSame(0, $exit);
        $update = $transport->requests()[2];
        $this->assertStringContainsString('"category":"env"', (string) $update->body);
        $this->assertStringContainsString('"description":"新しい説明"', (string) $update->body);
        $this->assertStringContainsString('"value":"GTM-NEW"', (string) $update->body);
    }

    public function testSkipDropsTheVariableFromTheBatch(): void
    {
        $transport = new FakeTransport();
        $this->queueWorkspaces($transport);
        $transport->queue(200, json_encode([
            'data' => [[
                'id' => 'var-9',
                'attributes' => ['key' => 'taken', 'value' => 'v', 'category' => 'terraform', 'sensitive' => false, 'description' => ''],
            ]],
        ]));

        $terminal = new FakeTerminal();
        $terminal->queueLine('acme');
        $terminal->queueKeys(KeyMap::ENTER);                          // workspace
        $terminal->queueLine('taken');                               // key（衝突する）
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::DOWN, KeyMap::ENTER); // Skip this variable
        $terminal->queueLine('n');                                   // もう1件? No

        $exit = $this->command($transport, $terminal)->run();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('nothing to apply', $terminal->output());
        $this->assertCount(2, $transport->requests());               // 一覧2回だけ。書き込みなし
    }

    public function testUseADifferentKeyGoesBackToTheKeyPrompt(): void
    {
        $transport = new FakeTransport();
        $this->queueWorkspaces($transport);
        $transport->queue(200, json_encode([
            'data' => [[
                'id' => 'var-9',
                'attributes' => ['key' => 'taken', 'value' => 'v', 'category' => 'terraform', 'sensitive' => false, 'description' => ''],
            ]],
        ]));
        $transport->queue(201, '{"data":{"id":"var-new","attributes":{"key":"free","category":"terraform","sensitive":false,"description":""}}}');

        $terminal = new FakeTerminal();
        $terminal->queueLine('acme');
        $terminal->queueKeys(KeyMap::ENTER);                          // workspace
        $terminal->queueLine('taken');                               // key（衝突する）
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::DOWN, KeyMap::DOWN, KeyMap::ENTER); // Use a different key
        $terminal->queueLine('free');                                // 新しい key
        $terminal->queueKeys(KeyMap::ENTER);                         // category: terraform
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::ENTER);           // sensitive: No
        $terminal->queueLine('v');                                   // value
        $terminal->queueLine('');                                    // description 省略
        $terminal->queueLine('n');                                   // もう1件? No
        $terminal->queueLine('y');                                   // 確認画面 Yes

        $exit = $this->command($transport, $terminal)->run();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"key":"free"', (string) $transport->requests()[2]->body);
    }

    public function testAnsweringNoOnTheConfirmationSendsNothing(): void
    {
        $transport = new FakeTransport();
        $this->queueWorkspaces($transport);
        $transport->queue(200, '{"data":[]}');

        $terminal = new FakeTerminal();
        $terminal->queueLine('acme');
        $terminal->queueKeys(KeyMap::ENTER);
        $terminal->queueLine('k');
        $terminal->queueKeys(KeyMap::ENTER);                         // category
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::ENTER);           // sensitive: No
        $terminal->queueLine('v');                                   // value
        $terminal->queueLine('');                                    // description
        $terminal->queueLine('n');                                   // もう1件? No
        $terminal->queueLine('n');                                   // 確認画面 No

        $exit = $this->command($transport, $terminal)->run();

        $this->assertSame(0, $exit);
        $this->assertCount(2, $transport->requests());
    }

    public function testAFailedApplyExitsNonZero(): void
    {
        $transport = new FakeTransport();
        $this->queueWorkspaces($transport);
        $transport->queue(200, '{"data":[]}');
        $transport->queue(422, '{"errors":[{"detail":"Key has already been taken"}]}');

        $terminal = new FakeTerminal();
        $terminal->queueLine('acme');
        $terminal->queueKeys(KeyMap::ENTER);
        $terminal->queueLine('k');
        $terminal->queueKeys(KeyMap::ENTER);
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::ENTER);
        $terminal->queueLine('v');
        $terminal->queueLine('');
        $terminal->queueLine('n');
        $terminal->queueLine('y');

        $exit = $this->command($transport, $terminal)->run();

        $this->assertSame(1, $exit);
    }

    public function testTheOrganizationDefaultsToTheEnvironmentValue(): void
    {
        $transport = new FakeTransport();
        $this->queueWorkspaces($transport);
        $transport->queue(200, '{"data":[]}');

        $terminal = new FakeTerminal();
        $terminal->queueLine('');                                    // Enter で TFC_ORG を採用
        $terminal->queueKeys(KeyMap::ENTER);
        $terminal->queueLine('k');
        $terminal->queueKeys(KeyMap::ENTER);
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::ENTER);
        $terminal->queueLine('v');
        $terminal->queueLine('');
        $terminal->queueLine('n');
        $terminal->queueLine('n');

        $this->command($transport, $terminal, 'acme')->run();

        $this->assertStringContainsString('/organizations/acme/workspaces', $transport->requests()[0]->url);
    }

    public function testAnOrganizationWithNoWorkspacesFailsClearly(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, '{"data":[],"meta":{"pagination":{"next-page":null}}}');

        $terminal = new FakeTerminal();
        $terminal->queueLine('acme');

        $exit = $this->command($transport, $terminal)->run();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no workspaces', $terminal->errorOutput());
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
nix develop --command make test
```

Expected: FAIL。`Class "Tfcenv\Cli\AddCommand" not found`。

- [ ] **Step 3: AddCommand を実装する**

`src/Cli/AddCommand.php`:

```php
<?php

namespace Tfcenv\Cli;

use Tfcenv\Terminal\CancelledException;
use Tfcenv\Terminal\Picker;
use Tfcenv\Terminal\Prompt;
use Tfcenv\Terminal\Style;
use Tfcenv\Terminal\Terminal;
use Tfcenv\Tfc\Category;
use Tfcenv\Tfc\Variable;
use Tfcenv\Tfc\VariableRepository;
use Tfcenv\Tfc\Workspace;
use Tfcenv\Tfc\WorkspaceRepository;

final class AddCommand
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly VariableRepository $variables,
        private readonly Terminal $terminal,
        private readonly Style $style,
        private readonly Prompt $prompt,
        private readonly Picker $picker,
        private readonly Confirmation $confirmation,
        private readonly Applier $applier,
        private readonly string $defaultOrganization,
    ) {
    }

    public function run(): int
    {
        if (!$this->terminal->isTty()) {
            $this->terminal->writeError(
                "tfcenv add is interactive and needs a TTY.\n"
                . "Run it from a terminal, or wait for the JSON input mode.\n",
            );

            return 1;
        }

        try {
            return $this->interact();
        } catch (CancelledException $e) {
            $this->terminal->write("\n" . $this->style->dim('cancelled') . "\n");

            return 1;
        }
    }

    private function interact(): int
    {
        $organization = $this->prompt->text('Organization', $this->defaultOrganization);

        if ($organization === '') {
            $this->terminal->writeError("An organization is required.\n");

            return 1;
        }

        $workspace = $this->chooseWorkspace($organization);

        if ($workspace === null) {
            return 1;
        }

        $existing = $this->variables->listFor($workspace->id);
        $this->terminal->write($this->style->dim(sprintf(
            '  %d existing variable(s) in %s',
            count($existing),
            $workspace->name,
        )) . "\n");

        $changes = $this->collectChanges($existing);
        $set = new ChangeSet($organization, $workspace, $changes);

        if (!$this->confirmation->ask($set)) {
            return 0;
        }

        return $this->applier->apply($set)->hasFailures() ? 1 : 0;
    }

    private function chooseWorkspace(string $organization): ?Workspace
    {
        $workspaces = $this->workspaces->listAll($organization);

        if ($workspaces === []) {
            $this->terminal->writeError(sprintf(
                "The organization \"%s\" has no workspaces, or the token cannot see them.\n",
                $organization,
            ));

            return null;
        }

        $items = [];

        foreach ($workspaces as $workspace) {
            $items[$workspace->id] = $workspace->name;
        }

        $chosenId = $this->picker->pick('Workspace', $items);

        foreach ($workspaces as $workspace) {
            if ($workspace->id === $chosenId) {
                return $workspace;
            }
        }

        return null;
    }

    /**
     * @param array<string, Variable> $existing
     * @return Change[]
     */
    private function collectChanges(array $existing): array
    {
        $changes = [];

        while (true) {
            $change = $this->collectOne($existing);

            if ($change !== null) {
                $changes[] = $change;
            }

            if (!$this->prompt->confirm('Add another variable?', false)) {
                return $changes;
            }
        }
    }

    /**
     * 1件分を対話で組み立てる。Skip が選ばれたら null。
     *
     * @param array<string, Variable> $existing
     */
    private function collectOne(array $existing): ?Change
    {
        while (true) {
            $key = $this->prompt->text('Key');

            if ($key === '') {
                $this->terminal->write($this->style->dim('  a key is required') . "\n");
                continue;
            }

            if (!isset($existing[$key])) {
                return new Change(ChangeOp::Create, $this->askNewVariable($key));
            }

            $current = $existing[$key];
            $this->showCollision($current);

            $choice = $this->prompt->select('What now?', [
                'value' => 'Update the value',
                'attributes' => 'Update the value and attributes',
                'skip' => 'Skip this variable',
                'rename' => 'Use a different key',
            ]);

            if ($choice === 'skip') {
                return null;
            }

            if ($choice === 'rename') {
                continue;
            }

            if ($choice === 'value') {
                return new Change(ChangeOp::Update, $current->withValue($this->askValue($current->sensitive)));
            }

            return new Change(ChangeOp::Update, $this->askUpdatedVariable($current));
        }
    }

    private function showCollision(Variable $current): void
    {
        $this->terminal->write(sprintf(
            "\n  %s %s already exists\n      %s / %s\n",
            $this->style->warn('!'),
            $current->key,
            $current->category->value,
            $current->sensitive ? 'sensitive' : 'plain',
        ));

        if ($current->description !== '') {
            $this->terminal->write('      "' . $current->description . '"' . "\n");
        }

        $this->terminal->write("\n");
    }

    private function askNewVariable(string $key): Variable
    {
        $category = $this->askCategory(Category::Terraform);
        $sensitive = $this->askSensitive(true);
        $value = $this->askValue($sensitive);
        $description = $this->prompt->text('Description');

        return new Variable($key, $value, $category, $sensitive, $description);
    }

    private function askUpdatedVariable(Variable $current): Variable
    {
        $category = $this->askCategory($current->category);
        $sensitive = $this->askSensitive($current->sensitive);
        $value = $this->askValue($sensitive);
        $description = $this->prompt->text('Description', $current->description);

        return new Variable($current->key, $value, $category, $sensitive, $description, $current->id);
    }

    private function askCategory(Category $default): Category
    {
        $chosen = $this->prompt->select('Category', [
            Category::Terraform->value => Category::Terraform->value,
            Category::Env->value => Category::Env->value,
        ], $default->value);

        return Category::from($chosen);
    }

    private function askSensitive(bool $default): bool
    {
        $chosen = $this->prompt->select('Sensitive', [
            'yes' => 'Yes',
            'no' => 'No',
        ], $default ? 'yes' : 'no');

        return $chosen === 'yes';
    }

    /**
     * sensitive を先に聞いてから value を聞く。そうしないと
     * 非表示にすべきかどうかが決まらない。
     */
    private function askValue(bool $sensitive): string
    {
        if ($sensitive) {
            return $this->prompt->hidden('Value');
        }

        return $this->prompt->text('Value');
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
nix develop --command make test
```

Expected: PASS。

- [ ] **Step 5: コミット**

```bash
git add -A
git commit -m "Add the interactive add command

The loop asks for a key, checks it against the variables already in the
workspace, and offers four ways out of a collision. Update the value keeps the
existing category, sensitive flag and description; update the attributes walks
them with the existing values as defaults.

Sensitive is asked before value, because whether to hide the value input is
not knowable until the flag is set. The tests assert the entered secret never
reaches the output stream."
```

---

### Task 12: Application・main.php・AOT スモークテスト

コマンド振り分け、env 読み取り、依存の組み立て、`main()` の差し替え。

**Files:**
- Create: `src/Version.php`
- Create: `src/Cli/Application.php`
- Create: `tests/Cli/ApplicationTest.php`
- Modify: `src/main.php`（スモークテストを本物に差し替える）
- Modify: `Makefile`（`smoke` ターゲット）
- Modify: `README.md`（使い方）

**Interfaces:**
- Consumes: 前タスクのすべて
- Produces:
  - `Tfcenv\Version`: `public const STRING`
  - `Tfcenv\Cli\Application`: `__construct(private readonly Terminal $terminal)`、`run(int $argc, array $argv): int`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Cli/ApplicationTest.php`:

```php
<?php

namespace Tfcenv\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tfcenv\Cli\Application;
use Tfcenv\Tests\Support\FakeTerminal;
use Tfcenv\Version;

final class ApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('TFC_TOKEN');
        putenv('TFC_ORG');
        putenv('NO_COLOR');
    }

    public function testVersionPrintsTheVersion(): void
    {
        $terminal = new FakeTerminal();

        $exit = (new Application($terminal))->run(2, ['tfcenv', '--version']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString(Version::STRING, $terminal->output());
    }

    public function testHelpListsTheCommandAndTheEnvironmentVariables(): void
    {
        $terminal = new FakeTerminal();

        $exit = (new Application($terminal))->run(2, ['tfcenv', '--help']);

        $this->assertSame(0, $exit);
        $output = $terminal->output();
        $this->assertStringContainsString('tfcenv add', $output);
        $this->assertStringContainsString('TFC_TOKEN', $output);
        $this->assertStringContainsString('TFC_ORG', $output);
    }

    public function testNoArgumentsShowsHelpAndFails(): void
    {
        $terminal = new FakeTerminal();

        $exit = (new Application($terminal))->run(1, ['tfcenv']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('tfcenv add', $terminal->errorOutput());
    }

    public function testAnUnknownCommandFails(): void
    {
        $terminal = new FakeTerminal();

        $exit = (new Application($terminal))->run(2, ['tfcenv', 'delete']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('delete', $terminal->errorOutput());
    }

    public function testAddWithoutATokenExplainsHowToGetOne(): void
    {
        $terminal = new FakeTerminal();

        $exit = (new Application($terminal))->run(2, ['tfcenv', 'add']);

        $this->assertSame(1, $exit);
        $error = $terminal->errorOutput();
        $this->assertStringContainsString('TFC_TOKEN', $error);
        $this->assertStringContainsString('app.terraform.io/app/settings/tokens', $error);
    }

    public function testAddWithATokenButNoTtyFailsWithTheTtyMessage(): void
    {
        putenv('TFC_TOKEN=tok');
        $terminal = new FakeTerminal(false);

        $exit = (new Application($terminal))->run(2, ['tfcenv', 'add']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('TTY', $terminal->errorOutput());
    }

    public function testTheTokenIsNeverEchoed(): void
    {
        putenv('TFC_TOKEN=tok-do-not-leak');
        $terminal = new FakeTerminal(false);

        (new Application($terminal))->run(2, ['tfcenv', 'add']);

        $this->assertStringNotContainsString('tok-do-not-leak', $terminal->output());
        $this->assertStringNotContainsString('tok-do-not-leak', $terminal->errorOutput());
    }
}
```

- [ ] **Step 2: テストが落ちることを確認する**

```bash
nix develop --command make test
```

Expected: FAIL。`Class "Tfcenv\Cli\Application" not found`。

- [ ] **Step 3: Version と Application を実装する**

`src/Version.php`:

```php
<?php

namespace Tfcenv;

final class Version
{
    // グローバル定数ではなくクラス定数で持つ（TypePHP の制約）
    public const STRING = '0.1.0';
}
```

`src/Cli/Application.php`:

```php
<?php

namespace Tfcenv\Cli;

use Tfcenv\Terminal\Picker;
use Tfcenv\Terminal\Prompt;
use Tfcenv\Terminal\Style;
use Tfcenv\Terminal\Terminal;
use Tfcenv\Tfc\Client;
use Tfcenv\Tfc\CurlTransport;
use Tfcenv\Tfc\TfcException;
use Tfcenv\Tfc\VariableRepository;
use Tfcenv\Tfc\WorkspaceRepository;
use Tfcenv\Version;

final class Application
{
    private const TOKEN_URL = 'https://app.terraform.io/app/settings/tokens';

    public function __construct(
        private readonly Terminal $terminal,
    ) {
    }

    public function run(int $argc, array $argv): int
    {
        $command = $argc > 1 ? (string) $argv[1] : '';

        return match ($command) {
            '--version', '-v' => $this->printVersion(),
            '--help', '-h' => $this->printHelp(),
            'add' => $this->add(),
            '' => $this->usageError('tfcenv needs a command.'),
            default => $this->usageError(sprintf('Unknown command "%s".', $command)),
        };
    }

    private function printVersion(): int
    {
        $this->terminal->write('tfcenv ' . Version::STRING . "\n");

        return 0;
    }

    private function printHelp(): int
    {
        $this->terminal->write($this->helpText());

        return 0;
    }

    private function usageError(string $message): int
    {
        $this->terminal->writeError($message . "\n\n" . $this->helpText());

        return 1;
    }

    /**
     * ヒアドキュメントを使わず連結で組む。メソッド呼び出しの文字列補間は
     * TypePHP で検証済みの構文に含まれないため。
     */
    private function helpText(): string
    {
        return "tfcenv - register Terraform Cloud workspace variables from the CLI\n"
            . "\n"
            . "Usage:\n"
            . "  tfcenv add        register workspace variables interactively\n"
            . "  tfcenv --help\n"
            . "  tfcenv --version\n"
            . "\n"
            . "Environment:\n"
            . '  TFC_TOKEN         required. Create one at ' . self::TOKEN_URL . "\n"
            . "  TFC_ORG           optional. Default organization\n"
            . "  NO_COLOR          optional. Set to disable ANSI colour\n";
    }

    private function add(): int
    {
        $token = (string) getenv('TFC_TOKEN');

        if ($token === '') {
            $this->terminal->writeError(
                "TFC_TOKEN is not set.\n"
                . 'Create a user token at ' . self::TOKEN_URL . " and export it:\n"
                . "  export TFC_TOKEN=...\n",
            );

            return 1;
        }

        $organization = (string) getenv('TFC_ORG');
        $noColor = getenv('NO_COLOR');

        $style = Style::detect($this->terminal, $noColor === false ? null : (string) $noColor);
        $prompt = new Prompt($this->terminal, $style);
        $client = new Client(new CurlTransport(), $token);
        $variables = new VariableRepository($client);

        $command = new AddCommand(
            new WorkspaceRepository($client),
            $variables,
            $this->terminal,
            $style,
            $prompt,
            new Picker($this->terminal, $style),
            new Confirmation($this->terminal, $style, $prompt),
            new Applier($variables, $this->terminal, $style),
            $organization,
        );

        try {
            return $command->run();
        } catch (TfcException $e) {
            $this->terminal->writeError($e->getMessage() . "\n");

            return 1;
        }
    }
}
```

- [ ] **Step 4: テストが通ることを確認する**

```bash
nix develop --command make test
```

Expected: PASS。

- [ ] **Step 5: main.php を本物に差し替える**

`src/main.php` の内容をすべて置き換える。

```php
<?php

use Tfcenv\Cli\Application;
use Tfcenv\Terminal\SttyTerminal;

/**
 * TypePHP の binary モードはグローバルな main() を要求する。
 * シグネチャは固定で、返り値は void なので終了コードは exit() で返す。
 */
function main(int $argc, array $argv): void
{
    exit((new Application(new SttyTerminal()))->run($argc, $argv));
}
```

- [ ] **Step 6: AOT スモークテストを Makefile に足す**

`.PHONY` の行に `smoke` を追加し、`test` ターゲットの下に足す。

```make
## smoke - check the compiled binary starts and answers --version / --help
smoke: $(TARGET)
	@./$(TARGET) --version | grep -q "^tfcenv " || { echo "smoke: --version failed"; exit 1; }
	@./$(TARGET) --help | grep -q "tfcenv add" || { echo "smoke: --help failed"; exit 1; }
	@./$(TARGET) nonsense >/dev/null 2>&1 && { echo "smoke: unknown command should fail"; exit 1; } || true
	@echo "smoke: ok"
```

- [ ] **Step 7: インタプリタとバイナリの両方で動作を確認する**

```bash
nix develop --command make test
nix develop --command make run ARGS="--version"
nix develop --command make run ARGS="--help"
nix develop --command make build
nix develop --command make smoke
```

Expected: すべて成功し、最後に `smoke: ok`。
`make build` が落ちる場合は Global Constraints の TypePHP 制約に違反している箇所がある。

- [ ] **Step 8: 実際の Terraform Cloud に対して手で通す**

```bash
export TFC_TOKEN=...      # https://app.terraform.io/app/settings/tokens
export TFC_ORG=acme
./tfcenv add
```

確認すること:

- ワークスペースの絞り込みが効き、矢印キーで選べる
- 既存キーを入れると衝突が検出され、4つの選択肢が出る
- `Update the value` が value だけを聞く
- `Update the value and attributes` が既存値をデフォルトに入れて全属性を聞く
- **`sensitive: true` の変数を `Update the value and attributes` で `No` にできるか**
  （spec の「実装時に確認する事項」。422 が返るなら spec を更新して
  「TFC では sensitive を解除できない」と明記する）
- 確認画面で `n` を選ぶと1件も送信されない
- sensitive な値がどこにも表示されない
- `Ctrl-C` で抜けたあとシェルのエコーが戻っている

- [ ] **Step 9: README を更新する**

`README.md` の `> Status: development environment only. The CLI itself is not implemented yet.` を消し、
`## Development shell` の前に使い方の節を足す。

```markdown
## Usage

```console
$ export TFC_TOKEN=...        # https://app.terraform.io/app/settings/tokens
$ export TFC_ORG=acme    # optional default
$ tfcenv add
```

`tfcenv add` walks you through picking a workspace and then registering one or
more variables. Keys that already exist are detected before anything is sent,
and offered as an update, a skip, or a different key. Nothing is written until
you confirm.
```

- [ ] **Step 10: コミット**

```bash
git add -A
git commit -m "Wire up the application entry point

Application is the composition root: it reads the environment, builds the
object graph and owns the top-level error boundary, so main.php stays two
lines. The token is read here and passed to Client; nothing prints it.

make smoke exercises the compiled binary rather than the interpreter, which is
the only place the TypePHP constraints actually bite."
```

---

## Self-Review

**1. Spec coverage**

| Spec の節 | 実装するタスク |
|---|---|
| コマンド（`add` / `--help` / `--version`、終了コード） | Task 12 |
| 環境変数（`TFC_TOKEN` / `TFC_ORG` / `NO_COLOR`） | Task 12（`NO_COLOR` の判定は Task 6 の `Style::detect`） |
| 使用する TFC API（4エンドポイント・ページング・`vnd.api+json`） | Task 2（ヘッダと URL）/ Task 4（ページング）/ Task 5（vars の4操作） |
| `sensitive` は読み戻せない | Task 1（`Variable::fromApi`）/ Task 5 |
| 実装時に確認する事項（sensitive を false に戻せるか） | Task 12 Step 8 |
| フロー 1（`TFC_TOKEN` 確認） | Task 12 |
| フロー 2（TTY 判定） | Task 11 |
| フロー 3（organization、`TFC_ORG` 既定） | Task 11 |
| フロー 4（ワークスペース一覧＋絞り込みピッカー） | Task 4 / Task 9 / Task 11 |
| フロー 5（既存変数の取得） | Task 5 / Task 11 |
| フロー 6（変数ループ・衝突4分岐） | Task 11 |
| フロー 7（確認画面・マスク） | Task 10 |
| フロー 8（1件ずつ適用・失敗しても継続） | Task 10 |
| フロー 9（集計・失敗があれば exit 1） | Task 10 / Task 11 |
| 再実行の安全性 | Task 11（毎回 `listFor` を取り直す） |
| 入力順序（sensitive → value） | Task 11 `askValue()` |
| 衝突時の更新2段構え | Task 11 |
| ピッカーのキー割り当て・`ESC [ A` | Task 6（`KeyMap`）/ Task 7（`readKey`）/ Task 9 |
| 中間表現 | Task 10（`ChangeSet`） |
| モジュール構成 | 全タスク |
| TypePHP 制約の反映 | Global Constraints、各タスクの AOT ビルド確認 |
| エラーハンドリング（`TfcException`・トークン非表示・マスク） | Task 1 / Task 2 / Task 10 / Task 12 |
| Ctrl-C 対策 | Task 6 / Task 7 / Task 8 / Task 9（`pcntl` は不要と判明。「Spec との差分」参照） |
| テスト戦略（PHPUnit・フェイク2つ・422/401/ページング・スモーク） | Task 1（基盤）/ Task 2（`FakeTransport`）/ Task 6（`FakeTerminal`）/ Task 12（スモーク） |
| P3 に引き渡す未解決事項 | 本計画の対象外。`pcntl` が落ちたので拡張は `curl` と `mbstring` のみ |

未カバーの spec 要件は無い。

**2. Placeholder scan**

「TBD」「TODO」「後で実装」「適切なエラー処理を追加」といった記述、およびコードブロックのないコード手順は無い。全タスクにテストコードと実装コードの実体がある。

**3. Type consistency**

- `Variable` のメソッド名は `payload()` / `updateDocument()` / `withValue()` / `withId()` / `displayValue()` / `fromApi()` で、Task 1 の定義と Task 5・10・11 の呼び出しが一致している
- `TfcException::status()` は Task 1 で定義し、Task 2・3 のテストが使っている
- `HttpRequest` の公開プロパティは `method` / `url` / `headers` / `body`。Task 2 で定義し、Task 3 の `CurlTransport` と Task 2・4・5・11 のテストが同じ名前で読んでいる
- `Terminal` の8メソッド（`isTty` / `width` / `write` / `writeError` / `readLine` / `readKey` / `enterRawMode` / `restoreMode`）は Task 6 の interface、Task 6 の `FakeTerminal`、Task 7 の `SttyTerminal` で同一
- `KeyMap` の定数名（`UP` / `DOWN` / `ENTER` / `BACKSPACE` / `CANCEL` / `EOF` / `UNKNOWN`）は Task 6 で定義し、Task 7・8・9・11 が参照
- `Prompt` は `text()` / `hidden()` / `confirm()` / `select()`。Task 8 で定義し、Task 10（`Confirmation`）と Task 11 が使う
- `Picker::pick()` の引数は `(string $label, array $items)` で `value => label`。Task 9 で定義し Task 11 が使う
- `ChangeSet` の `countOf()`（`countBy()` ではない）、`ApplyResult` の `hasFailures()` は Task 10 で定義し Task 11 が使う
- `Style` は `accent` / `ok` / `bad` / `warn` / `dim` / `bold` の6メソッド。Task 6 で定義し、Task 8・9・10・11 が使う

不整合は見つからなかった。

---

## 実装後に spec を直すこと

1. 「Ctrl-C 対策」の節を書き換える。`pcntl` は不要で、`stty raw` が `isig` を落とすため Ctrl-C はバイトとして届く。P1 が必要とする拡張は `curl` と `mbstring` の2つ。
2. 「P3 に引き渡す未解決事項」の拡張一覧から `pcntl` を削る。
3. 「モジュール構成」に本計画で追加したファイルを反映する。
4. Task 12 Step 8 で分かった「sensitive を false に戻せるか」の結論を「実装時に確認する事項」に書く。
