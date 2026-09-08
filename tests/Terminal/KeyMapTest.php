<?php

namespace Tfcenv\Tests\Terminal;

use PHPUnit\Framework\TestCase;
use Tfcenv\Terminal\KeyMap;

final class KeyMapTest extends TestCase
{
    public function testArrowKeysArriveAsThreeByteEscapeSequences(): void
    {
        $this->assertSame(KeyMap::UP, KeyMap::fromBytes("\e[A"));
        $this->assertSame(KeyMap::DOWN, KeyMap::fromBytes("\e[B"));
    }

    public function testEmacsStyleMovementKeys(): void
    {
        $this->assertSame(KeyMap::UP, KeyMap::fromBytes("\x10"));
        $this->assertSame(KeyMap::DOWN, KeyMap::fromBytes("\x0e"));
    }

    public function testEnterArrivesAsCarriageReturnInRawMode(): void
    {
        $this->assertSame(KeyMap::ENTER, KeyMap::fromBytes("\r"));
        $this->assertSame(KeyMap::ENTER, KeyMap::fromBytes("\n"));
    }

    public function testBackspaceArrivesAsDeleteOrBackspace(): void
    {
        $this->assertSame(KeyMap::BACKSPACE, KeyMap::fromBytes("\x7f"));
        $this->assertSame(KeyMap::BACKSPACE, KeyMap::fromBytes("\x08"));
    }

    public function testCtrlCIsAByteInRawModeNotASignal(): void
    {
        // stty raw は isig を落とすので、Ctrl-C は SIGINT にならず 0x03 として届く。
        $this->assertSame(KeyMap::CANCEL, KeyMap::fromBytes("\x03"));
    }

    public function testAnEmptyReadIsEof(): void
    {
        $this->assertSame(KeyMap::EOF, KeyMap::fromBytes(''));
    }

    public function testPrintableCharactersPassThrough(): void
    {
        $this->assertSame('b', KeyMap::fromBytes('b'));
        $this->assertSame('_', KeyMap::fromBytes('_'));
        $this->assertSame('-', KeyMap::fromBytes('-'));
    }

    public function testUnknownEscapeSequencesAreIgnorable(): void
    {
        // 左右矢印や Home などは使わないので、印字可能でもない中立な値になる。
        $key = KeyMap::fromBytes("\e[C");
        $this->assertNotSame(KeyMap::UP, $key);
        $this->assertNotSame(KeyMap::DOWN, $key);
        $this->assertFalse(KeyMap::isPrintable($key));
    }

    public function testIsPrintableSeparatesTextFromControlKeys(): void
    {
        $this->assertTrue(KeyMap::isPrintable('a'));
        $this->assertTrue(KeyMap::isPrintable('9'));
        $this->assertFalse(KeyMap::isPrintable(KeyMap::UP));
        $this->assertFalse(KeyMap::isPrintable(KeyMap::ENTER));
        $this->assertFalse(KeyMap::isPrintable(KeyMap::CANCEL));
        $this->assertFalse(KeyMap::isPrintable(KeyMap::EOF));
    }
}
