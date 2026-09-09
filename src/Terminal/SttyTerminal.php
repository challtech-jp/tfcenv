<?php

namespace Tfcenv\Terminal;

final class SttyTerminal implements Terminal
{
    private ?string $savedMode = null;

    private bool $shutdownHookInstalled = false;

    public function isTty(): bool
    {
        return stream_isatty(STDIN) && stream_isatty(STDOUT);
    }

    public function width(): int
    {
        $columns = trim((string) shell_exec('stty size 2>/dev/null'));

        if ($columns !== '') {
            $parts = explode(' ', $columns);
            if (isset($parts[1]) && (int) $parts[1] > 0) {
                return (int) $parts[1];
            }
        }

        return 80;
    }

    public function write(string $text): void
    {
        fwrite(STDOUT, $text);
    }

    public function writeError(string $text): void
    {
        fwrite(STDERR, $text);
    }

    public function readLine(): ?string
    {
        $line = fgets(STDIN);

        // EOF は「空行で Enter」ではない。潰さずに null で返す。
        if ($line === false) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    /**
     * raw モードで1キー読む。ESC を読んだら続きを非ブロッキングで拾って
     * エスケープシーケンスとして解釈する。
     */
    public function readKey(): string
    {
        $byte = fread(STDIN, 1);

        if ($byte === false || $byte === '') {
            return KeyMap::EOF;
        }

        if ($byte !== "\e") {
            return KeyMap::fromBytes($byte);
        }

        // ESC [ A のような3バイト列。単独の ESC もありうるので非ブロッキングで読む。
        stream_set_blocking(STDIN, false);
        $rest = (string) fread(STDIN, 2);
        stream_set_blocking(STDIN, true);

        return KeyMap::fromBytes($byte . $rest);
    }

    /**
     * raw モードに入る。
     *
     * 確立できなかったときに黙って戻ってはいけない。cooked モードのままだと
     * echo が有効なので Prompt::hidden() がシークレットを画面に出してしまう。
     * それは hidden() の唯一の存在理由を壊すので、例外で止める。
     */
    public function enterRawMode(): void
    {
        if ($this->savedMode !== null) {
            return;
        }

        $saved = trim((string) shell_exec('stty -g 2>/dev/null'));

        if ($saved === '') {
            throw new \RuntimeException(
                'Could not read the current terminal mode with stty, so the terminal '
                . 'cannot be switched to raw mode safely.'
            );
        }

        // raw は isig も落とすので Ctrl-C は SIGINT にならず 0x03 として読める。
        // 先に shutdown hook を張ってから切り替える。逆順だと切り替え直後に
        // 落ちた場合に復元されない窓ができる。
        $this->savedMode = $saved;
        $this->installShutdownHook();

        exec('stty raw -echo 2>/dev/null', $ignored, $status);

        if ($status !== 0) {
            // 切り替えは失敗しているので復元するものは無い。状態を戻して投げる。
            $this->savedMode = null;

            throw new \RuntimeException(
                'Could not switch the terminal to raw mode. Refusing to continue, '
                . 'because reading a secret with echo still enabled would print it.'
            );
        }
    }

    public function restoreMode(): void
    {
        if ($this->savedMode === null) {
            return;
        }

        shell_exec('stty ' . $this->savedMode . ' 2>/dev/null');
        $this->savedMode = null;
    }

    /**
     * 未捕捉例外や exit() で raw モードのまま抜けるのを防ぐ。
     * SIGTERM / SIGKILL では走らないが、そこは受け入れる。
     */
    private function installShutdownHook(): void
    {
        if ($this->shutdownHookInstalled) {
            return;
        }

        $this->shutdownHookInstalled = true;
        register_shutdown_function([$this, 'restoreMode']);
    }
}
