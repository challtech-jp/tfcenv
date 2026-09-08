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

    public function testItShowsTheWorkspaceAndTheOperationBreakdown(): void
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

    public function testAnEmptySetIsNotWorthAsking(): void
    {
        $terminal = new FakeTerminal();
        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), []);

        $this->assertFalse($this->confirmation($terminal)->ask($set));
        $this->assertStringContainsString('nothing to apply', $terminal->output());
    }
}
