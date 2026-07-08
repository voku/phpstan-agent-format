<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Context;

use RuntimeException;
use Voku\PhpstanAgentFormat\Config\AgentFormatConfig;

final readonly class DocblockExtractor
{
    public function __construct(private AgentFormatConfig $config)
    {
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

        $declarationLine = $this->findNearestDeclarationLine($lines, $line);
        if ($declarationLine === null) {
            return null;
        }

        $docblock = $this->findDocblockBefore($lines, $declarationLine);
        if ($docblock === null) {
            return null;
        }

        return $this->redact($docblock);
    }

    /**
     * @param list<string> $lines
     */
    private function findNearestDeclarationLine(array $lines, int $line): ?int
    {
        $limit = min(count($lines), max(1, $line));
        for ($i = $limit; $i >= 1; $i--) {
            if ($this->isDeclarationLine((string) $lines[$i - 1])) {
                return $i;
            }

            $trimmed = trim((string) $lines[$i - 1]);
            if ($i !== $limit && $trimmed !== '' && !$this->isDeclarationContinuation($trimmed)) {
                // Continue through function bodies, but avoid walking past unrelated top-level statements.
                if (str_ends_with($trimmed, ';') && !str_contains($trimmed, '$this->')) {
                    break;
                }
            }
        }

        return null;
    }

    private function isDeclarationLine(string $line): bool
    {
        return preg_match('/^\s*(?:#\[.*\]\s*)*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+[A-Za-z_][A-Za-z0-9_]*\b/', $line) === 1
            || preg_match('/^\s*(?:#\[.*\]\s*)*(?:(?:public|protected|private|static|abstract|final)\s+)*function\s+[A-Za-z_][A-Za-z0-9_]*\s*\(/', $line) === 1
            || preg_match('/^\s*(?:#\[.*\]\s*)*(?:(?:public|protected|private|static|readonly)\s+)+[^;{]*\$[A-Za-z_][A-Za-z0-9_]*\b/', $line) === 1
            || preg_match('/^\s*(?:#\[.*\]\s*)*(?:(?:public|protected|private)\s+)?const\s+[A-ZA-Za-z_][A-Za-z0-9_]*\b/', $line) === 1;
    }

    private function isDeclarationContinuation(string $trimmed): bool
    {
        return $trimmed === '' || str_starts_with($trimmed, '#[') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '*/') || str_contains($trimmed, 'function ') || str_contains($trimmed, 'class ');
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

        if ($i < 1 || trim((string) $lines[$i - 1]) !== '*/') {
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

    private function redact(string $value): string
    {
        $result = $value;
        foreach ($this->config->redactPatterns as $pattern) {
            $replaced = @preg_replace('/' . str_replace('/', '\\/', $pattern) . '/', '[REDACTED]', $result);
            if (is_string($replaced)) {
                $result = $replaced;
                continue;
            }
            $replaced = @preg_replace($pattern, '[REDACTED]', $result);
            if (is_string($replaced)) {
                $result = $replaced;
            }
        }
        return $result;
    }
}
