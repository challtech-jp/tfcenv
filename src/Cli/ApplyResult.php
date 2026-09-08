<?php

namespace Tfcenv\Cli;

final class ApplyResult
{
    public function __construct(
        public readonly int $succeeded,
        public readonly int $failed,
    ) {
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
