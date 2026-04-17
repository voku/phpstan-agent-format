<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$errors = [];

foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    if (str_contains($path, '/vendor/')) {
        continue;
    }

    $output = [];
    $code = 0;
    exec(sprintf('php -l %s 2>&1', escapeshellarg($path)), $output, $code);
    if ($code !== 0) {
        $errors[] = implode("\n", $output);
    }
}

if ($errors !== []) {
    echo implode("\n\n", $errors) . "\n";
    exit(1);
}

echo "Lint passed.\n";
