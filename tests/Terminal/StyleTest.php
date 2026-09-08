<?php

namespace Tfcenv\Tests\Terminal;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tests\Support\FakeTerminal;
use Tfcenv\Terminal\Style;

final class StyleTest extends TestCase
{
    public function testItEmitsAnsiWhenEnabled(): void
    {
        $style = new Style(true);

        $this->assertStringContainsString("\e[", $style->accent('x'));
        $this->assertStringContainsString('x', $style->accent('x'));
        $this->assertStringEndsWith("\e[0m", $style->accent('x'));
    }

    public function testItPassesTextThroughWhenDisabled(): void
    {
        $style = new Style(false);

        $this->assertSame('x', $style->accent('x'));
        $this->assertSame('x', $style->ok('x'));
        $this->assertSame('x', $style->bad('x'));
        $this->assertSame('x', $style->warn('x'));
        $this->assertSame('x', $style->dim('x'));
        $this->assertSame('x', $style->bold('x'));
    }

    public function testDetectEnablesColourOnATty(): void
    {
        $style = Style::detect(new FakeTerminal(true), null);

        $this->assertStringContainsString("\e[", $style->accent('x'));
    }

    public function testDetectDisablesColourWhenNotATty(): void
    {
        $style = Style::detect(new FakeTerminal(false), null);

        $this->assertSame('x', $style->accent('x'));
    }

    public function testDetectRespectsNoColor(): void
    {
        // NO_COLOR は「設定されていれば」有効。空文字列でも設定扱い。
        $this->assertSame('x', Style::detect(new FakeTerminal(true), '')->accent('x'));
        $this->assertSame('x', Style::detect(new FakeTerminal(true), '1')->accent('x'));
    }
}
