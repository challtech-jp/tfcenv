<?php

namespace Tfcenv\Cli;

use Tfcenv\Tfc\Workspace;

/**
 * 適用する変更のまとまり。対話でも JSON でも、入力方法によらずこの形にしてから
 * 確認と適用に渡す。
 */
final class ChangeSet
{
    /**
     * @param Change[] $changes
     */
    public function __construct(
        public readonly string $organization,
        public readonly Workspace $workspace,
        public readonly array $changes,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    public function countOf(ChangeOp $op): int
    {
        $count = 0;

        foreach ($this->changes as $change) {
            if ($change->op === $op) {
                $count++;
            }
        }

        return $count;
    }
}
