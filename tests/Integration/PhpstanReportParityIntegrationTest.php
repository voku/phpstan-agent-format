<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Integration;

use Voku\PhpstanAgentFormat\Tests\Support\PhpstanReportParity;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class PhpstanReportParityIntegrationTest
{
    public static function run(): void
    {
        $root = dirname(__DIR__, 2);
        PhpstanReportParity::assertFixtureParity($root, $root . '/tests/Config/phpstan-agent-fixtures.neon');
    }
}
