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
            'add' => $this->add(array_slice($argv, 2)),
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
            . "  tfcenv add                       register variables interactively\n"
            . "  tfcenv add [options] KEY=VALUE   register one variable without prompting\n"
            . "  tfcenv add [options] KEY         the same, taking the value from \$KEY\n"
            . "  tfcenv --help\n"
            . "  tfcenv --version\n"
            . "\n"
            . "Options (non-interactive only):\n"
            . "  -w, --workspace NAME   required\n"
            . "  -o, --org NAME         defaults to \$TFC_ORG\n"
            . "  -c, --category NAME    terraform or env. Defaults to terraform\n"
            . "  -s, --sensitive        register the value as sensitive\n"
            . "  -d, --description TEXT\n"
            . "  -u, --update           overwrite the variable if the key already exists.\n"
            . "                         Attributes you do not pass keep their current values\n"
            . "\n"
            . "Examples:\n"
            . "  tfcenv add -w alpha-core-stg GTM_ID=GTM-XXXXXXX\n"
            . "  tfcenv add -w alpha-core-stg -c env -s -d 'production DB' DB_PASSWORD\n"
            . "  tfcenv add -w alpha-core-stg -u GTM_ID=GTM-XXX\n"
            . "\n"
            . "Terraform Cloud cannot turn a sensitive variable back into a plain one,\n"
            . "so there is no flag for it. Delete the variable and create it again.\n"
            . "\n"
            . "Environment:\n"
            . '  TFC_TOKEN         required. Create one at ' . self::TOKEN_URL . "\n"
            . "  TFC_ORG           optional. Default organization\n"
            . "  NO_COLOR          optional. Set to disable ANSI colour\n";
    }

    /**
     * 引数が1つでも続いていれば非対話モード。対話モードとの分岐をここで済ませ、
     * トークンの確認と HTTP まわりの組み立ては両方で共有する。
     *
     * @param string[] $args
     */
    private function add(array $args): int
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
        $client = new Client(new CurlTransport(), $token);
        $variables = new VariableRepository($client);
        $workspaces = new WorkspaceRepository($client);
        $applier = new Applier($variables, $this->terminal, $style);

        try {
            if ($args === []) {
                return $this->interactive($workspaces, $variables, $applier, $style, $organization);
            }

            $options = AddOptions::parse($args, $organization);

            return (new DirectAddCommand($workspaces, $variables, $this->terminal, $applier))->run($options);
        } catch (UsageException $e) {
            // 引数の書き方の間違いなので、何も送られていないことが確実。
            $this->terminal->writeError($e->getMessage() . "\n");

            return 1;
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

    private function interactive(
        WorkspaceRepository $workspaces,
        VariableRepository $variables,
        Applier $applier,
        Style $style,
        string $organization,
    ): int {
        $prompt = new Prompt($this->terminal, $style);

        $command = new AddCommand(
            $workspaces,
            $variables,
            $this->terminal,
            $style,
            $prompt,
            new Picker($this->terminal, $style),
            new Confirmation($this->terminal, $style, $prompt),
            $applier,
            $organization,
        );

        return $command->run();
    }
}
