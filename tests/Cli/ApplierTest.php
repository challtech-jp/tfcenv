<?php

namespace Tfcenv\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tfcenv\Cli\Applier;
use Tfcenv\Cli\Change;
use Tfcenv\Cli\ChangeOp;
use Tfcenv\Cli\ChangeSet;
use Tfcenv\Tests\Support\FakeTerminal;
use Tfcenv\Tests\Support\FakeTransport;
use Tfcenv\Terminal\Style;
use Tfcenv\Tfc\Category;
use Tfcenv\Tfc\Client;
use Tfcenv\Tfc\Variable;
use Tfcenv\Tfc\VariableRepository;
use Tfcenv\Tfc\Workspace;

final class ApplierTest extends TestCase
{
    private function applier(FakeTransport $transport, FakeTerminal $terminal): Applier
    {
        return new Applier(
            new VariableRepository(new Client($transport, 'tok')),
            $terminal,
            new Style(false),
        );
    }

    public function testItPostsCreatesAndPatchesUpdates(): void
    {
        $transport = new FakeTransport();
        $transport->queue(201, '{"data":{"id":"var-1","attributes":{"key":"a","category":"terraform","sensitive":false,"description":""}}}');
        $transport->queue(200, '{"data":{"id":"var-2","attributes":{"key":"b","category":"terraform","sensitive":false,"description":""}}}');
        $terminal = new FakeTerminal();

        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), [
            new Change(ChangeOp::Create, new Variable('a', 'v', Category::Terraform, false, '')),
            new Change(ChangeOp::Update, new Variable('b', 'v', Category::Terraform, false, '', 'var-2')),
        ]);

        $result = $this->applier($transport, $terminal)->apply($set);

        $this->assertSame(2, $result->succeeded);
        $this->assertSame(0, $result->failed);
        $this->assertFalse($result->hasFailures());
        $this->assertSame('POST', $transport->requests()[0]->method);
        $this->assertSame('PATCH', $transport->requests()[1]->method);
    }

    public function testAFailureDoesNotStopTheRest(): void
    {
        $transport = new FakeTransport();
        $transport->queue(422, '{"errors":[{"detail":"Key has already been taken"}]}');
        $transport->queue(201, '{"data":{"id":"var-2","attributes":{"key":"b","category":"terraform","sensitive":false,"description":""}}}');
        $terminal = new FakeTerminal();

        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), [
            new Change(ChangeOp::Create, new Variable('a', 'v', Category::Terraform, false, '')),
            new Change(ChangeOp::Create, new Variable('b', 'v', Category::Terraform, false, '')),
        ]);

        $result = $this->applier($transport, $terminal)->apply($set);

        $this->assertSame(1, $result->succeeded);
        $this->assertSame(1, $result->failed);
        $this->assertTrue($result->hasFailures());
        $this->assertCount(2, $transport->requests());
        $this->assertStringContainsString('Key has already been taken', $terminal->output());
    }

    public function testItNeverPrintsASensitiveValue(): void
    {
        $transport = new FakeTransport();
        $transport->queue(422, '{"errors":[{"detail":"nope"}]}');
        $terminal = new FakeTerminal();

        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), [
            new Change(ChangeOp::Create, new Variable('k', 'super-secret', Category::Terraform, true, '')),
        ]);

        $this->applier($transport, $terminal)->apply($set);

        $this->assertStringNotContainsString('super-secret', $terminal->output());
        // 何も書かない実装でも上のアサーションは通ってしまうので、
        // 出力が実際に行われたことも確かめる。
        $this->assertStringContainsString('0 succeeded, 1 failed', $terminal->output());
    }

    public function testItRedactsASensitiveValueEchoedBackInAnErrorMessage(): void
    {
        // TFC の errors[].detail が送った値を含んで返してきても漏らさない。
        // 値だけを差し替えるので失敗理由は読めるままにする。
        $transport = new FakeTransport();
        $transport->queue(422, '{"errors":[{"detail":"the value \"super-secret\" is not allowed"}]}');
        $terminal = new FakeTerminal();

        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), [
            new Change(ChangeOp::Create, new Variable('k', 'super-secret', Category::Terraform, true, '')),
        ]);

        $this->applier($transport, $terminal)->apply($set);

        $this->assertStringNotContainsString('super-secret', $terminal->output());
        $this->assertStringContainsString('••••••••', $terminal->output());
        $this->assertStringContainsString('is not allowed', $terminal->output());
    }

    public function testItReportsProgressPerVariable(): void
    {
        $transport = new FakeTransport();
        $transport->queue(201, '{"data":{"id":"var-1","attributes":{"key":"a","category":"terraform","sensitive":false,"description":""}}}');
        $terminal = new FakeTerminal();

        $set = new ChangeSet('acme', new Workspace('ws-1', 'alpha-core-prod'), [
            new Change(ChangeOp::Create, new Variable('a', 'v', Category::Terraform, false, '')),
        ]);

        $this->applier($transport, $terminal)->apply($set);

        $this->assertStringContainsString('a', $terminal->output());
        $this->assertStringContainsString('1 succeeded', $terminal->output());
    }
}
