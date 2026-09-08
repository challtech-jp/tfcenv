<?php

namespace Tfcenv\Cli;

use Tfcenv\Terminal\Style;
use Tfcenv\Terminal\Terminal;
use Tfcenv\Tfc\TfcException;
use Tfcenv\Tfc\Variable;
use Tfcenv\Tfc\VariableRepository;

final class Applier
{
    public function __construct(
        private readonly VariableRepository $variables,
        private readonly Terminal $terminal,
        private readonly Style $style,
    ) {
    }

    /**
     * 1件ずつ適用する。1件失敗しても中断せず残りを続け、最後に集計を出す。
     */
    public function apply(ChangeSet $set): ApplyResult
    {
        $succeeded = 0;
        $failed = 0;

        foreach ($set->changes as $change) {
            try {
                match ($change->op) {
                    ChangeOp::Create => $this->variables->create($set->workspace->id, $change->variable),
                    ChangeOp::Update => $this->variables->update($set->workspace->id, $change->variable),
                };

                $succeeded++;
                $this->terminal->write(sprintf(
                    '  %s %s %s%s',
                    $this->style->ok('✓'),
                    $change->op->value,
                    $change->variable->key,
                    "\n",
                ));
            } catch (TfcException $e) {
                $failed++;
                $this->terminal->write(sprintf(
                    '  %s %s %s%s      %s%s',
                    $this->style->bad('✗'),
                    $change->op->value,
                    $change->variable->key,
                    "\n",
                    $this->redact($e->getMessage(), $change->variable),
                    "\n",
                ));
            }
        }

        $this->terminal->write(sprintf("\n%d succeeded, %d failed\n", $succeeded, $failed));

        return new ApplyResult($succeeded, $failed);
    }

    /**
     * spec は「sensitive な値は確認画面・進捗表示・エラーのすべてでマスクする」と
     * 定めている。TfcException のメッセージはサーバの errors[].detail をそのまま
     * 載せるので、「メッセージに値は入らない」という前提に頼らず構造的に潰す。
     * 差し替えるのは値そのものだけなので、失敗理由の診断情報は失われない。
     */
    private function redact(string $message, Variable $variable): string
    {
        if (!$variable->sensitive || $variable->value === '') {
            return $message;
        }

        return str_replace($variable->value, '••••••••', $message);
    }
}
