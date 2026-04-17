<?php

declare(strict_types=1);

namespace PHPStan\Command\ErrorFormatter;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\Output;

if (interface_exists(ErrorFormatter::class, false)) {
    return;
}

interface ErrorFormatter
{
    public function formatErrors(AnalysisResult $analysisResult, Output $output): int;
}
