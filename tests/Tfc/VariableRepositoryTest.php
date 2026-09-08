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

    public function testCreateRefusesToConfirmAResponseWithoutAVariable(): void
    {
        $transport = new FakeTransport();
        $transport->queue(201, '{"meta":{}}');
        $repository = new VariableRepository(new Client($transport, 'tok'));
        $variable = new Variable('k', 'v', Category::Terraform, false, '');

        $this->expectException(TfcException::class);
        $this->expectExceptionMessage('cannot be confirmed');

        $repository->create('ws-1', $variable);
    }

    public function testUpdateRefusesToConfirmAResponseWithoutAVariable(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, '{}');
        $repository = new VariableRepository(new Client($transport, 'tok'));
        $variable = new Variable('k', 'v', Category::Terraform, false, '', 'var-1');

        $this->expectException(TfcException::class);
        $this->expectExceptionMessage('cannot be confirmed');

        $repository->update('ws-1', $variable);
    }

    public function testListForSkipsAResourceWithoutAKey(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, json_encode([
            'data' => [
                ['id' => 'var-1', 'attributes' => ['key' => 'good', 'value' => 'v', 'category' => 'terraform', 'sensitive' => false, 'description' => '']],
                ['id' => 'var-2', 'attributes' => ['key' => '', 'value' => 'v', 'category' => 'terraform', 'sensitive' => false, 'description' => '']],
                ['id' => 'var-3', 'attributes' => ['value' => 'v', 'category' => 'terraform', 'sensitive' => false, 'description' => '']],
            ],
        ]));
        $repository = new VariableRepository(new Client($transport, 'tok'));

        $existing = $repository->listFor('ws-1');

        $this->assertCount(1, $existing);
        $this->assertArrayHasKey('good', $existing);
    }
}
