<?php

namespace Tfcenv\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tfcenv\Cli\Application;
use Tfcenv\Tests\Support\FakeTerminal;
use Tfcenv\Version;

final class ApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('TFC_TOKEN');
        putenv('TFC_ORG');
        putenv('NO_COLOR');
    }

    public function testVersionPrintsTheVersion(): void
    {
        $terminal = new FakeTerminal();

        $exit = (new Application($terminal))->run(2, ['tfcenv', '--version']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString(Version::STRING, $terminal->output());
    }

    public function testHelpListsTheCommandAndTheEnvironmentVariables(): void
    {
        $terminal = new FakeTerminal();

        $exit = (new Application($terminal))->run(2, ['tfcenv', '--help']);

        $this->assertSame(0, $exit);
        $output = $terminal->output();
        $this->assertStringContainsString('tfcenv add', $output);
        $this->assertStringContainsString('TFC_TOKEN', $output);
        $this->assertStringContainsString('TFC_ORG', $output);
    }

    public function testNoArgumentsShowsHelpAndFails(): void
    {
        $terminal = new FakeTerminal();

        $exit = (new Application($terminal))->run(1, ['tfcenv']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('tfcenv add', $terminal->errorOutput());
    }

    public function testAnUnknownCommandFails(): void
    {
        $terminal = new FakeTerminal();

        $exit = (new Application($terminal))->run(2, ['tfcenv', 'delete']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('delete', $terminal->errorOutput());
    }

    public function testAddWithoutATokenExplainsHowToGetOne(): void
    {
        $terminal = new FakeTerminal();

        $exit = (new Application($terminal))->run(2, ['tfcenv', 'add']);

        $this->assertSame(1, $exit);
        $error = $terminal->errorOutput();
        $this->assertStringContainsString('TFC_TOKEN', $error);
        $this->assertStringContainsString('app.terraform.io/app/settings/tokens', $error);
    }

    public function testAddWithATokenButNoTtyFailsWithTheTtyMessage(): void
    {
        putenv('TFC_TOKEN=tok');
        $terminal = new FakeTerminal(false);

        $exit = (new Application($terminal))->run(2, ['tfcenv', 'add']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('TTY', $terminal->errorOutput());
    }

    public function testTheTokenIsNeverEchoed(): void
    {
        putenv('TFC_TOKEN=tok-do-not-leak');
        $terminal = new FakeTerminal(false);

        (new Application($terminal))->run(2, ['tfcenv', 'add']);

        $this->assertStringNotContainsString('tok-do-not-leak', $terminal->output());
        $this->assertStringNotContainsString('tok-do-not-leak', $terminal->errorOutput());
    }

    public function testHelpDocumentsTheNonInteractiveForm(): void
    {
        $terminal = new FakeTerminal();

        (new Application($terminal))->run(2, ['tfcenv', '--help']);

        $output = $terminal->output();
        $this->assertStringContainsString('KEY=VALUE', $output);
        $this->assertStringContainsString('--workspace', $output);
        $this->assertStringContainsString('--update', $output);
    }

    /**
     * 引数のある add は非対話モードなので、TTY が無くても引数の解釈まで進む。
     * 進んだ証拠として、TTY のメッセージではなく引数のエラーが返る。
     */
    public function testAddWithArgumentsDoesNotRequireATty(): void
    {
        putenv('TFC_TOKEN=tok');
        $terminal = new FakeTerminal(false);

        $exit = (new Application($terminal))->run(3, ['tfcenv', 'add', 'KEY=v']);

        $this->assertSame(1, $exit);
        $error = $terminal->errorOutput();
        $this->assertStringNotContainsString('TTY', $error);
        $this->assertStringContainsString('--workspace', $error);
    }

    public function testAUsageErrorIsReportedWithoutATrace(): void
    {
        putenv('TFC_TOKEN=tok');
        $terminal = new FakeTerminal(false);

        $exit = (new Application($terminal))->run(5, ['tfcenv', 'add', '-w', 'stg', '--plain']);

        $this->assertSame(1, $exit);
        $error = $terminal->errorOutput();
        $this->assertStringContainsString('--plain', $error);
        $this->assertStringNotContainsString('#0', $error);
    }

    public function testAddWithArgumentsStillNeedsAToken(): void
    {
        $terminal = new FakeTerminal(false);

        $exit = (new Application($terminal))->run(5, ['tfcenv', 'add', '-w', 'stg', 'KEY=v']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('TFC_TOKEN', $terminal->errorOutput());
    }
}
