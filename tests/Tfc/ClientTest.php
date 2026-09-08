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
