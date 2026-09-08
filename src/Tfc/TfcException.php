<?php

namespace Tfcenv\Tfc;

final class TfcException extends \Exception
{
    public static function fromResponse(int $status, string $body): self
    {
        $details = self::details($body);

        if ($details === '') {
            return new self(sprintf('Terraform Cloud returned HTTP %d', $status), $status);
        }

        return new self(sprintf('Terraform Cloud returned HTTP %d: %s', $status, $details), $status);
    }

    public static function fromTransport(string $detail): self
    {
        return new self(sprintf('Could not reach Terraform Cloud: %s', $detail), 0);
    }

    public function status(): int
    {
        return (int) $this->getCode();
    }

    /**
     * JSON:API のエラー配列から detail を取り出して連結する。
     * JSON:API でない本文（HTML のエラーページなど）は捨てる。
     */
    private static function details(string $body): string
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || !isset($decoded['errors']) || !is_array($decoded['errors'])) {
            return '';
        }

        $messages = [];

        foreach ($decoded['errors'] as $error) {
            if (!is_array($error)) {
                continue;
            }
            $text = $error['detail'] ?? $error['title'] ?? null;
            if (is_string($text) && $text !== '') {
                $messages[] = $text;
            }
        }

        return implode('; ', $messages);
    }
}
