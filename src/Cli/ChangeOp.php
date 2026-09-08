<?php

namespace Tfcenv\Cli;

enum ChangeOp: string
{
    case Create = 'create';
    case Update = 'update';
}
