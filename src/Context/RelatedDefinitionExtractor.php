<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Context;

use RuntimeException;
use Voku\PhpstanAgentFormat\Dto\RelatedDefinition;
use Voku\PhpstanAgentFormat\Dto\SymbolContext;

final readonly class RelatedDefinitionExtractor
{
    public function __construct(
        private PhpSymbolScanner $symbolScanner,
        private Redactor $redactor,
    ) {
    }

    public function extract(string $file, int $line, SymbolContext $symbolContext): ?RelatedDefinition
    {
        if (!is_file($file)) {
            return null;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            throw new RuntimeException(sprintf('Could not read file for related definition extraction: %s', $file));
        }

        try {
            $declaration = $this->symbolScanner->findRelatedDeclaration($file, $line, $symbolContext);
        } catch (RuntimeException) {
            return null;
        }

        if ($declaration === null || $declaration['line'] < 1) {
            return null;
        }

        return new RelatedDefinition(
            file: $declaration['file'] !== '' ? $declaration['file'] : $file,
            line: $declaration['line'],
            endLine: $declaration['endLine'],
            symbol: $declaration['symbol'],
            kind: $declaration['kind'],
            snippet: [$this->redactor->redact($this->compactDeclaration($lines, $declaration['line']))],
            attributes: $this->redactAttributes($declaration['attributes']),
        );
    }


    /**
     * @param list<string> $attributes
     * @return list<string>
     */
    private function redactAttributes(array $attributes): array
    {
        $redacted = [];
        foreach ($attributes as $attribute) {
            $redacted[] = $this->redactor->redact($attribute);
        }

        return $redacted;
    }

    /**
     * @param list<string> $lines
     */
    private function compactDeclaration(array $lines, int $line): string
    {
        $parts = [];
        for ($i = $line, $count = count($lines); $i <= $count && count($parts) < 6; $i++) {
            $parts[] = trim((string) $lines[$i - 1]);
            $joined = trim(implode(' ', $parts));
            if (str_contains($joined, '{')) {
                return trim(substr($joined, 0, (int) strpos($joined, '{') + 1));
            }
            if (str_contains($joined, ';')) {
                return trim(substr($joined, 0, (int) strpos($joined, ';') + 1));
            }
        }

        return trim(implode(' ', $parts));
    }
}