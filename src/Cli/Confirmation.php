<?php

namespace Tfcenv\Cli;

use Tfcenv\Terminal\Prompt;
use Tfcenv\Terminal\Style;
use Tfcenv\Terminal\Terminal;

final class Confirmation
{
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
        $this->terminal->write($this->style->bold($set->organization . '/' . $set->workspace->name) . "\n");

        foreach ($set->changes as $change) {
            $this->terminal->write(sprintf(
                '  %s  %s = %s  %s%s',
                $this->label($change->op),
                $change->variable->key,
                $change->variable->displayValue(),
                $this->style->dim($this->attributes($change)),
                "\n",
            ));
        }

        $this->terminal->write("\n");

        return $this->prompt->confirm(sprintf(
            'Apply %d change(s)? (create %d / update %d)',
            count($set->changes),
            $set->countOf(ChangeOp::Create),
            $set->countOf(ChangeOp::Update),
        ));
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

        return '(' . implode(', ', $parts) . ')';
    }
}
