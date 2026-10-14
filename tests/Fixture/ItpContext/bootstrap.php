<?php

declare(strict_types=1);

// Makes voku/itp-context and the fixture rule enum autoloadable inside PHPStan's process,
// the way a host project's vendor/autoload.php would.
$itpContext = getenv('ITP_CONTEXT_DIR');
if (!is_string($itpContext) || !is_file($itpContext . '/vendor/autoload.php')) {
    throw new RuntimeException('ITP_CONTEXT_DIR must point to a voku/itp-context checkout with installed dependencies.');
}

require_once $itpContext . '/vendor/autoload.php';
require_once __DIR__ . '/Rules.php';
