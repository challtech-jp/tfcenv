<?php

namespace Tfcenv\Tests\Terminal;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tests\Support\FakeTerminal;
use Tfcenv\Terminal\CancelledException;
use Tfcenv\Terminal\KeyMap;
use Tfcenv\Terminal\Prompt;
use Tfcenv\Terminal\Style;

final class PromptTest extends TestCase
{
    public function testTextReturnsWhatWasTyped(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('acme');
        $prompt = new Prompt($terminal, new Style(false));

        $this->assertSame('acme', $prompt->text('Organization'));
        $this->assertStringContainsString('Organization', $terminal->output());
    }

    public function testTextFallsBackToTheDefaultOnEmptyInput(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('');
        $prompt = new Prompt($terminal, new Style(false));

        $this->assertSame('acme', $prompt->text('Organization', 'acme'));
    }

    public function testTextShowsTheDefaultInTheLabel(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('');
        $prompt = new Prompt($terminal, new Style(false));

        $prompt->text('Organization', 'acme');

        // 既定値はラベルの直後、区切りより手前に出す。区切りの後ろは
        // 端末が入力をエコーする場所なので空けておく。
        $this->assertStringContainsString("? Organization [acme] \u{203A} ", $terminal->output());
    }

    public function testTextTrimsSurroundingWhitespace(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('  partner_client_id  ');
        $prompt = new Prompt($terminal, new Style(false));

        $this->assertSame('partner_client_id', $prompt->text('Key'));
    }

    public function testHiddenReadsInRawModeAndNeverEchoesTheValue(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('s3cret');
        $terminal->queueKeys(KeyMap::ENTER);
        $prompt = new Prompt($terminal, new Style(false));

        $value = $prompt->hidden('Value');

        $this->assertSame('s3cret', $value);
        $this->assertStringNotContainsString('s3cret', $terminal->output());
        $this->assertSame(1, $terminal->rawModeEntered());
        $this->assertSame(1, $terminal->rawModeRestored());
    }

    public function testHiddenSupportsBackspace(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('abx');
        $terminal->queueKeys(KeyMap::BACKSPACE);
        $terminal->queueTyping('c');
        $terminal->queueKeys(KeyMap::ENTER);
        $prompt = new Prompt($terminal, new Style(false));

        $this->assertSame('abc', $prompt->hidden('Value'));
    }

    public function testHiddenCancelsOnCtrlCAndRestoresTheTerminal(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('ab');
        $terminal->queueKeys(KeyMap::CANCEL);
        $prompt = new Prompt($terminal, new Style(false));

        try {
            $prompt->hidden('Value');
            $this->fail('expected a CancelledException');
        } catch (CancelledException $e) {
            $this->assertSame(1, $terminal->rawModeRestored());
        }
    }

    public function testConfirmAcceptsYesAndNo(): void
    {
        $yes = new FakeTerminal();
        $yes->queueLine('y');
        $no = new FakeTerminal();
        $no->queueLine('n');

        $this->assertTrue((new Prompt($yes, new Style(false)))->confirm('Apply?'));
        $this->assertFalse((new Prompt($no, new Style(false)))->confirm('Apply?'));
    }

    public function testConfirmUsesTheDefaultOnEmptyInput(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('');
        $terminal->queueLine('');

        $prompt = new Prompt($terminal, new Style(false));

        $this->assertTrue($prompt->confirm('Apply?', true));
        $this->assertFalse($prompt->confirm('Apply?', false));
    }

    public function testConfirmRepromptsOnUnrecognisedInput(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('maybe');
        $terminal->queueLine('yes');
        $prompt = new Prompt($terminal, new Style(false));

        $this->assertTrue($prompt->confirm('Apply?'));
    }

    public function testSelectMovesWithArrowKeysAndReturnsTheChosenValue(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::ENTER);
        $prompt = new Prompt($terminal, new Style(false));

        $chosen = $prompt->select('What now?', [
            'update' => 'Update the value',
            'attrs' => 'Update the value and attributes',
            'skip' => 'Skip this variable',
        ]);

