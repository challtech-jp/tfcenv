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
