<?php

namespace Tfcenv\Tests\Tfc;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tfc\Category;

final class CategoryTest extends TestCase
{
    public function testBackedValuesMatchTheApi(): void
    {
        $this->assertSame('terraform', Category::Terraform->value);
        $this->assertSame('env', Category::Env->value);
    }

    public function testFromParsesApiValues(): void
    {
        $this->assertSame(Category::Terraform, Category::from('terraform'));
        $this->assertSame(Category::Env, Category::from('env'));
    }

    public function testLabelDescribesTheCategory(): void
    {
        $this->assertSame('Terraform variable', Category::Terraform->label());
        $this->assertSame('Environment variable', Category::Env->label());
    }
}
