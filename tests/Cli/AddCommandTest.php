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

    public function testItRefusesAKeyAlreadyAddedInThisSession(): void
    {
        $transport = new FakeTransport();
        $this->queueWorkspaces($transport);
        $transport->queue(200, '{"data":[]}');

        $terminal = new FakeTerminal();
        $terminal->queueLine('acme');
        $terminal->queueKeys(KeyMap::ENTER);                         // workspace
        $terminal->queueLine('dup');                                 // 1件目
        $terminal->queueKeys(KeyMap::ENTER);                         // category
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::ENTER);           // sensitive: No
        $terminal->queueLine('v1');                                  // value
        $terminal->queueLine('');                                    // description
        $terminal->queueLine('y');                                   // もう1件?
        $terminal->queueLine('dup');                                 // 同じキー → 拒否される
        $terminal->queueLine('other');                               // 別のキーで続行
        $terminal->queueKeys(KeyMap::ENTER);                         // category
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::ENTER);           // sensitive: No
        $terminal->queueLine('v2');                                  // value
        $terminal->queueLine('');                                    // description
        $terminal->queueLine('n');                                   // もう1件? No
        $terminal->queueLine('n');                                   // 確認画面 No

        $exit = $this->command($transport, $terminal)->run();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('already added in this session', $terminal->output());
        $this->assertCount(2, $transport->requests());               // 一覧2回のみ、書き込みなし
    }

    public function testItRefusesToApplyWhenInputEndsAtTheGate(): void
    {
        // 入力が途切れただけで Terraform Cloud に書き込まれてはいけない。
        $transport = new FakeTransport();
        $this->queueWorkspaces($transport);
        $transport->queue(200, json_encode([
            'data' => [[
                'id' => 'var-9',
                'attributes' => [
                    'key' => 'GTM_ID',
                    'value' => 'GTM-XXXXXXX',
                    'category' => 'terraform',
                    'sensitive' => false,
                    'description' => 'live',
                ],
            ]],
        ]));

        $terminal = new FakeTerminal();
        $terminal->queueLine('acme');
        $terminal->queueKeys(KeyMap::ENTER);   // workspace
        $terminal->queueLine('GTM_ID');        // 既存キー
        $terminal->queueKeys(KeyMap::ENTER);   // What now? -> Update the value
        $terminal->queueLine('newvalue');      // value（非 sensitive なので行入力）
        $terminal->queueEof();                 // ここで入力が途切れる

        $this->command($transport, $terminal)->run();

        // 終了コードだけを見てはいけない。早期 return のバグでも 0 になる。
        foreach ($transport->requests() as $request) {
            $this->assertNotSame('POST', $request->method, 'nothing may be created when input ends');
            $this->assertNotSame('PATCH', $request->method, 'nothing may be updated when input ends');
        }
    }

    public function testItGivesUpAfterRepeatedEmptyKeys(): void
    {
        $transport = new FakeTransport();
        $this->queueWorkspaces($transport);
        $transport->queue(200, '{"data":[]}');

        $terminal = new FakeTerminal();
        $terminal->queueLine('acme');
        $terminal->queueKeys(KeyMap::ENTER);                         // workspace
        $terminal->queueLine('');
        $terminal->queueLine('');
        $terminal->queueLine('');

        $exit = $this->command($transport, $terminal)->run();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('cancelled', $terminal->output());
    }
}
