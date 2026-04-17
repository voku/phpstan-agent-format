<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Config;

use InvalidArgumentException;

final readonly class AgentFormatConfig
{
    /**
     * @param list<string> $redactPatterns
     */
    public function __construct(
        public string $outputMode,
        public int $maxClusters,
        public int $maxIssuesPerCluster,
        public int $snippetLinesBefore,
        public int $snippetLinesAfter,
        public bool $includeDocblock,
        public bool $includeRelatedDefinition,
        public int $tokenBudget,
        public array $redactPatterns,
    ) {
        if ($this->maxClusters < 1 || $this->maxIssuesPerCluster < 1) {
            throw new InvalidArgumentException('maxClusters and maxIssuesPerCluster must be >= 1.');
        }
        if ($this->tokenBudget < 1) {
            throw new InvalidArgumentException('tokenBudget must be >= 1.');
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function fromParameters(array $parameters): self
    {
        $raw = $parameters['agentFormat'] ?? $parameters;
        if (!\is_array($raw)) {
            $raw = [];
        }

        /** @var list<string> $patterns */
        $patterns = [];
        foreach (($raw['redactPatterns'] ?? []) as $pattern) {
            if (\is_string($pattern) && $pattern !== '') {
                $patterns[] = $pattern;
            }
        }

        return new self(
            outputMode: self::normalizeMode((string) ($raw['outputMode'] ?? 'agentJson')),
            maxClusters: max(1, (int) ($raw['maxClusters'] ?? 30)),
            maxIssuesPerCluster: max(1, (int) ($raw['maxIssuesPerCluster'] ?? 3)),
            snippetLinesBefore: max(0, (int) ($raw['snippetLinesBefore'] ?? 2)),
            snippetLinesAfter: max(0, (int) ($raw['snippetLinesAfter'] ?? 3)),
            includeDocblock: (bool) ($raw['includeDocblock'] ?? false),
            includeRelatedDefinition: (bool) ($raw['includeRelatedDefinition'] ?? true),
            tokenBudget: max(1, (int) ($raw['tokenBudget'] ?? 12000)),
            redactPatterns: $patterns,
        );
    }

    private static function normalizeMode(string $mode): string
    {
        $normalized = strtolower($mode);

        return match ($normalized) {
            'json', 'agentjson' => 'agentJson',
            'ndjson', 'agentndjson' => 'agentNdjson',
            'markdown', 'agentmarkdown', 'md' => 'agentMarkdown',
            'compact', 'agentcompact', 'text' => 'agentCompact',
            default => 'agentJson',
        };
    }
}
