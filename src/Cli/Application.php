<?php

namespace Tfcenv\Cli;

use Tfcenv\Terminal\Picker;
use Tfcenv\Terminal\Prompt;
use Tfcenv\Terminal\Style;
use Tfcenv\Terminal\Terminal;
use Tfcenv\Tfc\Client;
use Tfcenv\Tfc\CurlTransport;
use Tfcenv\Tfc\TfcException;
use Tfcenv\Tfc\VariableRepository;
use Tfcenv\Tfc\WorkspaceRepository;
use Tfcenv\Version;

final class Application
{
    private const TOKEN_URL = 'https://app.terraform.io/app/settings/tokens';

    public function __construct(
        private readonly Terminal $terminal,
    ) {
    }

    public function run(int $argc, array $argv): int
    {
        $command = $argc > 1 ? (string) $argv[1] : '';

        return match ($command) {
            '--version', '-v' => $this->printVersion(),
            '--help', '-h' => $this->printHelp(),
            'add' => $this->add(),
            '' => $this->usageError('tfcenv needs a command.'),
            default => $this->usageError(sprintf('Unknown command "%s".', $command)),
        };
    }

    private function printVersion(): int
    {
        $this->terminal->write('tfcenv ' . Version::STRING . "\n");

        return 0;
    }

    private function printHelp(): int
    {
        $this->terminal->write($this->helpText());

        return 0;
    }

    private function usageError(string $message): int
    {
        $this->terminal->writeError($message . "\n\n" . $this->helpText());

        return 1;
    }

    /**
     * ヒアドキュメントを使わず連結で組む。メソッド呼び出しの文字列補間は
     * TypePHP で検証済みの構文に含まれないため。
     */
    private function helpText(): string
    {
        return "tfcenv - register Terraform Cloud workspace variables from the CLI\n"
            . "\n"
            . "Usage:\n"
            . "  tfcenv add        register workspace variables interactively\n"
            . "  tfcenv --help\n"
            . "  tfcenv --version\n"
            . "\n"
            . "Environment:\n"
            . '  TFC_TOKEN         required. Create one at ' . self::TOKEN_URL . "\n"
            . "  TFC_ORG           optional. Default organization\n"
            . "  NO_COLOR          optional. Set to disable ANSI colour\n";
    }

    private function add(): int
    {
        $token = (string) getenv('TFC_TOKEN');

        if ($token === '') {
            $this->terminal->writeError(
                "TFC_TOKEN is not set.\n"
                . 'Create a user token at ' . self::TOKEN_URL . " and export it:\n"
                . "  export TFC_TOKEN=...\n",
            );

            return 1;
        }

        $organization = (string) getenv('TFC_ORG');
        $noColor = getenv('NO_COLOR');

        $style = Style::detect($this->terminal, $noColor === false ? null : (string) $noColor);
        $prompt = new Prompt($this->terminal, $style);
        $client = new Client(new CurlTransport(), $token);
        $variables = new VariableRepository($client);

        $command = new AddCommand(
            new WorkspaceRepository($client),
            $variables,
            $this->terminal,
            $style,
            $prompt,
            new Picker($this->terminal, $style),
            new Confirmation($this->terminal, $style, $prompt),
            new Applier($variables, $this->terminal, $style),
            $organization,
        );

        try {
            return $command->run();
        } catch (TfcException $e) {
            $this->terminal->writeError($e->getMessage() . "\n");

            return 1;
        } catch (\Throwable $e) {
            // SttyTerminal は raw モードを確立できないと RuntimeException を投げる。
            // 対話ツールがスタックトレースを吐いて落ちるより、1行で伝えて終わる。
            $this->terminal->writeError($e->getMessage() . "\n");

            return 1;
        }
    }
}
