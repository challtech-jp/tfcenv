<?php

namespace Tfcenv\Tfc;

final class WorkspaceRepository
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly Client $client,
    ) {
    }

    /**
     * organization の全ワークスペースを name の昇順で返す。
     * ページングは meta.pagination.next-page が null になるまで辿る。
     *
     * @return Workspace[]
     */
    public function listAll(string $organization): array
    {
        $workspaces = [];
        $page = 1;

        while (true) {
            $path = sprintf(
                '/organizations/%s/workspaces?%s',
                rawurlencode($organization),
                http_build_query(['page' => ['size' => self::PAGE_SIZE, 'number' => $page]]),
            );

            $document = $this->client->get($path);
            $resources = is_array($document['data'] ?? null) ? $document['data'] : [];

            foreach ($resources as $resource) {
                if (!is_array($resource)) {
                    continue;
                }
                $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
                $id = (string) ($resource['id'] ?? '');
                $name = (string) ($attributes['name'] ?? '');

                // A workspace we cannot name is not selectable in the picker, and an empty id
                // would collide with every other malformed entry in the id => name map.
                if ($id === '' || $name === '') {
                    continue;
                }

                $workspaces[] = new Workspace($id, $name);
            }

            $next = $document['meta']['pagination']['next-page'] ?? null;

            if ($next === null) {
                break;
            }

            // Terminate rather than hang if the API ever fails to advance the cursor.
            if ((int) $next <= $page) {
                break;
            }

            $page = (int) $next;
        }

        usort($workspaces, static fn(Workspace $a, Workspace $b): int => strcmp($a->name, $b->name));

        return $workspaces;
    }
}
