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
