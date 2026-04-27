<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Voku\PhpstanAgentFormat\Tests\Support\PhpstanReportParity;

$root = dirname(__DIR__);

PhpstanReportParity::assertReducedFixtureParity($root, $root . '/tests/Config/phpstan-agent-reduced.neon');

echo "PHPStan reduced compare passed.\n";
