<?php

namespace Tfcenv\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tfcenv\Cli\Change;
use Tfcenv\Cli\ChangeOp;
use Tfcenv\Cli\ChangeSet;
use Tfcenv\Cli\Confirmation;
use Tfcenv\Tests\Support\FakeTerminal;
use Tfcenv\Terminal\Prompt;
use Tfcenv\Terminal\Style;
use Tfcenv\Tfc\Category;
use Tfcenv\Tfc\Variable;
use Tfcenv\Tfc\Workspace;

final class ConfirmationTest extends TestCase
{
    private function set(): ChangeSet
    {
        return new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), [
            new Change(ChangeOp::Create, new Variable('partner_client_id', 'super-secret', Category::Terraform, true, 'OAuth クライアントID')),
            new Change(ChangeOp::Update, new Variable('gtm_container_id', 'GTM-XXXXXXX', Category::Terraform, false, 'GTM コンテナ ID', 'var-2')),
        ]);
    }

    private function confirmation(FakeTerminal $terminal): Confirmation
    {
        $style = new Style(false);

        return new Confirmation($terminal, $style, new Prompt($terminal, $style));
    }

    public function testItShowsTheWorkspaceAndEveryChange(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('y');

        $this->confirmation($terminal)->ask($this->set());

        $output = $terminal->output();
        $this->assertStringContainsString('acme/alpha-core-prod', $output);
        $this->assertStringContainsString('create', $output);
        $this->assertStringContainsString('update', $output);
        $this->assertStringContainsString('partner_client_id', $output);
        $this->assertStringContainsString('gtm_container_id', $output);
    }

    public function testItMasksSensitiveValuesAndShowsPlainOnes(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('y');

        $this->confirmation($terminal)->ask($this->set());

        $output = $terminal->output();
        $this->assertStringNotContainsString('super-secret', $output);
        $this->assertStringContainsString('••••••••', $output);
        $this->assertStringContainsString('GTM-XXXXXXX', $output);
    }

    public function testItReturnsTheAnswer(): void
    {
        $yes = new FakeTerminal();
        $yes->queueLine('y');
        $no = new FakeTerminal();
        $no->queueLine('n');

        $this->assertTrue($this->confirmation($yes)->ask($this->set()));
        $this->assertFalse($this->confirmation($no)->ask($this->set()));
    }

    public function testItShowsAnEmptyValueRatherThanBlankSpace(): void
    {
        $terminal = new FakeTerminal();
        $terminal->queueLine('n');
        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), [
            new Change(ChangeOp::Update, new Variable('GTM_ID', '', Category::Terraform, false, 'live', 'var-9')),
        ]);

        $this->confirmation($terminal)->ask($set);

        // 破壊的な更新が空白にしか見えないなら、確認画面の意味が無い
        $this->assertStringContainsString('(empty)', $terminal->output());
    }

    public function testAnEmptySetIsNotWorthAsking(): void
    {
        $terminal = new FakeTerminal();
        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), []);

        $this->assertFalse($this->confirmation($terminal)->ask($set));
        $this->assertStringContainsString('nothing to apply', $terminal->output());
    }

    public function testItAlignsTheKeyAndValueColumnsAcrossTheChangeSet(): void
    {
        $terminal = new FakeTerminal(false);
        $terminal->queueLine('n');
        $set = new ChangeSet('acme', new Workspace('ws-1', 'gamma-ai-ocr'), [
            new Change(ChangeOp::Create, new Variable('yrdy', 'secret', Category::Terraform, true, 'aaa')),
            new Change(ChangeOp::Update, new Variable('GTM_ID', 'GTM-XXXXXXX', Category::Terraform, false, 'live', 'var-2')),
            new Change(ChangeOp::Create, new Variable('partner_id', 'secret', Category::Terraform, true, '連携先連携')),
        ]);

        $this->confirmation($terminal)->ask($set);

        // 一番広いキーと値に合わせて桁が揃い、属性は · で並ぶ
        $this->assertSame([
            '    create  yrdy        ••••••••     terraform · sensitive · "aaa"',
            '    update  GTM_ID      GTM-XXXXXXX  terraform · plain · "live"',
            '    create  partner_id  ••••••••     terraform · sensitive · "連携先連携"',
        ], $this->rows($terminal->output()));
    }

    public function testItLeavesOutTheQuotesWhenThereIsNoDescription(): void
    {
        $terminal = new FakeTerminal(false);
        $terminal->queueLine('n');
        $set = new ChangeSet('acme', new Workspace('ws-1', 'gamma-ai-ocr'), [
            new Change(ChangeOp::Create, new Variable('yrdy', 'secret', Category::Terraform, true, '')),
        ]);

        $this->confirmation($terminal)->ask($set);

        $this->assertSame(
            ['    create  yrdy  ••••••••  terraform · sensitive'],
            $this->rows($terminal->output())
        );
        $this->assertStringNotContainsString(' · ""', $terminal->output());
    }

    public function testItAlignsByDisplayWidthSoFullWidthValuesDoNotShiftTheAttributes(): void
    {
        $terminal = new FakeTerminal(false);
        $terminal->queueLine('n');
        $set = new ChangeSet('acme', new Workspace('ws-1', 'gamma-ai-ocr'), [
            new Change(ChangeOp::Update, new Variable('partner_name', '連携先', Category::Terraform, false, '連携先連携', 'var-1')),
            new Change(ChangeOp::Update, new Variable('partner_code', 'PARTNER-01', Category::Terraform, false, 'live', 'var-2')),
        ]);

        $this->confirmation($terminal)->ask($set);

        $rows = $this->rows($terminal->output());
        $this->assertCount(2, $rows);

        // '連携先' は表示幅 6 だが 9 バイト。値の列は 'PARTNER-01'（表示幅 10）に
        // 合わせるので、表示幅で埋めれば空白4つ、バイト長で埋めると空白1つになり、
        // 上の行の属性だけ3桁ぶん左へずれる。
        $this->assertSame(
            $this->attributeOffset($rows[0]),
            $this->attributeOffset($rows[1])
        );
        $this->assertSame([
            '    update  partner_name  連携先      terraform · plain · "連携先連携"',
            '    update  partner_code  PARTNER-01  terraform · plain · "live"',
        ], $rows);
    }

    public function testTheGateCountsTheChangesInWords(): void
    {
        $one = new FakeTerminal(false);
        $one->queueLine('n');
        $this->confirmation($one)->ask(new ChangeSet('acme', new Workspace('ws-1', 'gamma-ai-ocr'), [
            new Change(ChangeOp::Create, new Variable('yrdy', 'secret', Category::Terraform, true, 'aaa')),
        ]));

        $two = new FakeTerminal(false);
        $two->queueLine('n');
        $this->confirmation($two)->ask($this->set());

        $this->assertStringContainsString('Apply 1 change?', $one->output());
        $this->assertStringContainsString('Apply 2 changes?', $two->output());

        // 内訳は行が見せているので、質問文に (create N / update M) は付けない
        $this->assertStringNotContainsString('(create', $two->output());
    }

    /**
     * 変更行だけを取り出す。行頭の4スペースが変更行の目印。
     *
     * @return string[]
     */
    private function rows(string $output): array
    {
        $rows = [];

        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, '    ')) {
                $rows[] = $line;
            }
        }

        return $rows;
    }

    /** 属性の桁が始まる表示幅上の位置 */
    private function attributeOffset(string $row): int
    {
        $at = mb_strpos($row, 'terraform');
        $this->assertNotFalse($at, 'a change row always names its category');

        return mb_strwidth(mb_substr($row, 0, $at));
    }
}
