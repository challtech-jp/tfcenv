<?php

namespace Tfcenv\Tfc;

final class Variable
{
    private const MASK = '••••••••';

    public function __construct(
        public readonly string $key,
        public readonly string $value,
        public readonly Category $category,
        public readonly bool $sensitive,
        public readonly string $description,
        public readonly ?string $id = null,
    ) {
    }

    /**
     * TFC の vars リソースオブジェクトから作る。
     * sensitive な変数は value が返らないので空文字列になる。
     */
    public static function fromApi(array $resource): self
    {
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];

        return new self(
            (string) ($attributes['key'] ?? ''),
            (string) ($attributes['value'] ?? ''),
            Category::from((string) ($attributes['category'] ?? 'terraform')),
            (bool) ($attributes['sensitive'] ?? false),
            (string) ($attributes['description'] ?? ''),
            isset($resource['id']) ? (string) $resource['id'] : null,
        );
    }

    public function payload(): array
    {
        return [
            'data' => [
                'type' => 'vars',
                'attributes' => $this->attributes(),
            ],
        ];
    }

    public function updateDocument(): array
    {
        return [
            'data' => [
                'type' => 'vars',
                'id' => (string) $this->id,
                'attributes' => $this->attributes(),
            ],
        ];
    }

    public function withValue(string $value): self
    {
        return new self($this->key, $value, $this->category, $this->sensitive, $this->description, $this->id);
    }

    public function withId(string $id): self
    {
        return new self($this->key, $this->value, $this->category, $this->sensitive, $this->description, $id);
    }

    public function displayValue(): string
    {
        return $this->sensitive ? self::MASK : $this->value;
    }

    private function attributes(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'category' => $this->category->value,
            'sensitive' => $this->sensitive,
            'description' => $this->description,
        ];
    }
}
