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