        $this->assertSame('attrs', $chosen);
    }

    public function testSelectStartsOnTheDefaultOption(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::ENTER);
        $prompt = new Prompt($terminal, new Style(false));

        $chosen = $prompt->select('Category', [
            'terraform' => 'terraform',
            'env' => 'env',
        ], 'env');

        $this->assertSame('env', $chosen);
    }

    public function testSelectDoesNotWrapPastTheEnds(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::UP, KeyMap::UP, KeyMap::ENTER);
        $prompt = new Prompt($terminal, new Style(false));

        $chosen = $prompt->select('Category', [
            'terraform' => 'terraform',
            'env' => 'env',
        ]);

        $this->assertSame('terraform', $chosen);
    }

    public function testSelectCancelsOnCtrlC(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::CANCEL);
        $prompt = new Prompt($terminal, new Style(false));

        $this->expectException(CancelledException::class);
        $prompt->select('Category', ['terraform' => 'terraform']);
    }

    public function testSelectRestoresTheTerminalWhenCancelled(): void
    {
        // hidden() has the same property and the same test. Ctrl-C must not be
        // able to leave the terminal in raw mode from either prompt.
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::CANCEL);
        $prompt = new Prompt($terminal, new Style(false));

        try {
            $prompt->select('Category', ['terraform' => 'terraform']);
            $this->fail('expected a CancelledException');
        } catch (CancelledException $e) {
            $this->assertSame(1, $terminal->rawModeEntered());
            $this->assertSame(1, $terminal->rawModeRestored());
        }
    }

    public function testSelectDoesNotWrapPastTheLastOption(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::DOWN, KeyMap::DOWN, KeyMap::ENTER);
        $prompt = new Prompt($terminal, new Style(false));

        $chosen = $prompt->select('Category', [
            'terraform' => 'terraform',
            'env' => 'env',
        ]);

        $this->assertSame('env', $chosen);
    }

    public function testSelectFallsBackToTheFirstOptionWhenTheDefaultIsUnknown(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::ENTER);
        $prompt = new Prompt($terminal, new Style(false));

        $chosen = $prompt->select('Category', [
            'terraform' => 'terraform',
            'env' => 'env',
        ], 'nonexistent');

        $this->assertSame('terraform', $chosen);
    }

    public function testHiddenTreatsEndOfInputAsCancellation(): void
    {
        // A partially typed secret must not be accepted as confirmed just because
        // stdin ended; that would register a truncated value in Terraform Cloud.
        $terminal = new FakeTerminal();
        $terminal->queueTyping('half');
        $prompt = new Prompt($terminal, new Style(false));

        try {
            $prompt->hidden('Value');
            $this->fail('expected a CancelledException');
        } catch (CancelledException $e) {
            $this->assertSame(1, $terminal->rawModeRestored());
        }
    }


    public function testTextTreatsEndOfInputAsCancellation(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueEof();
        $prompt = new Prompt($terminal, new Style(false));

        $this->expectException(CancelledException::class);
        $prompt->text('Key');
    }

    public function testTextStillAcceptsABlankLineAsTheDefault(): void
    {
        // '' と EOF を取り違えていないことを固定する
        $terminal = new FakeTerminal();
        $terminal->queueLine('');
        $prompt = new Prompt($terminal, new Style(false));

        $this->assertSame('acme', $prompt->text('Organization', 'acme'));
    }

    public function testTextDoesNotCollapseTheLineWhenInputEnds(): void
    {
        // EOF では端末が改行をエコーしないのでカーソルは質問行のまま。
        // ここで畳むと ESC[1A がひとつ上の回答済み行に当たって消してしまう。
        $terminal = new FakeTerminal();
        $terminal->queueEof();
        $prompt = new Prompt($terminal, new Style(false));

        try {
            $prompt->text('Key');
            $this->fail('expected a CancelledException');
        } catch (CancelledException $e) {
            $this->assertStringNotContainsString("\e[1A", $terminal->output());
        }
    }

    public function testConfirmTreatsEndOfInputAsCancellation(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueEof();
        $prompt = new Prompt($terminal, new Style(false));

        $this->expectException(CancelledException::class);
        $prompt->confirm('Apply?');
    }

    public function testConfirmTreatsEndOfInputAsCancellationEvenWhenTheDefaultIsNo(): void
    {
        // 適用ゲートはここを既定 false で呼ぶ。既定を返して続けるのではなく
        // 中止すること。どちらも書き込まないが、意味を1つに揃えておく。
        $terminal = new FakeTerminal();
        $terminal->queueEof();
        $prompt = new Prompt($terminal, new Style(false));

        $this->expectException(CancelledException::class);
        $prompt->confirm('Apply?', false);
    }

    public function testSelectTreatsEndOfInputAsCancellation(): void
    {
        // キューを流さない = 1キー目で EOF。既定を選んだ扱いにしてはいけない。
        $terminal = new FakeTerminal();
        $prompt = new Prompt($terminal, new Style(false));

        try {
            $prompt->select('Category', [
                'terraform' => 'terraform',
                'env' => 'env',
            ], 'env');
            $this->fail('expected a CancelledException');
        } catch (CancelledException $e) {
            $this->assertSame(1, $terminal->rawModeRestored());
            $this->assertStringNotContainsString("\u{2714} Category", $terminal->output());
        }
    }

    public function testTextCollapsesTheAnsweredLine(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('acme');
        $prompt = new Prompt($terminal, new Style(false));

        $prompt->text('Organization', '');

        $this->assertStringContainsString("\u{2714} Organization \u{B7} acme\r\n", $terminal->output());
    }

    public function testTextLeavesTheLineAloneWhenTheAnswerWouldNotFit(): void
    {
        // cooked モードの入力は端末がエコーする。折り返していると1行戻しても
        // 消しきれず表示が壊れるので、幅に収まらないときは畳まない。
        $terminal = new FakeTerminal();
        $terminal->queueLine(str_repeat('v', 70));
        $prompt = new Prompt($terminal, new Style(false));

        $prompt->text('Value');

        $this->assertStringNotContainsString("\u{2714} Value", $terminal->output());
    }

    public function testHiddenCollapsesToAMaskedLine(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('s3cret');
        $terminal->queueKeys(KeyMap::ENTER);
        $prompt = new Prompt($terminal, new Style(false));

        $prompt->hidden('Value');

        $this->assertStringContainsString("\u{2714} Value        \u{B7} \u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\r\n", $terminal->output());
        $this->assertStringNotContainsString('s3cret', $terminal->output());
    }

    public function testConfirmCollapsesToYesOrNo(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('y');
        $prompt = new Prompt($terminal, new Style(false));

        $prompt->confirm('Sensitive');

        $this->assertStringContainsString("\u{2714} Sensitive    \u{B7} Yes\r\n", $terminal->output());
    }

    public function testSelectShowsTheCursorAndCollapsesOnAnswer(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::ENTER);
        $prompt = new Prompt($terminal, new Style(false));

        $prompt->select('Category', [
            'terraform' => 'terraform',
            'env' => 'env',
        ]);

        $output = $terminal->output();

        // 選択中の候補はラベル行にも出るので、入力位置は他のプロンプトと揃う。
        $this->assertStringContainsString("? Category \u{203A} env\r\n", $output);
        $this->assertStringContainsString("  \u{276F} env\r\n", $output);
        $this->assertStringContainsString("\u{2714} Category     \u{B7} env\r\n", $output);
    }
}
