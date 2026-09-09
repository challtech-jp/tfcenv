<?php

namespace Tfcenv\Cli;

use Tfcenv\Terminal\CancelledException;
use Tfcenv\Terminal\Picker;
use Tfcenv\Terminal\Prompt;
use Tfcenv\Terminal\Style;
use Tfcenv\Terminal\Terminal;
use Tfcenv\Tfc\Category;
use Tfcenv\Tfc\Variable;
use Tfcenv\Tfc\VariableRepository;
use Tfcenv\Tfc\Workspace;
use Tfcenv\Tfc\WorkspaceRepository;

final class AddCommand
{
    private const MAX_EMPTY_KEYS = 3;

    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly VariableRepository $variables,
        private readonly Terminal $terminal,
        private readonly Style $style,
        private readonly Prompt $prompt,
        private readonly Picker $picker,
        private readonly Confirmation $confirmation,
        private readonly Applier $applier,
        private readonly string $defaultOrganization,
    ) {
    }

    public function run(): int
    {
        if (!$this->terminal->isTty()) {
            $this->terminal->writeError(
                "tfcenv add is interactive and needs a TTY.\n"
                . "Run it from a terminal, or pass the variable as arguments: tfcenv add -w NAME KEY=VALUE\n",
            );

            return 1;
        }

        try {
            return $this->interact();
        } catch (CancelledException $e) {
            $this->terminal->write("\n" . $this->style->dim('cancelled') . "\n");

            return 1;
        }
    }

    private function interact(): int
    {
        $organization = $this->prompt->text('Organization', $this->defaultOrganization);

        if ($organization === '') {
            $this->terminal->writeError("An organization is required.\n");

            return 1;
        }

        $workspace = $this->chooseWorkspace($organization);

        if ($workspace === null) {
            return 1;
        }

        $existing = $this->variables->listFor($workspace->id);
        $this->terminal->write($this->style->dim(sprintf(
            '  %d existing variable(s) in %s',
            count($existing),
            $workspace->name,
        )) . "\n");

        $changes = $this->collectChanges($existing);
        $set = new ChangeSet($organization, $workspace, $changes);

        if (!$this->confirmation->ask($set)) {
            return 0;
        }

        return $this->applier->apply($set)->hasFailures() ? 1 : 0;
    }

    private function chooseWorkspace(string $organization): ?Workspace
    {
        $workspaces = $this->workspaces->listAll($organization);

        if ($workspaces === []) {
            $this->terminal->writeError(sprintf(
                "The organization \"%s\" has no workspaces, or the token cannot see them.\n",
                $organization,
            ));

            return null;
        }

        $items = [];

        foreach ($workspaces as $workspace) {
            $items[$workspace->id] = $workspace->name;
        }

        $chosenId = $this->picker->pick('Workspace', $items);

        foreach ($workspaces as $workspace) {
            if ($workspace->id === $chosenId) {
                return $workspace;
            }
        }

        return null;
    }

    /**
     * @param array<string, Variable> $existing
     * @return Change[]
     */
    private function collectChanges(array $existing): array
    {
        $changes = [];
        $collectedKeys = [];

        while (true) {
            $change = $this->collectOne($existing, $collectedKeys);

            if ($change !== null) {
                $changes[] = $change;
                $collectedKeys[$change->variable->key] = true;
            }

            if (!$this->prompt->confirm('Add another variable?', false)) {
                return $changes;
            }
        }
    }

    /**
     * 1件分を対話で組み立てる。Skip が選ばれたら null。
     *
     * @param array<string, Variable> $existing
     * @param array<string, bool> $collectedKeys このバッチで既に積んだキー
     */
    private function collectOne(array $existing, array $collectedKeys): ?Change
    {
        $emptyKeys = 0;

        while (true) {
            $key = $this->prompt->text('Key');

            if ($key === '') {
                $emptyKeys++;

                // EOF は readLine() が null を返して CancelledException になるので、
                // ここに来るのは本当に空行を入れられた場合だけ。それでも同じ質問を
                // 際限なく出し直さないよう、繰り返しの回数で打ち切る。
                if ($emptyKeys >= self::MAX_EMPTY_KEYS) {
                    throw new CancelledException('no key was entered');
                }

                $this->terminal->write($this->style->dim('  a key is required') . "\n");
                continue;
            }

            $emptyKeys = 0;

            if (isset($collectedKeys[$key])) {
                // 衝突検出は「送る前に気づく」ためにある。同一バッチ内の重複を
                // apply 時の 422 まで持ち越さない。既に積んだ変更は Create で id を
                // 持たないので、更新メニューを出すと id なしの PATCH になってしまう。
                $this->terminal->write(
                    $this->style->warn('  ' . $key . ' was already added in this session') . "\n"
                );
                continue;
            }

            if (!isset($existing[$key])) {
                return new Change(ChangeOp::Create, $this->askNewVariable($key));
            }

            $current = $existing[$key];
            $this->showCollision($current);

            $choice = $this->prompt->select('What now?', [
                'value' => 'Update the value',
                'attributes' => 'Update the value and attributes',
                'skip' => 'Skip this variable',
                'rename' => 'Use a different key',
            ]);

            if ($choice === 'skip') {
                return null;
            }

            if ($choice === 'rename') {
                continue;
            }

            if ($choice === 'value') {
                return new Change(ChangeOp::Update, $current->withValue($this->askValue($current->sensitive)));
            }

            return new Change(ChangeOp::Update, $this->askUpdatedVariable($current));
        }
    }

    private function showCollision(Variable $current): void
    {
        $this->terminal->write(sprintf(
            "\n  %s %s already exists\n      %s / %s\n",
            $this->style->warn('!'),
            $current->key,
            $current->category->value,
            $current->sensitive ? 'sensitive' : 'plain',
        ));

        if ($current->description !== '') {
            $this->terminal->write('      "' . $current->description . '"' . "\n");
        }

        $this->terminal->write("\n");
    }

    private function askNewVariable(string $key): Variable
    {
        $category = $this->askCategory(Category::Terraform);
        $sensitive = $this->askSensitive(true);
        $value = $this->askValue($sensitive);
        $description = $this->prompt->text('Description');

        return new Variable($key, $value, $category, $sensitive, $description);
    }

    private function askUpdatedVariable(Variable $current): Variable
    {
        $category = $this->askCategory($current->category);
        $sensitive = $this->askSensitive($current->sensitive);
        $value = $this->askValue($sensitive);
        $description = $this->prompt->text('Description', $current->description);

        return new Variable($current->key, $value, $category, $sensitive, $description, $current->id);
    }

    private function askCategory(Category $default): Category
    {
        $chosen = $this->prompt->select('Category', [
            Category::Terraform->value => Category::Terraform->value,
            Category::Env->value => Category::Env->value,
        ], $default->value);

        return Category::from($chosen);
    }

    private function askSensitive(bool $default): bool
    {
        $chosen = $this->prompt->select('Sensitive', [
            'yes' => 'Yes',
            'no' => 'No',
        ], $default ? 'yes' : 'no');

        return $chosen === 'yes';
    }

    /**
     * sensitive を先に聞いてから value を聞く。そうしないと
     * 非表示にすべきかどうかが決まらない。
     */
    private function askValue(bool $sensitive): string
    {
        if ($sensitive) {
            return $this->prompt->hidden('Value');
        }

        return $this->prompt->text('Value');
    }
}
