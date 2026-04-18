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
use Voku\PhpstanAgentFormat\Support\PhpstanTipHints;

final readonly class IssueNormalizer
{
    /**
     * @var array<string, 1|2>
     */
    private const INFERRED_TYPE_PATTERNS = [
        '/should return\s+(.+?)\s+but returns\s+(.+?)(?:\.|$)/i' => 2,
        '/cannot call method [A-Za-z_][A-Za-z0-9_]*\(\) on\s+(.+?)(?:\.|$)/i' => 1,
        '/cannot access property \$[A-Za-z_][A-Za-z0-9_]* on\s+(.+?)(?:\.|$)/i' => 1,
        '/offset\s+.+?\s+does not exist on\s+(.+?)(?:\.|$)/i' => 1,
        '/does not accept(?: default value of type| value of type)?\s+(.+?)(?:\.|$)/i' => 1,
        '/with type\s+(.+?)\s+is not subtype of(?: native)? type\s+.+?(?:\.|$)/i' => 1,
    ];

    /**
     * @var array<string, 1>
     */
    private const EXPECTED_TYPE_PATTERNS = [
        '/should return\s+(.+?)\s+but returns\s+.+?(?:\.|$)/i' => 1,
    ];

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
        return $this->normalizeRaw(
            $analysisResult->getFileSpecificErrors(),
            $analysisResult->getNotFileSpecificErrors(),
        );
    }

    /**
     * @param list<object> $fileSpecificErrors
     * @param list<object|string> $notFileSpecificErrors
     * @return list<AgentIssue>
     */
    public function normalizeRaw(array $fileSpecificErrors, array $notFileSpecificErrors = []): array
    {
        $issues = [];

        foreach ($fileSpecificErrors as $index => $error) {
            $file = $this->getString($error, 'getFile', 'unknown.php');
            $filePath = $this->getNullableString($error, 'getFilePath') ?? $file;
            $traitFilePath = $this->getNullableString($error, 'getTraitFilePath');
            $line = max(1, $this->getInt($error, 'getLine', 1));
            $nodeLine = $this->getNullableInt($error, 'getNodeLine');
            $nodeType = $this->getNullableString($error, 'getNodeType');
            $message = $this->getString($error, 'getMessage', 'Unknown PHPStan error.');
            $ruleIdentifier = $this->getNullableString($error, 'getIdentifier');
            $tip = $this->getNullableString($error, 'getTip');
            $metadata = $this->getArray($error, 'getMetadata');

            $location = new FileLocation($filePath, $line);
            $nodeLocation = $nodeLine !== null && $nodeLine !== $line ? new FileLocation($filePath, $this->normalizeLineNumber($nodeLine)) : null;
            $traitLocation = $traitFilePath !== null && $nodeLine !== null ? new FileLocation($traitFilePath, $this->normalizeLineNumber($nodeLine)) : null;
            $symbolContext = $this->extractSymbolContext($message, $ruleIdentifier, $tip, $metadata);
            $snippet = $this->contextExtractor->extractSnippet($filePath, $line);
            $fixHint = $this->createFixHint($message, $ruleIdentifier);

            $issues[] = new AgentIssue(
                id: $this->issueId($file, $line, $message, $index),
                message: $message,
                ruleIdentifier: $ruleIdentifier,
                location: $location,
                symbolContext: $symbolContext,
                snippet: $snippet,
                contextTrace: $this->traceBuilder->build($location, $symbolContext, $ruleIdentifier, $message, $nodeLocation, $nodeType, $traitLocation, $tip),
                fixHint: $fixHint,
                secondaryLocations: $this->extractSecondaryLocations($location, $nodeLocation, $traitLocation),
            );
        }

        foreach ($notFileSpecificErrors as $index => $error) {
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

    /**
     * @param array<string, mixed> $metadata
     */
    private function extractSymbolContext(string $message, ?string $ruleIdentifier, ?string $tip = null, array $metadata = []): SymbolContext
    {
        [$class, $property] = $this->extractPropertyContext($message);
        $method = $this->extractMethodName($message);
        $function = $this->extractFunctionName($message);
        $parameter = $this->extractParameterName($message);
        $expectedAndInferredTypes = $this->extractExpectedAndInferredTypes($message);
        $metadataSymbolContext = $this->extractMetadataSymbolContext($metadata);

        $class = $metadataSymbolContext['className'] ?? $class ?? $this->extractClassName($message);
        $property = $metadataSymbolContext['propertyName'] ?? $property;
        $method = $metadataSymbolContext['methodName'] ?? $method;
        $function = $metadataSymbolContext['functionName'] ?? $function;
        $parameter = $metadataSymbolContext['parameterName'] ?? $parameter;

        return new SymbolContext(
            className: $class,
            methodName: $method,
            propertyName: $property,
            functionName: $function,
            parameterName: $parameter,
            expectedType: $metadataSymbolContext['expectedType'] ?? $this->extractExpectedType($message, $expectedAndInferredTypes),
            inferredType: $metadataSymbolContext['inferredType'] ?? $this->extractInferredType($message, $expectedAndInferredTypes),
            typeOrigin: $metadataSymbolContext['typeOrigin'] ?? $this->extractTypeOrigin($ruleIdentifier, $tip),
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{
     *     className:?string,
     *     methodName:?string,
     *     propertyName:?string,
     *     functionName:?string,
     *     parameterName:?string,
     *     expectedType:?string,
     *     inferredType:?string,
     *     typeOrigin:?string
     * }
     */
    private function extractMetadataSymbolContext(array $metadata): array
    {
        $className = $this->findMetadataString($metadata, ['className', 'class', 'declaringClass', 'class_name']);
        $methodName = $this->findMetadataString($metadata, ['methodName', 'method', 'memberName', 'method_name']);
        $propertyName = $this->findMetadataString($metadata, ['propertyName', 'property', 'property_name']);
        $functionName = $this->findMetadataString($metadata, ['functionName', 'function', 'function_name']);
        $parameterName = $this->findMetadataString($metadata, ['parameterName', 'parameter', 'argumentName', 'argument', 'parameter_name']);
        $expectedType = $this->findMetadataString($metadata, ['expectedType', 'expected', 'acceptedType', 'targetType', 'expected_type']);
        $inferredType = $this->findMetadataString($metadata, ['inferredType', 'actualType', 'givenType', 'receivedType', 'valueType', 'inferred_type']);
        $typeOrigin = $this->findMetadataString($metadata, ['typeOrigin', 'origin', 'typeSource', 'source']);

        if ($className !== null && $methodName !== null && !str_contains($methodName, '::')) {
            $methodName = $className . '::' . ltrim($methodName, ':');
        }

        return [
            'className' => $className,
            'methodName' => $methodName,
            'propertyName' => $propertyName !== null ? ltrim($propertyName, '$') : null,
            'functionName' => $functionName,
            'parameterName' => $parameterName !== null ? ltrim($parameterName, '$') : null,
            'expectedType' => $expectedType,
            'inferredType' => $inferredType,
            'typeOrigin' => $typeOrigin,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<string> $aliases
     */
    private function findMetadataString(array $metadata, array $aliases): ?string
    {
        foreach ($metadata as $key => $value) {
            if (in_array($key, $aliases, true) && is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_array($value)) {
                $nested = $this->findMetadataString($this->normalizeMetadata($value), $aliases);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function extractPropertyContext(string $message): array
    {
        if (preg_match('/property\s+([\\\\\w]+)::\$([A-Za-z_][A-Za-z0-9_]*)/i', $message, $match) === 1) {
            return [$match[1], $match[2]];
        }

        if (preg_match('/cannot access property\s+\$([A-Za-z_][A-Za-z0-9_]*)\s+on\s+.+?(?:\.|$)/i', $message, $match) === 1) {
            return [null, $match[1]];
        }

        return [null, null];
    }

    private function extractClassName(string $message): ?string
    {
        if (preg_match('/(?:class|trait)\s+([\\\\\w]+)/i', $message, $match) === 1) {
            return $match[1];
        }

        if (preg_match('/(?:method|property)\s+([\\\\\w]+)::/i', $message, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private function extractMethodName(string $message): ?string
    {
        if (preg_match('/cannot call method\s+([A-Za-z_][A-Za-z0-9_]*)\(\)/i', $message, $match) === 1) {
            return $match[1];
        }

        if (preg_match('/(?:static\s+)?method\s+([\\\\\w]+::[A-Za-z_][A-Za-z0-9_]*)\(\)/i', $message, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private function extractFunctionName(string $message): ?string
    {
        if (preg_match('/function\s+([\\\\\w]+)(?:\(\))?/i', $message, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    private function extractParameterName(string $message): ?string
    {
        foreach ([
            '/parameter\s+#\d+\s+\$([A-Za-z_][A-Za-z0-9_]*)\b/i',
            '/has parameter\s+\$([A-Za-z_][A-Za-z0-9_]*)\b/i',
            '/parameter\s+\$([A-Za-z_][A-Za-z0-9_]*)\b/i',
        ] as $pattern) {
            if (preg_match($pattern, $message, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * @param array{0: string, 1: string}|null $expectedAndInferredTypes
     */
    private function extractExpectedType(string $message, ?array $expectedAndInferredTypes = null): ?string
    {
        if ($expectedAndInferredTypes !== null) {
            return $expectedAndInferredTypes[0];
        }

        foreach (self::EXPECTED_TYPE_PATTERNS as $pattern => $index) {
            if (preg_match($pattern, $message, $match) !== 1) {
                continue;
            }

            $type = $this->normalizeExpectedTypeCandidate($match[$index]);
            if ($type !== '') {
                return $type;
            }
        }

        return null;
    }

    private function normalizeExpectedTypeCandidate(string $candidate): string
    {
        $candidate = trim($candidate);
        if (preg_match('/\bto be\s+(.+)$/i', $candidate, $match) === 1) {
            return trim($match[1]);
        }

        return $candidate;
    }

    /**
     * @param array{0: string, 1: string}|null $expectedAndInferredTypes
     */
    private function extractInferredType(string $message, ?array $expectedAndInferredTypes = null): ?string
    {
        if ($expectedAndInferredTypes !== null) {
            return $expectedAndInferredTypes[1];
        }

        foreach (self::INFERRED_TYPE_PATTERNS as $pattern => $index) {
            if (preg_match($pattern, $message, $match) !== 1) {
                continue;
            }

            if (!isset($match[$index])) {
                continue;
            }

            $type = trim($match[$index]);
            if ($type !== '') {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function extractExpectedAndInferredTypes(string $message): ?array
    {
        foreach ([
            '/expects\s+(.+?)\s+given(?:\.|$)/i',
        ] as $pattern) {
            if (preg_match($pattern, $message, $match) !== 1) {
                continue;
            }

            $pair = $this->splitTopLevelTypePair(trim($match[1]));
            if ($pair !== null) {
                return [$this->normalizeExpectedTypeCandidate($pair[0]), $pair[1]];
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function splitTopLevelTypePair(string $candidate): ?array
    {
        $angleDepth = 0;
        $braceDepth = 0;
        $bracketDepth = 0;
        $parenthesisDepth = 0;
        $length = strlen($candidate);

        for ($i = 0; $i < $length; ++$i) {
            $character = $candidate[$i];

            switch ($character) {
                case '<':
                    ++$angleDepth;
                    break;
                case '>':
                    $angleDepth = max(0, $angleDepth - 1);
                    break;
                case '{':
                    ++$braceDepth;
                    break;
                case '}':
                    $braceDepth = max(0, $braceDepth - 1);
                    break;
                case '[':
                    ++$bracketDepth;
                    break;
                case ']':
                    $bracketDepth = max(0, $bracketDepth - 1);
                    break;
                case '(':
                    ++$parenthesisDepth;
                    break;
                case ')':
                    $parenthesisDepth = max(0, $parenthesisDepth - 1);
                    break;
                case ',':
                    if ($angleDepth !== 0 || $braceDepth !== 0 || $bracketDepth !== 0 || $parenthesisDepth !== 0) {
                        break;
                    }

                    $expectedType = trim(substr($candidate, 0, $i));
                    $inferredType = trim(substr($candidate, $i + 1));

                    if ($expectedType !== '' && $inferredType !== '') {
                        return [$expectedType, $inferredType];
                    }

                    return null;
            }
        }

        return null;
    }

    private function extractTypeOrigin(?string $ruleIdentifier, ?string $tip): ?string
    {
        if ($tip === null) {
            return $ruleIdentifier;
        }

        $normalizedTip = strtolower(PhpstanTipHints::stripFormatting($tip));
        if (str_contains($normalizedTip, PhpstanTipHints::PHPDOC_TYPE_ORIGIN_FRAGMENT)) {
            return 'phpdoc';
        }

        if (str_contains($normalizedTip, 'remembering and forgetting returned values')) {
            return 'returned-value';
        }

        return $ruleIdentifier;
    }

    private function createFixHint(string $message, ?string $ruleIdentifier): FixHint
    {
        $kind = strtolower(($ruleIdentifier ?? '') . ' ' . $message);

        if ($this->isGenericTemplateMismatch($message, $ruleIdentifier)) {
            return new FixHint(
                rootCauseSummary: 'Generic or template arguments drifted from the declared contract.',
                repairStrategySummary: 'Align template arguments at the source or tighten the declaration that introduced the mismatch.',
            );
        }

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

        if (
            str_contains($kind, 'undefined')
            || str_contains($kind, 'cannot call method')
            || str_contains($kind, 'cannot access property')
            || str_contains($kind, 'nonobject')
        ) {
            return new FixHint(
                rootCauseSummary: 'The inferred type does not define the accessed member.',
                repairStrategySummary: 'Correct the source type or guard before member access.',
            );
        }

        if (str_contains($kind, 'offsetaccess') || str_contains($kind, 'offset ')) {
            return new FixHint(
                rootCauseSummary: 'The inferred container type does not define the accessed offset.',
                repairStrategySummary: 'Validate the container shape earlier or guard before reading the offset.',
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

    private function normalizeLineNumber(int $line): int
    {
        return max(1, $line);
    }

    /**
     * @return list<FileLocation>
     */
    private function extractSecondaryLocations(FileLocation $primaryLocation, ?FileLocation ...$candidates): array
    {
        $secondaryLocations = [];
        $seen = [$primaryLocation->file . ':' . $primaryLocation->line => true];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $key = $candidate->file . ':' . $candidate->line;
            if (isset($seen[$key])) {
                continue;
            }

            $secondaryLocations[] = $candidate;
            $seen[$key] = true;
        }

        return $secondaryLocations;
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
        return $this->getNullableInt($object, $method) ?? $default;
    }

    private function getNullableString(object $object, string $method): ?string
    {
        if (!method_exists($object, $method)) {
            return null;
        }

        $value = $object->{$method}();

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function getNullableInt(object $object, string $method): ?int
    {
        if (!method_exists($object, $method)) {
            return null;
        }

        $value = $object->{$method}();

        return is_int($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function getArray(object $object, string $method): array
    {
        if (!method_exists($object, $method)) {
            return [];
        }

        $value = $object->{$method}();

        return is_array($value) ? $this->normalizeMetadata($value) : [];
    }

    private function isGenericTemplateMismatch(string $message, ?string $ruleIdentifier): bool
    {
        $haystack = strtolower(($ruleIdentifier ?? '') . ' ' . $message);

        if (str_contains($haystack, 'template')) {
            return true;
        }

        if (!str_contains($haystack, 'given') && !str_contains($haystack, 'returns')) {
            return false;
        }

        return preg_match('/[A-Za-z_\\\\]+\s*<.+>/', $message) === 1
            || preg_match('/array<.+>/', $message) === 1;
    }

    /**
     * @param array<mixed> $metadata
     * @return array<string, mixed>
     */
    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];

        foreach ($metadata as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_array($value)) {
                $normalized[$key] = $this->normalizeMetadata($value);
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

}
