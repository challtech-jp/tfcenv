<?php

namespace Tfcenv\Terminal;

final class Picker
{
    /** 回答済み行のラベル桁。Prompt と揃える。 */
    private const LABEL_WIDTH = 12;

    /** 質問行で入力の直前に置く区切り */
    private const PENDING_MARK = '›';

    /** 回答済み行でラベルと値を分ける区切り */
    private const ANSWER_MARK = '·';

    public function __construct(
        private readonly Terminal $terminal,
        private readonly Style $style,
        private readonly int $visibleRows = 8,
    ) {
    }

    /**
     * 絞り込み付きのリスト選択。
     *
     * @param array<string,string> $items value => 表示ラベル。絞り込みはラベルに対して行う
     * @return string 選ばれた value
     * @throws CancelledException Ctrl-C
     */
    public function pick(string $label, array $items): string
    {
        if ($items === []) {
            throw new \InvalidArgumentException('pick() needs at least one item');
        }

        $filter = '';
        $cursor = 0;
        $renderedLines = 0;

        $this->terminal->enterRawMode();

        try {
            while (true) {
                $matches = $this->filter($items, $filter);
                $cursor = $this->clamp($cursor, count($matches));

                $this->clearLines($renderedLines);
                $renderedLines = $this->render($label, $filter, $matches, $cursor, count($items));

                $key = $this->terminal->readKey();

                if ($key === KeyMap::CANCEL) {
                    throw new CancelledException('cancelled');
                }

                if ($key === KeyMap::EOF) {
                    if ($matches === []) {
                        throw new CancelledException('no candidate to select');
                    }
                    return $this->chosen($label, $matches, $cursor, $filter, $renderedLines);
                }

                if ($key === KeyMap::ENTER) {
                    if ($matches !== []) {
                        return $this->chosen($label, $matches, $cursor, $filter, $renderedLines);
                    }
                    continue;
                }

                if ($key === KeyMap::UP) {
                    $cursor = $cursor > 0 ? $cursor - 1 : 0;
                    continue;
                }

                if ($key === KeyMap::DOWN) {
                    $cursor = $cursor + 1;
                    continue;
                }

                if ($key === KeyMap::BACKSPACE) {
                    if ($filter !== '') {
                        $filter = substr($filter, 0, -1);
                        $cursor = 0;
                    }
                    continue;
                }

                if (KeyMap::isPrintable($key)) {
                    $filter .= $key;
                    // 絞り込みが変われば候補の並びが変わるのでカーソルを先頭へ戻す
                    $cursor = 0;
                }
            }
        } finally {
            $this->terminal->restoreMode();
        }
    }

    /**
     * 確定した候補を返し、リストを回答済みの1行に畳む。
     *
     * @param array<string,string> $matches
     */
    private function chosen(string $label, array $matches, int $cursor, string $filter, int $renderedLines): string
    {
        $value = array_keys($matches)[$cursor];
        $this->collapse($label, $matches[$value], $renderedLines, $this->questionWidth($label, mb_strwidth($filter)));

        return $value;
    }

    /**
     * @param array<string,string> $items
     * @return array<string,string>
     */
    private function filter(array $items, string $filter): array
    {
        if ($filter === '') {
            return $items;
        }

        $needle = strtolower($filter);
        $matches = [];

        foreach ($items as $value => $itemLabel) {
            if (str_contains(strtolower($itemLabel), $needle)) {
                $matches[$value] = $itemLabel;
            }
        }

        return $matches;
    }

    private function clamp(int $cursor, int $count): int
    {
        if ($count === 0) {
            return 0;
        }

        if ($cursor < 0) {
            return 0;
        }

        if ($cursor > $count - 1) {
            return $count - 1;
        }

        return $cursor;
    }

    /**
     * 打った絞り込みはラベルと同じ行に出す。別に filter: 行を立てると
     * 入力の位置が他のプロンプトとずれる。
     *
     * @param array<string,string> $matches
     * @return int 描いた行数
     */
    private function render(string $label, string $filter, array $matches, int $cursor, int $total): int
    {
        $this->terminal->write($this->question($label) . $filter . "\r\n");
        $lines = 1;

        $labels = array_values($matches);
        $window = $this->window($cursor, count($labels));

        foreach ($window as $index) {
            $marker = $index === $cursor ? $this->style->accent('❯') : ' ';
            $text = $index === $cursor ? $this->style->bold($labels[$index]) : $labels[$index];
            $this->terminal->write('  ' . $marker . ' ' . $text . "\r\n");
            $lines++;
        }

        if ($matches === []) {
            $this->terminal->write('  ' . $this->style->warn('no match') . "\r\n");
            $lines++;
        }

        $this->terminal->write(
            '  ' . $this->style->dim(sprintf('%d/%d · type to filter', count($matches), $total)) . "\r\n",
        );
        $lines++;

        return $lines;
    }

    /**
     * カーソルが常に見える範囲のインデックス列を返す。
     *
     * @return int[]
     */
    private function window(int $cursor, int $count): array
    {
        if ($count === 0) {
            return [];
        }

        $start = 0;

        if ($count > $this->visibleRows && $cursor >= $this->visibleRows) {
            $start = $cursor - $this->visibleRows + 1;
        }

        $end = min($count, $start + $this->visibleRows);
        $indexes = [];

        for ($i = $start; $i < $end; $i++) {
            $indexes[] = $i;
        }

        return $indexes;
    }

    /** 未回答の質問行。絞り込みの入力はこの行の続きに来る。 */
    private function question(string $label): string
    {
        return $this->style->accent('?') . ' ' . $label . ' '
            . $this->style->dim(self::PENDING_MARK) . ' ';
    }

    /** 質問行の表示幅。'? ' + ラベル + ' > ' + 絞り込み */
    private function questionWidth(string $label, int $inputWidth): int
    {
        return 2 + mb_strwidth($label) + 3 + $inputWidth;
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
     * 出しているリストを「✔ ラベル · 値」1行に畳む。端末幅に収まらない
     * ときは畳まず、出ているものをそのまま残す。TTY でなければカーソル
     * 移動に意味がないので何もしない。
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
