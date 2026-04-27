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
