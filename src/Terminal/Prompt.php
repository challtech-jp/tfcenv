<?php

namespace Tfcenv\Terminal;

final class Prompt
{
    public function __construct(
        private readonly Terminal $terminal,
        private readonly Style $style,
    ) {
    }

    public function text(string $label, string $default = ''): string
    {
        $suffix = $default === '' ? '' : ' ' . $this->style->dim('[' . $default . ']');
        $this->terminal->write($this->style->accent('?') . ' ' . $label . $suffix . ' ');

        $answer = trim($this->terminal->readLine());

        return $answer === '' ? $default : $answer;
    }

    /**
     * 入力を表示せずに1行読む。raw モードで読むので Ctrl-C は
     * SIGINT ではなくバイトとして届き、端末を必ず復元できる。
     */
    public function hidden(string $label): string
    {
        $this->terminal->write($this->style->accent('?') . ' ' . $label . ' ');
        $this->terminal->enterRawMode();

        $value = '';

        try {
            while (true) {
                $key = $this->terminal->readKey();

                if ($key === KeyMap::CANCEL) {
                    throw new CancelledException('cancelled');
                }

                if ($key === KeyMap::ENTER) {
                    break;
                }

                // EOF（Ctrl-D や stdin の切断）は確認の意思表示ではない。
                // ここで break すると打ちかけのシークレットを確定扱いにしてしまい、
                // 切り詰められた値が TFC に登録される。
                if ($key === KeyMap::EOF) {
                    throw new CancelledException('input ended before the value was confirmed');
                }

                if ($key === KeyMap::BACKSPACE) {
                    if ($value !== '') {
                        $value = substr($value, 0, -1);
                        $this->terminal->write("\x08 \x08");
                    }
                    continue;
                }

                if (KeyMap::isPrintable($key)) {
                    $value .= $key;
                    $this->terminal->write('*');
                }
            }
        } finally {
            $this->terminal->restoreMode();
        }

        $this->terminal->write("\n");

        return $value;
    }

    public function confirm(string $label, bool $default = true): bool
    {
        $hint = $default ? '[Y/n]' : '[y/N]';

        while (true) {
            $this->terminal->write($this->style->accent('?') . ' ' . $label . ' ' . $this->style->dim($hint) . ' ');
            $answer = strtolower(trim($this->terminal->readLine()));

            if ($answer === '') {
                return $default;
            }

            if ($answer === 'y' || $answer === 'yes') {
                return true;
            }

            if ($answer === 'n' || $answer === 'no') {
                return false;
            }

            $this->terminal->write($this->style->dim('  y or n を入力してください') . "\n");
        }
    }

    /**
     * 上下キーで選ぶメニュー。
     *
     * @param array<string,string> $options value => 表示ラベル
     * @return string 選ばれた value
     */
    public function select(string $label, array $options, string $default = ''): string
    {
        $values = array_keys($options);

        if ($values === []) {
            throw new \InvalidArgumentException('select() needs at least one option');
        }

        $cursor = 0;
        $defaultIndex = array_search($default, $values, true);

        if ($defaultIndex !== false) {
            $cursor = (int) $defaultIndex;
        }

        $this->terminal->write($this->style->accent('?') . ' ' . $label . "\n");
        $this->terminal->enterRawMode();

        try {
            while (true) {
                $this->renderOptions($options, $cursor);
                $key = $this->terminal->readKey();

                if ($key === KeyMap::CANCEL) {
                    throw new CancelledException('cancelled');
                }

                if ($key === KeyMap::ENTER || $key === KeyMap::EOF) {
                    return $values[$cursor];
                }

                if ($key === KeyMap::UP && $cursor > 0) {
                    $cursor--;
                }

                if ($key === KeyMap::DOWN && $cursor < count($values) - 1) {
                    $cursor++;
                }

                $this->clearLines(count($values));
            }
        } finally {
            $this->terminal->restoreMode();
        }
    }

    /**
     * @param array<string,string> $options
     */
    private function renderOptions(array $options, int $cursor): void
    {
        $index = 0;

        foreach ($options as $optionLabel) {
            $marker = $index === $cursor ? $this->style->accent('>') : ' ';
            $text = $index === $cursor ? $this->style->bold($optionLabel) : $optionLabel;
            $this->terminal->write('  ' . $marker . ' ' . $text . "\r\n");
            $index++;
        }
    }

    private function clearLines(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->terminal->write("\e[1A\e[2K");
        }
    }
}
