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

    /**
     * 名前でワークスペースを1件引く。TFC の show エンドポイントは
     * organization + name を直接受けるので、一覧をページングで辿る必要がない。
     * 非対話モードは1回の実行で1変数しか登録しないため、ここが速いかどうかが
     * まとめて登録するときの体感を決める。
     *
     * 存在しなければ null。それ以外の失敗（401 など）はそのまま投げる。
     */
    public function findByName(string $organization, string $name): ?Workspace
    {
        $path = sprintf(
            '/organizations/%s/workspaces/%s',
            rawurlencode($organization),
            rawurlencode($name),
        );

        try {
            $document = $this->client->get($path);
        } catch (TfcException $e) {
            if ($e->status() === 404) {
                return null;
            }

            throw $e;
        }

        $resource = is_array($document['data'] ?? null) ? $document['data'] : [];
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
        $id = (string) ($resource['id'] ?? '');

        // id が無いと以降の /workspaces/{id}/vars が組み立てられない。
        // 空 id で送って別の何かを書き換えるより、ここで止める。
        if ($id === '') {
            throw new TfcException(sprintf(
                'Terraform Cloud returned no id for the workspace "%s".',
                $name,
            ), 0);
        }

        $returned = (string) ($attributes['name'] ?? '');

        return new Workspace($id, $returned === '' ? $name : $returned);
    }
}
