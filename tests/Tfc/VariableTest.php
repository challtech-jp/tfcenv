<?php

namespace Tfcenv\Tests\Tfc;

use PHPUnit\Framework\TestCase;
use Tfcenv\Tfc\Category;
use Tfcenv\Tfc\Variable;

final class VariableTest extends TestCase
{
    public function testPayloadBuildsTheCreateDocument(): void
    {
        $v = new Variable('partner_client_id', 'abc123', Category::Terraform, true, 'OAuth クライアントID');

        $this->assertSame([
            'data' => [
                'type' => 'vars',
                'attributes' => [
                    'key' => 'partner_client_id',
                    'value' => 'abc123',
                    'category' => 'terraform',
                    'sensitive' => true,
                    'description' => 'OAuth クライアントID',
                ],
            ],
        ], $v->payload());
    }

    public function testUpdateDocumentCarriesTheIdAndEveryAttribute(): void
    {
        $v = new Variable('k', 'v2', Category::Env, false, 'desc', 'var-Ab3xY9');

        $this->assertSame([
            'data' => [
                'type' => 'vars',
                'id' => 'var-Ab3xY9',
                'attributes' => [
                    'key' => 'k',
                    'value' => 'v2',
                    'category' => 'env',
                    'sensitive' => false,
                    'description' => 'desc',
                ],
            ],
        ], $v->updateDocument());
    }

    public function testFromApiReadsAResourceObject(): void
    {
        $v = Variable::fromApi([
            'id' => 'var-Ab3xY9',
            'type' => 'vars',
            'attributes' => [
                'key' => 'google_tag_manager_container_id',
                'value' => 'GTM-XXXXXXX',
                'category' => 'terraform',
                'sensitive' => false,
                'description' => 'GTM コンテナ ID',
            ],
        ]);

        $this->assertSame('var-Ab3xY9', $v->id);
        $this->assertSame('google_tag_manager_container_id', $v->key);
        $this->assertSame('GTM-XXXXXXX', $v->value);
        $this->assertSame(Category::Terraform, $v->category);
        $this->assertFalse($v->sensitive);
        $this->assertSame('GTM コンテナ ID', $v->description);
    }

    public function testFromApiTreatsAMissingSensitiveValueAsEmpty(): void
    {
        $v = Variable::fromApi([
            'id' => 'var-1',
            'attributes' => [
                'key' => 'secret',
                'value' => null,
                'category' => 'terraform',
                'sensitive' => true,
                'description' => null,
            ],
        ]);

        $this->assertSame('', $v->value);
        $this->assertSame('', $v->description);
        $this->assertTrue($v->sensitive);
    }

    public function testDisplayValueMasksSensitiveValues(): void
    {
        $secret = new Variable('k', 'super-secret', Category::Terraform, true, '');
        $plain = new Variable('k', 'GTM-XXXXXXX', Category::Terraform, false, '');

        $this->assertSame('••••••••', $secret->displayValue());
        $this->assertStringNotContainsString('super-secret', $secret->displayValue());
        $this->assertSame('GTM-XXXXXXX', $plain->displayValue());
    }

    public function testDisplayValueMarksAnEmptyValueRatherThanMaskingIt(): void
    {
        $emptySecret = new Variable('k', '', Category::Terraform, true, '');
        $emptyPlain = new Variable('k', '', Category::Terraform, false, '');

        // 空をマスクしても隠すものが無く、8個の点は「8文字の秘密がある」と誤読させる
        $this->assertSame('(empty)', $emptySecret->displayValue());
        $this->assertSame('(empty)', $emptyPlain->displayValue());
    }

    public function testWithValueAndWithIdReturnNewInstances(): void
    {
        $v = new Variable('k', 'v1', Category::Terraform, false, 'd');

        $withValue = $v->withValue('v2');
        $withId = $v->withId('var-9');

        $this->assertSame('v1', $v->value);
        $this->assertNull($v->id);
        $this->assertSame('v2', $withValue->value);
        $this->assertSame('var-9', $withId->id);
        $this->assertSame('k', $withValue->key);
    }
}
