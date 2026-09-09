<?php

namespace Tfcenv\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tfcenv\Cli\AddOptions;
use Tfcenv\Cli\Applier;
use Tfcenv\Cli\DirectAddCommand;
use Tfcenv\Terminal\Style;
use Tfcenv\Tests\Support\FakeTerminal;
use Tfcenv\Tests\Support\FakeTransport;
use Tfcenv\Tfc\Client;
use Tfcenv\Tfc\VariableRepository;
use Tfcenv\Tfc\WorkspaceRepository;

final class DirectAddCommandTest extends TestCase
{
    private FakeTransport $transport;
    private FakeTerminal $terminal;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->terminal = new FakeTerminal(false);
    }

    public function testItCreatesAVariableWithTheDefaultsForAnUnspecifiedAttribute(): void
    {
        $this->queueWorkspace();
        $this->queueExistingVariables([]);
        $this->transport->queue(201, $this->variableDocument('var-1', 'GTM_ID'));

        $exit = $this->execute(['-w', 'alpha-core-stg', 'GTM_ID=GTM-XXXXXXX']);

        $this->assertSame(0, $exit);
        $attributes = $this->sentAttributes();
        $this->assertSame('GTM_ID', $attributes['key']);
        $this->assertSame('GTM-XXXXXXX', $attributes['value']);
        $this->assertSame('terraform', $attributes['category']);
        $this->assertFalse($attributes['sensitive']);
        $this->assertSame('', $attributes['description']);
        $this->assertStringContainsString('create GTM_ID', $this->terminal->output());
    }

    public function testItSendsTheAttributeFlagsItWasGiven(): void
    {
        $this->queueWorkspace();
        $this->queueExistingVariables([]);
        $this->transport->queue(201, $this->variableDocument('var-1', 'PARTNER_TOKEN'));

        $exit = $this->execute(['-w', 'stg', '-c', 'env', '-s', '-d', '連携先連携', 'PARTNER_TOKEN=xxxx']);

        $this->assertSame(0, $exit);
        $attributes = $this->sentAttributes();
        $this->assertSame('env', $attributes['category']);
        $this->assertTrue($attributes['sensitive']);
        $this->assertSame('連携先連携', $attributes['description']);
    }

    public function testAnExistingKeyWithoutUpdateSendsNothing(): void
    {
        $this->queueWorkspace();
        $this->queueExistingVariables([['var-9', 'GTM_ID', 'terraform', false, 'live']]);

        $exit = $this->execute(['-w', 'alpha-core-stg', 'GTM_ID=GTM-NEW']);

        $this->assertSame(1, $exit);
        $this->assertCount(2, $this->transport->requests());
        $this->assertSame('GET', $this->transport->lastRequest()->method);
        $error = $this->terminal->errorOutput();
        $this->assertStringContainsString('GTM_ID already exists', $error);
        $this->assertStringContainsString('--update', $error);
    }

    public function testUpdateInheritsTheAttributesThatWereNotGiven(): void
    {
        $this->queueWorkspace();
        $this->queueExistingVariables([['var-9', 'DB_PASSWORD', 'env', true, '本番DB']]);
        $this->transport->queue(200, $this->variableDocument('var-9', 'DB_PASSWORD'));

        $exit = $this->execute(['-w', 'stg', '-u', 'DB_PASSWORD=new-secret']);

        $this->assertSame(0, $exit);
        $request = $this->transport->lastRequest();
        $this->assertSame('PATCH', $request->method);
        $this->assertStringEndsWith('/workspaces/ws-7/vars/var-9', $request->url);

        $attributes = $this->sentAttributes();
        $this->assertSame('new-secret', $attributes['value']);
        $this->assertSame('env', $attributes['category']);
        $this->assertTrue($attributes['sensitive'], 'sensitive must be inherited or TFC answers 422');
        $this->assertSame('本番DB', $attributes['description']);
    }

    public function testUpdateOverridesOnlyTheAttributesThatWereGiven(): void
    {
        $this->queueWorkspace();
        $this->queueExistingVariables([['var-9', 'LOG_LEVEL', 'terraform', false, 'old']]);
        $this->transport->queue(200, $this->variableDocument('var-9', 'LOG_LEVEL'));

        $exit = $this->execute(['-w', 'stg', '-u', '-c', 'env', '-s', 'LOG_LEVEL=info']);

        $this->assertSame(0, $exit);
        $attributes = $this->sentAttributes();
        $this->assertSame('env', $attributes['category']);
        $this->assertTrue($attributes['sensitive']);
        $this->assertSame('old', $attributes['description']);
    }

    public function testUpdateOnAKeyThatDoesNotExistCreatesIt(): void
    {
        $this->queueWorkspace();
        $this->queueExistingVariables([]);
        $this->transport->queue(201, $this->variableDocument('var-1', 'NEW_KEY'));

        $exit = $this->execute(['-w', 'stg', '-u', 'NEW_KEY=1']);

        $this->assertSame(0, $exit);
        $this->assertSame('POST', $this->transport->lastRequest()->method);
        $this->assertStringContainsString('create NEW_KEY', $this->terminal->output());
    }

    public function testAnUnknownWorkspaceSendsNothingAndNamesIt(): void
    {
        $this->transport->queue(404, '{"errors":[{"detail":"not found"}]}');

        $exit = $this->execute(['-w', 'no-such-ws', 'KEY=v']);

        $this->assertSame(1, $exit);
        $this->assertCount(1, $this->transport->requests());
        $error = $this->terminal->errorOutput();
        $this->assertStringContainsString('no-such-ws', $error);
        $this->assertStringContainsString('acme', $error);
    }

    public function testItNeedsNoTty(): void
    {
        $this->assertFalse($this->terminal->isTty());
        $this->queueWorkspace();
        $this->queueExistingVariables([]);
        $this->transport->queue(201, $this->variableDocument('var-1', 'KEY'));

        $this->assertSame(0, $this->execute(['-w', 'stg', 'KEY=v']));
    }

    public function testASensitiveValueIsNeverWrittenToTheTerminal(): void
    {
        $this->queueWorkspace();
        $this->queueExistingVariables([]);
        $this->transport->queue(422, '{"errors":[{"detail":"rejected value hunter2"}]}');

        $exit = $this->execute(['-w', 'stg', '-s', 'DB_PASSWORD=hunter2']);

        $this->assertSame(1, $exit);
        $this->assertStringNotContainsString('hunter2', $this->terminal->output());
        $this->assertStringNotContainsString('hunter2', $this->terminal->errorOutput());
    }

    public function testAFailedWriteExitsNonZero(): void
    {
        $this->queueWorkspace();
        $this->queueExistingVariables([]);
        $this->transport->queue(422, '{"errors":[{"detail":"nope"}]}');

        $this->assertSame(1, $this->execute(['-w', 'stg', 'KEY=v']));
    }

    /**
     * @param string[] $args
     */
    private function execute(array $args): int
    {
        $options = AddOptions::parse($args, 'acme');
        $client = new Client($this->transport, 'tok');
        $variables = new VariableRepository($client);
        $style = Style::detect($this->terminal, '1');

        $command = new DirectAddCommand(
            new WorkspaceRepository($client),
            $variables,
            $this->terminal,
            new Applier($variables, $this->terminal, $style),
        );

        return $command->run($options);
    }

    private function queueWorkspace(): void
    {
        $this->transport->queue(200, (string) json_encode([
            'data' => ['id' => 'ws-7', 'type' => 'workspaces', 'attributes' => ['name' => 'alpha-core-stg']],
        ]));
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string, 3: bool, 4: string}> $rows
     */
    private function queueExistingVariables(array $rows): void
    {
        $data = [];

        foreach ($rows as $row) {
            $data[] = [
                'id' => $row[0],
                'type' => 'vars',
                'attributes' => [
                    'key' => $row[1],
                    'value' => '',
                    'category' => $row[2],
                    'sensitive' => $row[3],
                    'description' => $row[4],
                ],
            ];
        }

        $this->transport->queue(200, (string) json_encode(['data' => $data]));
    }

    private function variableDocument(string $id, string $key): string
    {
        return (string) json_encode([
            'data' => [
                'id' => $id,
                'type' => 'vars',
                'attributes' => ['key' => $key, 'category' => 'terraform', 'sensitive' => false],
            ],
        ]);
    }

    private function sentAttributes(): array
    {
        $body = (string) $this->transport->lastRequest()->body;
        $decoded = json_decode($body, true);

        return $decoded['data']['attributes'];
    }
}
