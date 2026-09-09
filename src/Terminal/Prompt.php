<?php

namespace Tfcenv\Terminal;

final class Prompt
{
    /** 回答済み行のラベル桁。全角を含むので mb_strwidth で揃える。 */
    private const LABEL_WIDTH = 12;

    /** 質問行で入力の直前に置く区切り */
    private const PENDING_MARK = '›';

    /** 回答済み行でラベルと値を分ける区切り */
    private const ANSWER_MARK = '·';

    /** hidden() の回答済み行に出す伏せ字。長さは値を漏らさないよう固定 */
    private const MASK = '••••••••';

    public function __construct(
        private readonly Terminal $terminal,
        private readonly Style $style,
    ) {
    }

    public function text(string $label, string $default = ''): string
    {
        $hint = $default === '' ? '' : ' ' . $this->style->dim('[' . $default . ']');
        // ' [' + default + ']'
        $hintWidth = $default === '' ? 0 : mb_strwidth($default) + 3;

        $this->terminal->write($this->question($label, $hint));

        $typed = $this->terminal->readLine();

        // EOF は答えではない。畳む前に投げること。EOF のときは端末が改行を
        // エコーしていないのでカーソルは質問行のままで、そこで1行戻すと
        // ひとつ上の回答済み行を消してしまう。
        if ($typed === null) {
            throw new CancelledException('input ended');
        }

        $answer = trim($typed);
        $value = $answer === '' ? $default : $answer;

        $this->collapse($label, $value, 1, $this->questionWidth($label, $hintWidth, mb_strwidth($typed)));

        return $value;
    }

    /**
     * 入力を表示せずに1行読む。raw モードで読むので Ctrl-C は
     * SIGINT ではなくバイトとして届き、端末を必ず復元できる。
     */
    public function hidden(string $label): string
    {
        $this->terminal->write($this->question($label, ''));
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

        // '*' はバイトごとに書いているので、エコー幅はバイト長と一致する。
        $this->collapse($label, self::MASK, 1, $this->questionWidth($label, 0, strlen($value)));

        return $value;
    }

    public function confirm(string $label, bool $default = true): bool
    {
        $hintText = $default ? '[Y/n]' : '[y/N]';
        $hint = ' ' . $this->style->dim($hintText);

        while (true) {
            $this->terminal->write($this->question($label, $hint));

            $typed = $this->terminal->readLine();

            // 空行の Enter は既定の採用。EOF は意思表示ではないので中止する。
            if ($typed === null) {
                throw new CancelledException('input ended');
            }

            $answer = strtolower(trim($typed));
            $shown = $this->questionWidth($label, mb_strwidth($hintText) + 1, mb_strwidth($typed));

            if ($answer === '') {
                $this->collapse($label, $default ? 'Yes' : 'No', 1, $shown);

                return $default;
            }

            if ($answer === 'y' || $answer === 'yes') {
                $this->collapse($label, 'Yes', 1, $shown);

                return true;
            }

            if ($answer === 'n' || $answer === 'no') {
                $this->collapse($label, 'No', 1, $shown);

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

        $this->terminal->enterRawMode();
        $renderedLines = 0;

        try {
            while (true) {
                $this->clearLines($renderedLines);
                $renderedLines = $this->renderOptions($label, $options, $cursor);

                $key = $this->terminal->readKey();

                if ($key === KeyMap::CANCEL) {
                    throw new CancelledException('cancelled');
                }

                // EOF（Ctrl-D や stdin の切断）で既定を選んだことにしてはいけない。
                // 選択は Enter でしか確定しない。
                if ($key === KeyMap::EOF) {
                    throw new CancelledException('input ended before an option was chosen');
                }

                if ($key === KeyMap::ENTER) {
                    $chosen = $values[$cursor];
                    $this->collapse(
                        $label,
                        $options[$chosen],
                        $renderedLines,
                        $this->questionWidth($label, 0, mb_strwidth($options[$chosen])),
                    );

                    return $chosen;
                }

                if ($key === KeyMap::UP && $cursor > 0) {
                    $cursor--;
                }

                if ($key === KeyMap::DOWN && $cursor < count($values) - 1) {
                    $cursor++;
                }
            }
        } finally {
            $this->terminal->restoreMode();
        }
    }

    /**
     * ラベル行と候補行をまとめて描く。ラベル行には今の選択を出すので、
     * 入力は他のプロンプトと同じくラベルと同じ行に見える。
     *
     * @param array<string,string> $options
     * @return int 描いた行数
     */
    private function renderOptions(string $label, array $options, int $cursor): int
    {
        $values = array_keys($options);
        $this->terminal->write($this->question($label, '') . $options[$values[$cursor]] . "\r\n");

        $index = 0;

        foreach ($options as $optionLabel) {
            $marker = $index === $cursor ? $this->style->accent('❯') : ' ';
            $text = $index === $cursor ? $this->style->bold($optionLabel) : $optionLabel;
            $this->terminal->write('  ' . $marker . ' ' . $text . "\r\n");
            $index++;
        }

        return 1 + count($options);
    }

    /** 未回答の質問行。入力は必ずこの行の続きに来る。 */
    private function question(string $label, string $hint): string
    {
        return $this->style->accent('?') . ' ' . $label . $hint . ' '
            . $this->style->dim(self::PENDING_MARK) . ' ';
    }

    /** 質問行の表示幅。'? ' + ラベル + ヒント + ' > ' + 入力 */
    private function questionWidth(string $label, int $hintWidth, int $inputWidth): int
    {
        return 2 + mb_strwidth($label) + $hintWidth + 3 + $inputWidth;
    }

    /** 回答済みの1行。ラベル桁は mb_strwidth で揃える（全角の説明があるため） */
    private function answered(string $label, string $value): string
    {
        return $this->style->ok('✔') . ' ' . $this->padLabel($label) . ' '
            . $this->style->dim(self::ANSWER_MARK) . ' ' . $value;
    }

    private function padLabel(string $label): string
    {
        $pad = self::LABEL_WIDTH - mb_strwidth($label);

        return $pad > 0 ? $label . str_repeat(' ', $pad) : $label;
    }

    /**
     * 画面に出ている質問 $lines 行を「✔ ラベル · 値」1行に畳む。
     *
     * cooked モードの入力は端末側がエコーするので、行が折り返していると
     * 1行戻すだけでは足りず、消すと表示が壊れる。端末幅に収まるときだけ
     * 畳み、収まらなければ出ているものをそのまま残す。TTY でなければ
     * カーソル移動そのものが無意味なので何もしない。
     */
    private function collapse(string $label, string $value, int $lines, int $shownWidth): void
    {
        if ($lines < 1 || !$this->terminal->isTty()) {
            return;
        }

        $width = $this->terminal->width();

        if ($shownWidth > $width) {
            return;
        }

        if (2 + mb_strwidth($this->padLabel($label)) + 3 + mb_strwidth($value) > $width) {
            return;
        }

        $this->clearLines($lines);
        $this->terminal->write($this->answered($label, $value) . "\r\n");
    }

    private function clearLines(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->terminal->write("\e[1A\e[2K");
        }
    }
}
