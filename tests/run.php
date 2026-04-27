<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$autoloadPath = $root . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

$stubFiles = [];
$stubIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tests/Stubs'));
foreach ($stubIterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
        $stubFiles[] = $file->getPathname();
    }
}
sort($stubFiles);
foreach ($stubFiles as $stubFile) {
    require_once $stubFile;
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefixes = [
        'Voku\\PhpstanAgentFormat\\' => $root . '/src/',
        'Voku\\PhpstanAgentFormat\\Tests\\' => $root . '/tests/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

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
