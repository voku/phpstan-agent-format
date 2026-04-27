<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Integration;

use Voku\PhpstanAgentFormat\Tests\Support\PhpstanReportParity;

final class PhpstanReducedReportParityIntegrationTest
{
    public static function run(): void
    {
        $root = dirname(__DIR__, 2);
        PhpstanReportParity::assertReducedFixtureParity($root, $root . '/tests/Config/phpstan-agent-reduced.neon');
    }
}
