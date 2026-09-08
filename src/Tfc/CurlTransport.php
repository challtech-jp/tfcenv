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
