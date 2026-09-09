<?php

namespace Tfcenv\Tests\Tfc;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tests\Support\FakeTransport;
use Tfcenv\Tfc\Client;
use Tfcenv\Tfc\TfcException;
use Tfcenv\Tfc\WorkspaceRepository;

final class WorkspaceRepositoryTest extends TestCase
{
    public function testItAsksForTheLargestPageSize(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, $this->page([], null));
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $repository->listAll('acme');

        $url = $transport->lastRequest()->url;
        $this->assertStringContainsString('/organizations/acme/workspaces', $url);
        $this->assertStringContainsString('page%5Bsize%5D=100', $url);
        $this->assertStringContainsString('page%5Bnumber%5D=1', $url);
    }

    public function testItFollowsPaginationUntilNextPageIsNull(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, $this->page([['ws-1', 'alpha-core-prod']], 2));
        $transport->queue(200, $this->page([['ws-2', 'alpha-core-stg']], 3));
        $transport->queue(200, $this->page([['ws-3', 'beta-core-prod']], null));
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $workspaces = $repository->listAll('acme');

        $this->assertCount(3, $workspaces);
        $this->assertCount(3, $transport->requests());
        $this->assertStringContainsString('page%5Bnumber%5D=3', $transport->lastRequest()->url);
    }

    public function testItReturnsIdAndNameSortedByName(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, $this->page([
            ['ws-2', 'alpha-core-stg'],
            ['ws-1', 'alpha-core-prod'],
        ], null));
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $workspaces = $repository->listAll('acme');

        $this->assertSame('alpha-core-prod', $workspaces[0]->name);
        $this->assertSame('ws-1', $workspaces[0]->id);
        $this->assertSame('alpha-core-stg', $workspaces[1]->name);
    }

    public function testAnOrganizationWithNoWorkspacesReturnsAnEmptyList(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, $this->page([], null));
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $this->assertSame([], $repository->listAll('acme'));
    }

    public function testItSkipsResourcesWithoutAnIdOrName(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, json_encode([
            'data' => [
                ['id' => 'ws-1', 'attributes' => ['name' => 'alpha-core-prod']],
                ['id' => '', 'attributes' => ['name' => 'no-id']],
                ['id' => 'ws-3', 'attributes' => []],
                ['id' => 'ws-4'],
            ],
            'meta' => ['pagination' => ['next-page' => null]],
        ]));
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $workspaces = $repository->listAll('acme');

        $this->assertCount(1, $workspaces);
        $this->assertSame('ws-1', $workspaces[0]->id);
        $this->assertSame('alpha-core-prod', $workspaces[0]->name);
    }

    public function testItStopsRatherThanLoopingWhenNextPageDoesNotAdvance(): void
    {
        $transport = new FakeTransport();
        // next-page points back at the page we just fetched. Without a guard this
        // would request page 1 forever.
        $transport->queue(200, $this->page([['ws-1', 'alpha-core-prod']], 1));
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $workspaces = $repository->listAll('acme');

        $this->assertCount(1, $workspaces);
        $this->assertCount(1, $transport->requests());
    }

    /**
     * @param array<array{0:string,1:string}> $workspaces
     */
    private function page(array $workspaces, ?int $nextPage): string
    {
        $data = [];

        foreach ($workspaces as $workspace) {
            $data[] = [
                'id' => $workspace[0],
                'type' => 'workspaces',
                'attributes' => ['name' => $workspace[1]],
            ];
        }

        return json_encode([
            'data' => $data,
            'meta' => ['pagination' => ['next-page' => $nextPage]],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function testFindByNameAsksTheShowEndpointDirectly(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, $this->workspaceDocument('ws-7', 'alpha-core-stg'));
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $workspace = $repository->findByName('acme', 'alpha-core-stg');

        $this->assertNotNull($workspace);
        $this->assertSame('ws-7', $workspace->id);
        $this->assertSame('alpha-core-stg', $workspace->name);
        $this->assertCount(1, $transport->requests());
        $this->assertStringEndsWith(
            '/organizations/acme/workspaces/alpha-core-stg',
            $transport->lastRequest()->url,
        );
    }

    public function testFindByNameEscapesTheOrganizationAndTheName(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, $this->workspaceDocument('ws-1', 'a b'));
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $repository->findByName('my org', 'a b');

        $this->assertStringEndsWith('/organizations/my%20org/workspaces/a%20b', $transport->lastRequest()->url);
    }

    public function testFindByNameReturnsNullWhenTheWorkspaceDoesNotExist(): void
    {
        $transport = new FakeTransport();
        $transport->queue(404, '{"errors":[{"detail":"not found"}]}');
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $this->assertNull($repository->findByName('acme', 'nope'));
    }

    public function testFindByNameLetsOtherFailuresThrough(): void
    {
        $transport = new FakeTransport();
        $transport->queue(401, '{"errors":[{"detail":"unauthorized"}]}');
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $this->expectException(TfcException::class);

        $repository->findByName('acme', 'alpha-core-stg');
    }

    public function testFindByNameRejectsAResponseWithoutAnId(): void
    {
        $transport = new FakeTransport();
        $transport->queue(200, '{"data":{"attributes":{"name":"alpha-core-stg"}}}');
        $repository = new WorkspaceRepository(new Client($transport, 'tok'));

        $this->expectException(TfcException::class);

        $repository->findByName('acme', 'alpha-core-stg');
    }

    private function workspaceDocument(string $id, string $name): string
    {
        return (string) json_encode([
            'data' => ['id' => $id, 'type' => 'workspaces', 'attributes' => ['name' => $name]],
        ]);
    }
}
