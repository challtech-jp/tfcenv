<?php

namespace Tfcenv\Tfc;

final class Workspace
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
    }
}
