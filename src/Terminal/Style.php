<?php

namespace Tfcenv\Terminal;

final class Style
{
    private const RESET = "\e[0m";

    public function __construct(
        private readonly bool $enabled,
    ) {
    }

    /**
     * 非 TTY か NO_COLOR が設定されていれば色を切る。
     * NO_COLOR は「設定されているか」だけを見る規約なので、空文字列でも無効化する。
     */
    public static function detect(Terminal $terminal, ?string $noColor): self
    {
        return new self($terminal->isTty() && $noColor === null);
    }

    public function accent(string $text): string
    {
        return $this->wrap("\e[35m", $text);
    }

    public function ok(string $text): string
    {
        return $this->wrap("\e[32m", $text);
    }

    public function bad(string $text): string
    {
        return $this->wrap("\e[31m", $text);
    }

    public function warn(string $text): string
    {
        return $this->wrap("\e[33m", $text);
    }

    public function dim(string $text): string
    {
        return $this->wrap("\e[2m", $text);
    }

    public function bold(string $text): string
    {
        return $this->wrap("\e[1m", $text);
    }

    private function wrap(string $code, string $text): string
    {
        if (!$this->enabled) {
            return $text;
        }

        return $code . $text . self::RESET;
    }
}
