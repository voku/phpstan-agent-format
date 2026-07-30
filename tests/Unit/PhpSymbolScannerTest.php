<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use RuntimeException;
use Voku\PhpstanAgentFormat\Context\PhpSymbolScanner;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

enum ContextFixtureUnitEnum
{
    case Example;
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class ContextFixtureAttribute
{
    public function __construct(public ContextFixtureUnitEnum $rule)
    {
    }
}

final class PhpSymbolScannerTest
{
    public static function run(): void
    {
        $fixture = sys_get_temp_dir() . '/phpstan-agent-format-enum-attribute-fixture-' . sha1((string) microtime(true)) . '.php';
        file_put_contents($fixture, <<<'PHP'
<?php

#[ContextFixtureAttribute(\Voku\PhpstanAgentFormat\Tests\Unit\ContextFixtureUnitEnum::Example)]
final class ContextFixtureEnumAttribute
{
}
PHP);

        try {
            require_once $fixture;
            $declaration = (new PhpSymbolScanner())->findNearestDeclaration($fixture, 5);

            if ($declaration === null) {
                throw new RuntimeException('The class declaration with the enum attribute should be found.');
            }

            TestCase::assertTrue(
                str_contains(implode("\n", $declaration['attributes']), 'ContextFixtureUnitEnum::Example'),
                'Non-backed enum attribute arguments should be rendered as enum case labels instead of being passed to json_encode().',
            );
        } finally {
            if (is_file($fixture)) {
                unlink($fixture);
            }
        }
    }
}
