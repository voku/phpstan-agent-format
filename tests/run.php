<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$tests = [
    ['Voku\\PhpstanAgentFormat\\Tests\\Unit\\DtoTest', 'run'],
    ['Voku\\PhpstanAgentFormat\\Tests\\Unit\\ClustererTest', 'run'],
    ['Voku\\PhpstanAgentFormat\\Tests\\Unit\\IssueNormalizerTest', 'run'],
    ['Voku\\PhpstanAgentFormat\\Tests\\Unit\\TokenBudgetReducerTest', 'run'],
    ['Voku\\PhpstanAgentFormat\\Tests\\Unit\\AgentErrorFormatterTest', 'run'],
    ['Voku\\PhpstanAgentFormat\\Tests\\Unit\\PhpstanJsonExportIngestorTest', 'run'],
    ['Voku\\PhpstanAgentFormat\\Tests\\Unit\\SerializerTest', 'run'],
    ['Voku\\PhpstanAgentFormat\\Tests\\Integration\\FormatterIntegrationTest', 'run'],
    ['Voku\\PhpstanAgentFormat\\Tests\\Integration\\PhpstanCliBlindSpotMatrixTest', 'run'],
    ['Voku\\PhpstanAgentFormat\\Tests\\Integration\\PhpstanCliConfigIntegrationTest', 'run'],
    ['Voku\\PhpstanAgentFormat\\Tests\\Integration\\PhpstanCliIntegrationTest', 'run'],
    ['Voku\\PhpstanAgentFormat\\Tests\\Integration\\PhpstanCliFixtureMatrixTest', 'run'],
    ['Voku\\PhpstanAgentFormat\\Tests\\Integration\\PhpstanJsonExportIntegrationTest', 'run'],
    ['Voku\\PhpstanAgentFormat\\Tests\\Integration\\PhpstanReportParityIntegrationTest', 'run'],
];

$failures = [];

foreach ($tests as [$className, $method]) {
    $testLabel = $className . '::' . $method;

    try {
        if (!class_exists($className) || !method_exists($className, $method)) {
            throw new RuntimeException(sprintf('Invalid test callable: %s', $testLabel));
        }

        call_user_func([$className, $method]);
        echo "[PASS] {$testLabel}\n";
    } catch (Throwable $throwable) {
        $failures[] = "[FAIL] {$testLabel}: " . $throwable->getMessage();
    }
}

if ($failures !== []) {
    echo implode("\n", $failures) . "\n";
    exit(1);
}

echo "All tests passed.\n";
