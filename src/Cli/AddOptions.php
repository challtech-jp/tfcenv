<?php

namespace Tfcenv\Cli;

use Tfcenv\Tfc\Category;

/**
 * 非対話モードの引数。`tfcenv add` に引数が1つでも続いていればこの経路に入る。
 *
 * category / sensitive / description が null なのは「指定されなかった」という意味で、
 * 「既定値」とは区別する。--update のとき、指定されなかった属性は TFC 側の現在値を
 * 引き継ぐ必要があるため。既定値に潰してしまうと、sensitive な変数を更新するたびに
 * sensitive: false を送ることになり、TFC が 422 で拒む
 * （Sensitive cannot be changed from true to false on saved records）。
 */
final class AddOptions
{
    public function __construct(
        public readonly string $organization,
        public readonly string $workspace,
        public readonly string $key,
        public readonly string $value,
        public readonly ?Category $category,
        public readonly ?bool $sensitive,
        public readonly ?string $description,
        public readonly bool $update,
    ) {
    }

    /**
     * argv の "add" より後ろを読む。ネットワークには触れない。
     *
     * @param string[] $args
     * @throws UsageException
     */
    public static function parse(array $args, string $defaultOrganization): self
    {
        $organization = $defaultOrganization;
        $workspace = '';
        $category = null;
        $sensitive = null;
        $description = null;
        $update = false;
        $specs = [];

        $count = count($args);

        for ($i = 0; $i < $count; $i++) {
            $arg = (string) $args[$i];

            if ($arg === '-s' || $arg === '--sensitive') {
                $sensitive = true;
                continue;
            }

            if ($arg === '-u' || $arg === '--update') {
                $update = true;
                continue;
            }

            if ($arg === '-w' || $arg === '--workspace') {
                $workspace = self::valueFor($args, $i, '--workspace');
                $i++;
                continue;
            }

            if ($arg === '-o' || $arg === '--org' || $arg === '--organization') {
                $organization = self::valueFor($args, $i, '--org');
                $i++;
                continue;
            }

            if ($arg === '-c' || $arg === '--category') {
                $category = self::category(self::valueFor($args, $i, '--category'));
                $i++;
                continue;
            }

            if ($arg === '-d' || $arg === '--description') {
                $description = self::valueFor($args, $i, '--description');
                $i++;
                continue;
            }

            if ($arg !== '' && $arg[0] === '-') {
                throw new UsageException(sprintf(
                    'Unknown option "%s". Run tfcenv --help for the ones that exist.',
                    $arg,
                ));
            }

            $specs[] = $arg;
        }

        if ($workspace === '') {
            throw new UsageException(
                'A workspace is required. Pass --workspace NAME (-w).',
            );
        }

        if ($organization === '') {
            throw new UsageException(
                'An organization is required. Pass --org NAME (-o), or set TFC_ORG.',
            );
        }

        if ($specs === []) {
            throw new UsageException(
                'A variable is required. Pass KEY=VALUE, or KEY on its own to take '
                . 'the value from the environment variable of the same name.',
            );
        }

        if (count($specs) > 1) {
            // 値を晒さないよう、2つ目が何だったかは書かない。
            throw new UsageException(sprintf(
                'tfcenv registers one variable at a time, but %d were given.',
                count($specs),
            ));
        }

        $spec = $specs[0];
        $separator = strpos($spec, '=');

        if ($separator === 0) {
            throw new UsageException('A variable needs a key before the "=".');
        }

        if ($separator === false) {
            return new self(
                $organization,
                $workspace,
                $spec,
                self::fromEnvironment($spec),
                $category,
                $sensitive,
                $description,
                $update,
            );
        }

        return new self(
            $organization,
            $workspace,
            substr($spec, 0, $separator),
            substr($spec, $separator + 1),
            $category,
            $sensitive,
            $description,
            $update,
        );
    }

    /**
     * `=` を省いたキーは同名の環境変数から読む。シークレットをシェル履歴と
     * ps に残さないための経路なので、値はエラーメッセージにも載せない。
     * 空文字列に設定されているのは「空を登録したい」という意思表示として通す。
     */
    private static function fromEnvironment(string $key): string
    {
        $value = getenv($key);

        if ($value === false) {
            throw new UsageException(sprintf(
                '%s is not set in the environment. Export it, or pass %s=VALUE.',
                $key,
                $key,
            ));
        }

        return $value;
    }

    /**
     * @param string[] $args
     */
    private static function valueFor(array $args, int $index, string $name): string
    {
        if (!isset($args[$index + 1])) {
            throw new UsageException(sprintf('%s needs a value.', $name));
        }

        return (string) $args[$index + 1];
    }

    private static function category(string $given): Category
    {
        $category = Category::tryFrom($given);

        if ($category === null) {
            throw new UsageException(sprintf(
                'Unknown category "%s". Use terraform or env.',
                $given,
            ));
        }

        return $category;
    }
}
