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
