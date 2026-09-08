<?php

namespace Tfcenv\Terminal;

interface Terminal
{
    public function isTty(): bool;

    /** 端末の桁数。取得できないときは 80 */
    public function width(): int;

    public function write(string $text): void;

    public function writeError(string $text): void;

    /** 行入力（cooked モード）。EOF なら空文字列 */
    public function readLine(): string;

    /**
     * raw モードでの1キー入力。KeyMap の定数か印字可能な1文字を返す。
     * 呼ぶ前に enterRawMode() しておくこと。
     */
    public function readKey(): string;

    public function enterRawMode(): void;

    public function restoreMode(): void;
}
