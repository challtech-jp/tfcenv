<?php

namespace Tfcenv\Cli;

use Tfcenv\Terminal\Prompt;
use Tfcenv\Terminal\Style;
use Tfcenv\Terminal\Terminal;

final class Confirmation
{
    /** 桁の間隔。列の区切りが見えるだけの最小限。 */
    private const GUTTER = '  ';

    public function __construct(
        private readonly Terminal $terminal,
        private readonly Style $style,
        private readonly Prompt $prompt,
    ) {
    }

    public function ask(ChangeSet $set): bool
    {
        if ($set->isEmpty()) {
            $this->terminal->write($this->style->dim('nothing to apply') . "\n");

            return false;
        }

        $this->terminal->write("\n");
        $this->terminal->write('  ' . $this->style->bold($set->organization . '/' . $set->workspace->name) . "\n\n");

        $keyWidth = 0;
        $valueWidth = 0;

        foreach ($set->changes as $change) {
            $keyWidth = max($keyWidth, mb_strwidth($change->variable->key));
            $valueWidth = max($valueWidth, mb_strwidth($change->variable->displayValue()));
        }

        foreach ($set->changes as $change) {
            $this->terminal->write(
                '    ' . $this->label($change->op) . self::GUTTER
                . $this->pad($change->variable->key, $keyWidth) . self::GUTTER
                . $this->pad($change->variable->displayValue(), $valueWidth) . self::GUTTER
                . $this->style->dim($this->attributes($change)) . "\n"
            );
        }

        $this->terminal->write("\n");

        // 内訳は上の行がそのまま見せているので、質問文では数だけを聞く。
        // 既定は false のまま渡すこと。空の返事は EOF の可能性があり、
        // 既定を true にすると入力が途切れただけで書き込んでしまう。
        return $this->prompt->confirm(sprintf(
            'Apply %d %s?',
            count($set->changes),
            count($set->changes) === 1 ? 'change' : 'changes',
        ), false);
    }

    private function label(ChangeOp $op): string
    {
        return match ($op) {
            ChangeOp::Create => $this->style->ok('create'),
            ChangeOp::Update => $this->style->warn('update'),
        };
    }

    private function attributes(Change $change): string
    {
        $parts = [$change->variable->category->value];
        $parts[] = $change->variable->sensitive ? 'sensitive' : 'plain';

        if ($change->variable->description !== '') {
            $parts[] = '"' . $change->variable->description . '"';
        }

        return implode(' · ', $parts);
    }

    /**
     * 桁揃えは mb_strwidth で測る。キーにも説明にも全角が入りうるので、
     * strlen だとバイト数で埋めてしまい列が崩れる。
     */
    private function pad(string $text, int $width): string
    {
        $gap = $width - mb_strwidth($text);

        return $gap > 0 ? $text . str_repeat(' ', $gap) : $text;
    }
}
