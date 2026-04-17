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

        /** @var array<string, mixed> $raw */
        /** @var list<string> $patterns */
        $patterns = [];
        $redactPatterns = $raw['redactPatterns'] ?? [];
        if (!\is_array($redactPatterns)) {
            $redactPatterns = [];
        }

        foreach ($redactPatterns as $pattern) {
            if (\is_string($pattern) && $pattern !== '') {
                $patterns[] = $pattern;
            }
        }

        return new self(
            outputMode: self::normalizeMode(self::stringValue($raw, 'outputMode', 'agentJson')),
            maxClusters: max(1, self::intValue($raw, 'maxClusters', 30)),
            maxIssuesPerCluster: max(1, self::intValue($raw, 'maxIssuesPerCluster', 3)),
            snippetLinesBefore: max(0, self::intValue($raw, 'snippetLinesBefore', 2)),
            snippetLinesAfter: max(0, self::intValue($raw, 'snippetLinesAfter', 3)),
            includeDocblock: self::boolValue($raw, 'includeDocblock', false),
            includeRelatedDefinition: self::boolValue($raw, 'includeRelatedDefinition', true),
            tokenBudget: max(1, self::intValue($raw, 'tokenBudget', 12000)),
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

    /**
     * @param array<string, mixed> $values
     */
    private static function stringValue(array $values, string $key, string $default): string
    {
        $value = $values[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function intValue(array $values, string $key, int $default): int
    {
        $value = $values[$key] ?? null;

        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function boolValue(array $values, string $key, bool $default): bool
    {
        $value = $values[$key] ?? null;

        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return $value !== 0;
        }

        if (\is_string($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return $default;
    }
}
