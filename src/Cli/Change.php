<?php

namespace Tfcenv\Cli;

use Tfcenv\Tfc\Variable;

final class Change
{
    public function __construct(
        public readonly ChangeOp $op,
        public readonly Variable $variable,
    ) {
    }
}
