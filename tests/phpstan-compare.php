<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Voku\PhpstanAgentFormat\Tests\Support\PhpstanReportParity;

$root = dirname(__DIR__);

PhpstanReportParity::assertFixtureParity($root, $root . '/tests/Config/phpstan-agent-fixtures.neon');

echo "PHPStan compare passed.\n";
