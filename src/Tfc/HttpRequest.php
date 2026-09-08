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
