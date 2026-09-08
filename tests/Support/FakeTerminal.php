<?php

namespace Tfcenv\Tests\Support;

use Tfcenv\Terminal\KeyMap;
use Tfcenv\Terminal\Terminal;

/**
 * 入力をキューで流し、出力を文字列に溜める Terminal。
 * これと FakeTransport の2つで対話フロー全体を駆動できる。
 */
final class FakeTerminal implements Terminal
{
    /** @var string[] */
    private array $lines = [];

    /** @var string[] */
    private array $keys = [];

    private string $output = '';
    private string $errorOutput = '';
    private int $entered = 0;
    private int $restored = 0;

    public function __construct(
        private readonly bool $tty = true,
    ) {
    }

    public function queueLine(string $line): void
    {
        $this->lines[] = $line;
    }

    public function queueKeys(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->keys[] = $key;
        }
    }

    /** 1文字ずつのキー列として文字列を流し込む */
    public function queueTyping(string $text): void
    {
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $this->keys[] = $text[$i];
        }
    }

    public function isTty(): bool
    {
        return $this->tty;
    }

    public function width(): int
    {
        return 80;
    }

    public function write(string $text): void
    {
        $this->output .= $text;
    }

    public function writeError(string $text): void
    {
        $this->errorOutput .= $text;
    }

    public function readLine(): string
    {
        if ($this->lines === []) {
            return '';
        }

        return array_shift($this->lines);
    }

    public function readKey(): string
    {
        if ($this->keys === []) {
            return KeyMap::EOF;
        }

        return array_shift($this->keys);
    }

    public function enterRawMode(): void
    {
        $this->entered++;
    }

    public function restoreMode(): void
    {
        $this->restored++;
    }

    public function output(): string
    {
        return $this->output;
    }

    public function errorOutput(): string
    {
        return $this->errorOutput;
    }

    public function rawModeEntered(): int
    {
        return $this->entered;
    }

    public function rawModeRestored(): int
    {
        return $this->restored;
    }
}
