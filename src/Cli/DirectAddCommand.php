<?php

namespace Tfcenv\Cli;

use Tfcenv\Terminal\Terminal;
use Tfcenv\Tfc\Category;
use Tfcenv\Tfc\Variable;
use Tfcenv\Tfc\VariableRepository;
use Tfcenv\Tfc\WorkspaceRepository;

/**
 * 引数だけで1変数を登録する非対話モード。TTY を要求しないので CI やスクリプトから
 * 呼べる。確認画面は出さない — コマンドラインに書いた内容そのものが確認であり、
 * 既存の値を壊しうる操作には --update という明示的なフラグを要求している。
 *
 * ChangeSet と Applier は対話モードと共通なので、出力もマスキングも同じになる。
 */
final class DirectAddCommand
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly VariableRepository $variables,
        private readonly Terminal $terminal,
        private readonly Applier $applier,
    ) {
    }

    public function run(AddOptions $options): int
    {
        $workspace = $this->workspaces->findByName($options->organization, $options->workspace);

        if ($workspace === null) {
            $this->terminal->writeError(sprintf(
                "The workspace \"%s\" does not exist in %s, or the token cannot see it.\n",
                $options->workspace,
                $options->organization,
            ));

            return 1;
        }

        $existing = $this->variables->listFor($workspace->id);
        $current = $existing[$options->key] ?? null;

        if ($current !== null && !$options->update) {
            $this->terminal->writeError(sprintf(
                "%s already exists in %s. Pass --update to overwrite it.\nNothing was sent.\n",
                $options->key,
                $workspace->name,
            ));

            return 1;
        }

        $change = $current === null
            ? new Change(ChangeOp::Create, $this->created($options))
            : new Change(ChangeOp::Update, $this->updated($options, $current));

        $set = new ChangeSet($options->organization, $workspace, [$change]);

        return $this->applier->apply($set)->hasFailures() ? 1 : 0;
    }

    /**
     * 新規作成では指定されなかった属性を既定値に落とす。sensitive の既定が false
     * なのは、TFC が sensitive を false に戻すことを許さないため
     * （HTTP 422 / Sensitive cannot be changed from true to false on saved records）。
     * 打ち間違いで sensitive にすると作り直すまで元に戻せないので、opt-in にする。
     */
    private function created(AddOptions $options): Variable
    {
        return new Variable(
            $options->key,
            $options->value,
            $options->category ?? Category::Terraform,
            $options->sensitive ?? false,
            $options->description ?? '',
        );
    }

    /**
     * 更新では指定されなかった属性を TFC 側の現在値から引き継ぐ。既定値に落とすと、
     * sensitive な変数を更新するたびに sensitive: false を送ることになって
     * 必ず 422 になり、category や description も黙って書き換わってしまう。
     */
    private function updated(AddOptions $options, Variable $current): Variable
    {
        return new Variable(
            $current->key,
            $options->value,
            $options->category ?? $current->category,
            $options->sensitive ?? $current->sensitive,
            $options->description ?? $current->description,
            $current->id,
        );
    }
}
