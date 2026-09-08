<?php

namespace Tfcenv\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tfcenv\Cli\Change;
use Tfcenv\Cli\ChangeOp;
use Tfcenv\Cli\ChangeSet;
use Tfcenv\Tfc\Category;
use Tfcenv\Tfc\Variable;
use Tfcenv\Tfc\Workspace;

final class ChangeSetTest extends TestCase
{
    public function testItCountsCreatesAndUpdatesSeparately(): void
    {
        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), [
            new Change(ChangeOp::Create, new Variable('a', 'v', Category::Terraform, false, '')),
            new Change(ChangeOp::Create, new Variable('b', 'v', Category::Terraform, false, '')),
            new Change(ChangeOp::Update, new Variable('c', 'v', Category::Terraform, false, '', 'var-3')),
        ]);

        $this->assertSame(2, $set->countOf(ChangeOp::Create));
        $this->assertSame(1, $set->countOf(ChangeOp::Update));
        $this->assertFalse($set->isEmpty());
    }

    public function testAnEmptySetReportsItself(): void
    {
        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), []);

        $this->assertTrue($set->isEmpty());
        $this->assertSame(0, $set->countOf(ChangeOp::Create));
    }
}
