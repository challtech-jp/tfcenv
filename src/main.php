<?php

/**
 * Toolchain smoke test.
 *
 * TypePHP binary mode requires a global main() returning void, and forbids
 * top-level executable statements. The real CLI replaces this.
 */
function main(int $argc, array $argv): void
{
    echo "tfcenv (toolchain smoke test)\n";
    echo "  argc: {$argc}\n";

    for ($i = 0; $i < $argc; $i++) {
        echo "  argv[{$i}]: {$argv[$i]}\n";
    }
}
