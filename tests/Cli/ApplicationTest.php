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
}
