<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Normalizer;

use PHPStan\Command\AnalysisResult;
use Voku\PhpstanAgentFormat\Context\ContextExtractor;
use Voku\PhpstanAgentFormat\Context\ContextTraceBuilder;
use Voku\PhpstanAgentFormat\Dto\AgentIssue;
use Voku\PhpstanAgentFormat\Dto\FileLocation;
use Voku\PhpstanAgentFormat\Dto\FixHint;
use Voku\PhpstanAgentFormat\Dto\SymbolContext;

final readonly class IssueNormalizer
{
    public function __construct(
        private ContextExtractor $contextExtractor,
        private ContextTraceBuilder $traceBuilder,
    ) {
    }

    /**
     * @return list<AgentIssue>
     */
    public function normalize(AnalysisResult $analysisResult): array
    {
        $issues = [];

        foreach ($analysisResult->getFileSpecificErrors() as $index => $error) {
            $file = $this->getString($error, 'getFile', 'unknown.php');
            $line = max(1, $this->getInt($error, 'getLine', 1));
            $message = $this->getString($error, 'getMessage', 'Unknown PHPStan error.');
            $ruleIdentifier = $this->getNullableString($error, 'getIdentifier');

            $location = new FileLocation($file, $line);
            $symbolContext = $this->extractSymbolContext($message, $ruleIdentifier);
            $snippet = $this->contextExtractor->extractSnippet($file, $line);
            $fixHint = $this->createFixHint($message, $ruleIdentifier);

            $issues[] = new AgentIssue(
                id: $this->issueId($file, $line, $message, $index),
                message: $message,
                ruleIdentifier: $ruleIdentifier,
                location: $location,
                symbolContext: $symbolContext,
                snippet: $snippet,
                contextTrace: $this->traceBuilder->build($location, $symbolContext, $ruleIdentifier, $message),
                fixHint: $fixHint,
            );
        }

        foreach ($analysisResult->getNotFileSpecificErrors() as $index => $error) {
            $message = is_string($error) ? $error : $this->getString($error, 'getMessage', 'General PHPStan error.');
            $ruleIdentifier = is_string($error) ? null : $this->getNullableString($error, 'getIdentifier');
            $location = new FileLocation('unknown.php', 1);
            $symbolContext = $this->extractSymbolContext($message, $ruleIdentifier);

            $issues[] = new AgentIssue(
                id: $this->issueId('unknown.php', 1, $message, $index + 10000),
                message: $message,
                ruleIdentifier: $ruleIdentifier,
                location: $location,
                symbolContext: $symbolContext,
                snippet: $this->contextExtractor->extractSnippet('unknown.php', 1),
                contextTrace: $this->traceBuilder->build($location, $symbolContext, $ruleIdentifier, $message),
                fixHint: $this->createFixHint($message, $ruleIdentifier),
            );
        }

        usort($issues, static fn (AgentIssue $a, AgentIssue $b): int => $a->id <=> $b->id);

        return $issues;
    }

    private function extractSymbolContext(string $message, ?string $ruleIdentifier): SymbolContext
    {
        $class = null;
        $method = null;
        $property = null;
        $function = null;

        if (preg_match('/class\s+([\\\\\w]+)/i', $message, $match) === 1) {
            $class = $match[1];
        }
        if (preg_match('/method\s+([\\\\\w:]+)/i', $message, $match) === 1) {
            $method = $match[1];
        }
        if (preg_match('/property\s+\$?([\\\\\w]+)/i', $message, $match) === 1) {
            $property = $match[1];
        }
        if (preg_match('/function\s+([\\\\\w]+)/i', $message, $match) === 1) {
            $function = $match[1];
        }

        $inferredType = null;
        if (preg_match('/(?:expects|expects parameter .*?)\s+([\\\\\w|<>\[\]{}:?]+)/i', $message, $match) === 1) {
            $inferredType = $match[1];
        }

        return new SymbolContext(
            className: $class,
            methodName: $method,
            propertyName: $property,
            functionName: $function,
            inferredType: $inferredType,
            typeOrigin: $ruleIdentifier,
        );
    }

    private function createFixHint(string $message, ?string $ruleIdentifier): FixHint
    {
        $kind = strtolower(($ruleIdentifier ?? '') . ' ' . $message);

        if (str_contains($kind, 'null')) {
            return new FixHint(
                rootCauseSummary: 'Nullable value reaches a non-null expectation.',
                repairStrategySummary: 'Constrain nullability earlier or widen the target type to accept null.',
            );
        }

        if (str_contains($kind, 'type') && str_contains($kind, 'missing')) {
            return new FixHint(
                rootCauseSummary: 'A symbol is missing an explicit type declaration.',
                repairStrategySummary: 'Add precise native/phpdoc type declarations on the reported symbol.',
            );
        }

        if (str_contains($kind, 'undefined')) {
            return new FixHint(
                rootCauseSummary: 'The inferred type does not define the accessed member.',
                repairStrategySummary: 'Correct the source type or guard before member access.',
            );
        }

        return new FixHint(
            rootCauseSummary: 'Static-analysis rule violation detected.',
            repairStrategySummary: 'Update code or type declarations so inferred and expected types align.',
        );
    }

    private function issueId(string $file, int $line, string $message, int $index): string
    {
        return sha1($file . '|' . $line . '|' . $message . '|' . $index);
    }

    private function getString(object $object, string $method, string $default): string
    {
        if (!method_exists($object, $method)) {
            return $default;
        }

        $value = $object->{$method}();

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function getInt(object $object, string $method, int $default): int
    {
        if (!method_exists($object, $method)) {
            return $default;
        }

        $value = $object->{$method}();

        return is_int($value) ? $value : $default;
    }

    private function getNullableString(object $object, string $method): ?string
    {
        if (!method_exists($object, $method)) {
            return null;
        }

        $value = $object->{$method}();

        return is_string($value) && $value !== '' ? $value : null;
    }
}
