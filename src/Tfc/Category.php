<?php

namespace Tfcenv\Tfc;

enum Category: string
{
    case Terraform = 'terraform';
    case Env = 'env';

    public function label(): string
    {
        return match ($this) {
            Category::Terraform => 'Terraform variable',
            Category::Env => 'Environment variable',
        };
    }
}
