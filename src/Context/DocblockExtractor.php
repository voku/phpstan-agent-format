<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Context;

use RuntimeException;

final readonly class DocblockExtractor
{
    public function __construct(
        private PhpSymbolScanner $symbolScanner,
        private Redactor $redactor,
    ) {
    }

    public function extract(string $file, int $line): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            throw new RuntimeException(sprintf('Could not read file for docblock extraction: %s', $file));
        }

        $declaration = $this->symbolScanner->findNearestDeclaration($file, $line);
        if ($declaration === null || $declaration['line'] < 1) {
            return null;
        }

        $docblock = $this->findDocblockBefore($lines, $declaration['line']);
        if ($docblock === null) {
            return null;
        }

        return $this->redactor->redact($docblock);
    }

    /**
     * @param list<string> $lines
     */
    private function findDocblockBefore(array $lines, int $declarationLine): ?string
    {
        $i = $declarationLine - 1;
        while ($i >= 1) {
            $trimmed = trim((string) $lines[$i - 1]);
            if ($trimmed === '' || str_starts_with($trimmed, '#[')) {
                $i--;
                continue;
            }
            break;
        }

        $candidate = $i >= 1 ? trim((string) $lines[$i - 1]) : '';
        if (str_starts_with($candidate, '/**') && str_ends_with($candidate, '*/')) {
            return $candidate;
        }

        if ($i < 1 || $candidate !== '*/') {
            return null;
        }

        $end = $i;
        while ($i >= 1) {
            if (str_starts_with(trim((string) $lines[$i - 1]), '/**')) {
                return implode("\n", array_slice($lines, $i - 1, $end - $i + 1));
            }
            $i--;
        }

        return null;
    }
}
