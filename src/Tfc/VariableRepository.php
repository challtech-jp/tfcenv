<?php

namespace Tfcenv\Tfc;

final class VariableRepository
{
    public function __construct(
        private readonly Client $client,
    ) {
    }

    /**
     * ワークスペースの既存変数を key で引ける形で返す。
     * sensitive な変数は value が返らないので空文字列になる。
     *
     * @return array<string, Variable>
     */
    public function listFor(string $workspaceId): array
    {
        $document = $this->client->get(sprintf('/workspaces/%s/vars', rawurlencode($workspaceId)));
        $resources = is_array($document['data'] ?? null) ? $document['data'] : [];

        $variables = [];

        foreach ($resources as $resource) {
            if (!is_array($resource)) {
                continue;
            }
            $variable = Variable::fromApi($resource);
            if ($variable->key !== '') {
                $variables[$variable->key] = $variable;
            }
        }

        return $variables;
    }

    public function create(string $workspaceId, Variable $variable): Variable
    {
        $document = $this->client->post(
            sprintf('/workspaces/%s/vars', rawurlencode($workspaceId)),
            $variable->payload(),
        );

        return $this->fromDocument($document, $variable);
    }

    public function update(string $workspaceId, Variable $variable): Variable
    {
        if ($variable->id === null || $variable->id === '') {
            throw new TfcException(
                sprintf('Cannot update the variable "%s" without an id', $variable->key),
                0,
            );
        }

        $document = $this->client->patch(
            sprintf('/workspaces/%s/vars/%s', rawurlencode($workspaceId), rawurlencode($variable->id)),
            $variable->updateDocument(),
        );

        return $this->fromDocument($document, $variable);
    }

    /**
     * 応答の resource object を読む。TFC は create に 201、update に 200 で
     * リソースを返すので、data が無い 2xx は「書き込みが適用されたか確認できない」
     * 状態であり、送った値を成功として返してはいけない。
     */
    private function fromDocument(array $document, Variable $variable): Variable
    {
        $resource = $document['data'] ?? null;

        if (!is_array($resource)) {
            throw new TfcException(
                sprintf(
                    'Terraform Cloud accepted the write for "%s" but returned no variable, '
                    . 'so it cannot be confirmed. Re-run to check whether it was applied.',
                    $variable->key,
                ),
                0,
            );
        }

        return Variable::fromApi($resource);
    }
}
