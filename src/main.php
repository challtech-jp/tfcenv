<?php

use Tfcenv\Cli\Application;
use Tfcenv\Terminal\SttyTerminal;

/**
 * TypePHP の binary モードはグローバルな main() を要求する。
 * シグネチャは固定で、返り値は void なので終了コードは exit() で返す。
 */
function main(int $argc, array $argv): void
{
    exit((new Application(new SttyTerminal()))->run($argc, $argv));
}
