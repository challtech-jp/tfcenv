<?php

namespace Tfcenv\Tests\Terminal;

use PHPUnit\Framework\TestCase;
use Tfcenv\Terminal\SttyTerminal;

final class SttyTerminalTest extends TestCase
{
    public function testTheFunctionsItNeedsAreAllAvailable(): void
    {
        // readline と posix_isatty は拡張なので AOT バイナリには無い。
        // 使っていないことをここで固定する。
        foreach (['shell_exec', 'fread', 'fgets', 'stream_isatty', 'stream_set_blocking'] as $function) {
            $this->assertTrue(function_exists($function), $function . ' is required');
        }
    }

    public function testItReportsAWidth(): void
    {
        $terminal = new SttyTerminal();

        $this->assertGreaterThan(0, $terminal->width());
    }

    public function testIsTtyDoesNotThrowUnderTheTestRunner(): void
    {
        $terminal = new SttyTerminal();

        // PHPUnit 下では TTY でないことが多い。値ではなく型だけ確かめる。
        $this->assertIsBool($terminal->isTty());
    }

    public function testRestoringWithoutEnteringIsHarmless(): void
    {
        $terminal = new SttyTerminal();

        $terminal->restoreMode();
        $terminal->restoreMode();

        $this->assertTrue(true);
    }

    public function testItRefusesToEnterRawModeWhenSttyCannotReportTheMode(): void
    {
        // Under the test runner STDIN is not a tty, so `stty -g` fails and there is
        // no safe way into raw mode. Failing loudly matters because a silent
        // fall-through would leave echo on while Prompt::hidden() reads a secret.
        $terminal = new SttyTerminal();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be switched to raw mode safely');

        $terminal->enterRawMode();
    }
}
