<?php

namespace Tfcenv\Terminal;

interface Terminal
{
    public function isTty(): bool;

    /** 端末の桁数。取得できないときは 80 */
    public function width(): int;

    public function write(string $text): void;

    public function writeError(string $text): void;

    /**
     * 行入力（cooked モード）。EOF なら null。
     *
     * '' と null を分けるのが要点。端末は「空行で Enter」のとき改行をエコーするが
     * EOF のときはしない。両方を '' で返すと、呼び手は畳んでよいかも、続行してよいかも
     * 判断できない。
     */
    public function readLine(): ?string;

    /**
     * raw モードでの1キー入力。KeyMap の定数か印字可能な1文字を返す。
     * 呼ぶ前に enterRawMode() しておくこと。
     */
    public function readKey(): string;

    public function enterRawMode(): void;

    public function restoreMode(): void;
}
