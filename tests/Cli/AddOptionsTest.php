<?php

namespace Tfcenv\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tfcenv\Cli\AddOptions;
use Tfcenv\Cli\UsageException;
use Tfcenv\Tfc\Category;

final class AddOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('DB_PASSWORD');
        putenv('EMPTY_SECRET');
    }

    public function testItReadsAWorkspaceAndAKeyValuePair(): void
    {
        $options = AddOptions::parse(['-w', 'alpha-core-stg', 'GTM_ID=GTM-XXXXXXX'], 'acme');

        $this->assertSame('acme', $options->organization);
        $this->assertSame('alpha-core-stg', $options->workspace);
        $this->assertSame('GTM_ID', $options->key);
        $this->assertSame('GTM-XXXXXXX', $options->value);
        $this->assertFalse($options->update);
    }

    public function testUnspecifiedAttributesStayNullSoAnUpdateCanInheritThem(): void
    {
        $options = AddOptions::parse(['-w', 'stg', 'KEY=v'], 'acme');

        $this->assertNull($options->category);
        $this->assertNull($options->sensitive);
        $this->assertNull($options->description);
    }

    public function testItReadsEveryAttributeFlag(): void
    {
        $options = AddOptions::parse(
            ['-w', 'stg', '-c', 'env', '-s', '-d', '連携先連携', '-u', 'PARTNER_TOKEN=xxxx'],
            'acme',
        );

        $this->assertSame(Category::Env, $options->category);
        $this->assertTrue($options->sensitive);
        $this->assertSame('連携先連携', $options->description);
        $this->assertTrue($options->update);
    }

    public function testTheLongFlagsMeanTheSameThing(): void
    {
        $options = AddOptions::parse([
            '--workspace', 'stg',
            '--org', 'other-org',
            '--category', 'env',
            '--sensitive',
            '--description', 'text',
            '--update',
            'KEY=v',
        ], 'acme');

        $this->assertSame('other-org', $options->organization);
        $this->assertSame('stg', $options->workspace);
        $this->assertSame(Category::Env, $options->category);
        $this->assertTrue($options->sensitive);
        $this->assertSame('text', $options->description);
        $this->assertTrue($options->update);
    }

    public function testFlagsMayFollowTheVariable(): void
    {
        $options = AddOptions::parse(['KEY=v', '-w', 'stg', '-s'], 'acme');

        $this->assertSame('stg', $options->workspace);
        $this->assertSame('KEY', $options->key);
        $this->assertTrue($options->sensitive);
    }

    public function testAValueMayContainEqualsSigns(): void
    {
        $options = AddOptions::parse(['-w', 'stg', 'DSN=user=a;pass=b'], 'acme');

        $this->assertSame('DSN', $options->key);
        $this->assertSame('user=a;pass=b', $options->value);
    }

    public function testAnExplicitlyEmptyValueIsKept(): void
    {
        $options = AddOptions::parse(['-w', 'stg', 'KEY='], 'acme');

        $this->assertSame('', $options->value);
    }

    public function testABareKeyTakesItsValueFromTheEnvironment(): void
    {
        putenv('DB_PASSWORD=hunter2');

        $options = AddOptions::parse(['-w', 'stg', '-s', 'DB_PASSWORD'], 'acme');

        $this->assertSame('DB_PASSWORD', $options->key);
        $this->assertSame('hunter2', $options->value);
    }

    public function testABareKeySetToAnEmptyStringIsNotAnError(): void
    {
        putenv('EMPTY_SECRET=');

        $options = AddOptions::parse(['-w', 'stg', 'EMPTY_SECRET'], 'acme');

        $this->assertSame('', $options->value);
    }

    public function testABareKeyThatIsNotInTheEnvironmentIsRejected(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('DB_PASSWORD is not set in the environment');

        AddOptions::parse(['-w', 'stg', 'DB_PASSWORD'], 'acme');
    }

    public function testTheEnvironmentValueIsNeverPutInTheErrorMessage(): void
    {
        putenv('DB_PASSWORD=hunter2');

        try {
            AddOptions::parse(['-w', 'stg', 'DB_PASSWORD', 'SECOND=x'], 'acme');
            $this->fail('expected a UsageException');
        } catch (UsageException $e) {
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    public function testAMissingWorkspaceIsRejected(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('--workspace');

        AddOptions::parse(['KEY=v'], 'acme');
    }

    public function testAMissingOrganizationIsRejected(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('TFC_ORG');

        AddOptions::parse(['-w', 'stg', 'KEY=v'], '');
    }

    public function testAMissingVariableIsRejected(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('KEY=VALUE');

        AddOptions::parse(['-w', 'stg'], 'acme');
    }

    public function testASecondVariableIsRejected(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('one variable at a time');

        AddOptions::parse(['-w', 'stg', 'A=1', 'B=2'], 'acme');
    }

    public function testAnEmptyKeyIsRejected(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('key');

        AddOptions::parse(['-w', 'stg', '=value'], 'acme');
    }

    public function testAnUnknownFlagIsRejected(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('--plain');

        AddOptions::parse(['-w', 'stg', '--plain', 'KEY=v'], 'acme');
    }

    public function testAFlagWithoutItsValueIsRejected(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('--workspace needs a value');

        AddOptions::parse(['KEY=v', '-w'], 'acme');
    }

    public function testAnUnknownCategoryIsRejectedAndListsTheValidOnes(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('terraform');

        AddOptions::parse(['-w', 'stg', '-c', 'shell', 'KEY=v'], 'acme');
    }

    public function testBundledShortFlagsAreNotAccepted(): void
    {
        $this->expectException(UsageException::class);

        AddOptions::parse(['-w', 'stg', '-su', 'KEY=v'], 'acme');
    }

    public function testTheOrganizationDefaultsToTheOneFromTheEnvironmentAndFlagsWin(): void
    {
        $fromEnv = AddOptions::parse(['-w', 'stg', 'KEY=v'], 'acme');
        $this->assertSame('acme', $fromEnv->organization);

        $short = AddOptions::parse(['-w', 'stg', '-o', 'other', 'KEY=v'], 'acme');
        $this->assertSame('other', $short->organization);

        $long = AddOptions::parse(['-w', 'stg', '--organization', 'other', 'KEY=v'], 'acme');
        $this->assertSame('other', $long->organization);
    }
}
