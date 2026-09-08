<?php

namespace Tfcenv\Tests\Terminal;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tests\Support\FakeTerminal;
use Tfcenv\Terminal\CancelledException;
use Tfcenv\Terminal\KeyMap;
use Tfcenv\Terminal\Picker;
use Tfcenv\Terminal\Style;

final class PickerTest extends TestCase
{
    /** @return array<string,string> */
    private function workspaces(): array
    {
        return [
            'ws-1' => 'alpha-core-prod',
            'ws-2' => 'alpha-core-stg',
            'ws-3' => 'alpha-core-worker-prod',
            'ws-4' => 'beta-core-prod',
            'ws-5' => 'medical-records-prod',
        ];
    }

    public function testEnterPicksTheFirstItemWhenNothingIsTyped(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $this->assertSame('ws-1', $picker->pick('Workspace', $this->workspaces()));
    }

    public function testTypingNarrowsTheCandidates(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('beta');
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $this->assertSame('ws-4', $picker->pick('Workspace', $this->workspaces()));
    }

    public function testFilteringIsCaseInsensitiveAndMatchesAnywhere(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('WORKER');
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $this->assertSame('ws-3', $picker->pick('Workspace', $this->workspaces()));
    }

    public function testArrowKeysMoveWithinTheFilteredList(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('alpha');
        $terminal->queueKeys(KeyMap::DOWN, KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $this->assertSame('ws-2', $picker->pick('Workspace', $this->workspaces()));
    }

    public function testBackspaceWidensTheFilterAgain(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('beta');
        foreach (range(1, 6) as $ignored) {
            $terminal->queueKeys(KeyMap::BACKSPACE);
        }
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $this->assertSame('ws-1', $picker->pick('Workspace', $this->workspaces()));
    }

    public function testTheCursorResetsWhenTheFilterChanges(): void
    {
        $terminal = new FakeTerminal();
        // core で4件に絞って2番目(ws-2)へ移動したあと、さらに絞り込むと
        // 候補の並びが変わるのでカーソルは先頭に戻る。
        // 戻らなければ 2番目の beta-core-prod(ws-4) が選ばれてしまう。
        $terminal->queueTyping('core');
        $terminal->queueKeys(KeyMap::DOWN);
        $terminal->queueTyping('-p');
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $this->assertSame('ws-1', $picker->pick('Workspace', $this->workspaces()));
    }

    public function testEnterOnAnEmptyResultKeepsWaiting(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('zzz');
        $terminal->queueKeys(KeyMap::ENTER);        // 候補ゼロなので確定しない
        $terminal->queueKeys(KeyMap::BACKSPACE, KeyMap::BACKSPACE, KeyMap::BACKSPACE);
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $this->assertSame('ws-1', $picker->pick('Workspace', $this->workspaces()));
    }

    public function testItShowsHowManyOfHowManyAreVisible(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('alpha');
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $picker->pick('Workspace', $this->workspaces());

        $this->assertStringContainsString('3/5', $terminal->output());
    }

    public function testCtrlCCancelsAndRestoresTheTerminal(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::CANCEL);
        $picker = new Picker($terminal, new Style(false));

        try {
            $picker->pick('Workspace', $this->workspaces());
            $this->fail('expected a CancelledException');
        } catch (CancelledException $e) {
            $this->assertSame(1, $terminal->rawModeRestored());
        }
    }

    public function testAnEmptyItemListIsRejected(): void
    {
        $picker = new Picker(new FakeTerminal(), new Style(false));

        $this->expectException(\InvalidArgumentException::class);
        $picker->pick('Workspace', []);
    }

    public function testItScrollsTheWindowToKeepTheCursorVisible(): void
    {
        // 実運用の organization はワークスペースが visibleRows(8) を超えるので、
        // スクロール分岐は例外的な経路ではなく通常の経路になる。
        $items = [];

        for ($i = 1; $i <= 12; $i++) {
            $items['ws-' . $i] = sprintf('workspace-%02d', $i);
        }

        $terminal = new FakeTerminal();
        $terminal->queueKeys(...array_fill(0, 9, KeyMap::DOWN));
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $chosen = $picker->pick('Workspace', $items);

        $this->assertSame('ws-10', $chosen);

        // 最後の描画だけを見る。clearLines() はエスケープを書くだけで
        // FakeTerminal の蓄積出力からは消えないため。ヒント行は1描画に
        // つき1回、しかも末尾に出るので、そこで割った最後から2番目が
        // 最終描画のラベル行と候補行にあたる（末尾は畳んだ回答行）。
        $renders = explode('· type to filter', $terminal->output());
        $final = $renders[count($renders) - 2];

        $this->assertStringContainsString('workspace-10', $final);
        $this->assertStringNotContainsString('workspace-01', $final);
    }

    public function testTheFilterIsTypedOnTheLabelLine(): void
    {
        // 別の filter: 行を立てず、他のプロンプトと同じくラベルと同じ行で受ける。
        $terminal = new FakeTerminal();
        $terminal->queueTyping('beta');
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $picker->pick('Workspace', $this->workspaces());

        $this->assertStringContainsString("? Workspace \u{203A} beta", $terminal->output());
        $this->assertStringNotContainsString('filter:', $terminal->output());
    }

    public function testTheChosenItemCollapsesToASingleLine(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueTyping('beta');
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $picker->pick('Workspace', $this->workspaces());

        // ラベル桁は Prompt と揃えて 12。
        $this->assertStringContainsString(
            "\u{2714} Workspace    \u{B7} beta-core-prod\r\n",
            $terminal->output(),
        );
    }

    public function testTheListStaysWhenTheAnsweredLineWouldNotFit(): void
    {
        // 折り返した行は clearLines() では消しきれない。畳めないと分かったら
        // 出ているものをそのまま残す。FakeTerminal の幅は 80。
        $long = str_repeat('w', 70);
        $terminal = new FakeTerminal();
        $terminal->queueKeys(KeyMap::ENTER);
        $picker = new Picker($terminal, new Style(false));

        $picker->pick('Workspace', ['ws-1' => $long]);

        $this->assertStringNotContainsString("\u{2714} Workspace", $terminal->output());
    }
}
