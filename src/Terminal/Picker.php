<?php

namespace Tfcenv\Terminal;

final class Picker
{
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

        $this->terminal->write($this->style->accent('?') . ' ' . $label . "\n");
        $this->terminal->enterRawMode();

        try {
            while (true) {
                $matches = $this->filter($items, $filter);
                $cursor = $this->clamp($cursor, count($matches));

                $this->clearLines($renderedLines);
                $renderedLines = $this->render($filter, $matches, $cursor, count($items));

                $key = $this->terminal->readKey();

                if ($key === KeyMap::CANCEL) {
                    throw new CancelledException('cancelled');
                }

                if ($key === KeyMap::EOF) {
                    if ($matches === []) {
                        throw new CancelledException('no candidate to select');
                    }
                    return array_keys($matches)[$cursor];
                }

                if ($key === KeyMap::ENTER) {
                    if ($matches !== []) {
                        return array_keys($matches)[$cursor];
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
     * @param array<string,string> $matches
     * @return int 描いた行数
     */
    private function render(string $filter, array $matches, int $cursor, int $total): int
    {
        $this->terminal->write('  ' . $this->style->dim('filter:') . ' ' . $filter . "\r\n");
        $lines = 1;

        $labels = array_values($matches);
        $window = $this->window($cursor, count($labels));

        foreach ($window as $index) {
            $marker = $index === $cursor ? $this->style->accent('>') : ' ';
            $text = $index === $cursor ? $this->style->bold($labels[$index]) : $labels[$index];
            $this->terminal->write('  ' . $marker . ' ' . $text . "\r\n");
            $lines++;
        }

        if ($matches === []) {
            $this->terminal->write('  ' . $this->style->warn('no match') . "\r\n");
            $lines++;
        }

        $this->terminal->write(
            '  ' . $this->style->dim(sprintf('%d/%d shown, type to filter', count($matches), $total)) . "\r\n",
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

    private function clearLines(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->terminal->write("\e[1A\e[2K");
        }
    }
}
