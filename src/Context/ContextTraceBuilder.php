<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Context;

use Voku\PhpstanAgentFormat\Dto\ContextTrace;
use Voku\PhpstanAgentFormat\Dto\FileLocation;
use Voku\PhpstanAgentFormat\Dto\SymbolContext;
use Voku\PhpstanAgentFormat\Dto\TraceHop;

final class ContextTraceBuilder
{
    private const TIP_BULLET_TRIM_CHARS = "• \t-";

    public function build(
        FileLocation $location,
        SymbolContext $symbolContext,
        ?string $ruleIdentifier,
        string $message,
        ?FileLocation $nodeLocation = null,
        ?string $nodeType = null,
        ?FileLocation $traitLocation = null,
        ?string $tip = null,
    ): ContextTrace
    {
        $hops = [
            new TraceHop(
                location: $location,
                summary: 'Primary static-analysis finding.',
                symbol: $this->bestSymbol($symbolContext),
                ruleIdentifier: $ruleIdentifier,
            ),
        ];

        if ($nodeLocation !== null) {
            $hops[] = new TraceHop(
                location: $nodeLocation,
                summary: sprintf('PHPStan attached the finding to AST node %s.', $this->describeNodeType($nodeType)),
                symbol: $this->bestSymbol($symbolContext),
                ruleIdentifier: $ruleIdentifier,
            );
        }

        if ($symbolContext->typeOrigin !== null) {
            $hops[] = new TraceHop(
                location: $location,
                summary: $symbolContext->typeOrigin === 'phpdoc'
                    ? 'Type origin hint: PHPDoc-enriched type.'
                    : sprintf('Inferred type origin: %s', $symbolContext->typeOrigin),
                symbol: $symbolContext->inferredType,
                ruleIdentifier: $ruleIdentifier,
            );
        }

        $tipSummary = $this->tipSummary($tip, $symbolContext->typeOrigin);
        if ($tipSummary !== null) {
            $hops[] = new TraceHop(
                location: $nodeLocation ?? $location,
                summary: $tipSummary,
                symbol: $symbolContext->inferredType ?? $this->bestSymbol($symbolContext),
                ruleIdentifier: $ruleIdentifier,
            );
        }

        if ($traitLocation !== null) {
            $hops[] = new TraceHop(
                location: $traitLocation,
                summary: 'Related declaration is traced through an imported trait.',
                symbol: $this->bestSymbol($symbolContext),
                ruleIdentifier: $ruleIdentifier,
            );
        }

        if (str_contains(strtolower($message), 'null')) {
            $hops[] = new TraceHop(
                location: $location,
                summary: 'Nullable value propagated into a stricter type expectation.',
                symbol: $this->bestSymbol($symbolContext),
                ruleIdentifier: $ruleIdentifier,
            );
        }

        return new ContextTrace($hops);
    }

    private function bestSymbol(SymbolContext $symbolContext): ?string
    {
        return $symbolContext->methodName ?? $symbolContext->propertyName ?? $symbolContext->functionName ?? $symbolContext->className;
    }

    private function describeNodeType(?string $nodeType): string
    {
        if ($nodeType === null || $nodeType === '') {
            return 'unknown';
        }

        $shortName = str_contains($nodeType, '\\') ? (string) substr($nodeType, strrpos($nodeType, '\\') + 1) : $nodeType;

        return $shortName !== '' ? $shortName : 'unknown';
    }

    private function tipSummary(?string $tip, ?string $typeOrigin): ?string
    {
        if ($tip === null) {
            return null;
        }

        $plain = preg_replace('/<[^>]+>/', '', $tip);
        if (!is_string($plain)) {
            return null;
        }

        $lines = preg_split('/\R+/', trim($plain));
        if (!is_array($lines)) {
            return null;
        }

        foreach ($lines as $line) {
            $normalized = trim(ltrim($line, self::TIP_BULLET_TRIM_CHARS));
            if ($normalized === '' || str_starts_with($normalized, 'Learn more at ')) {
                continue;
            }

            if ($typeOrigin === 'phpdoc' && str_contains(strtolower($normalized), 'because the type is coming from a phpdoc')) {
                return 'PHPStan reports that the current type certainty comes from PHPDoc.';
            }

            return 'PHPStan tip: ' . rtrim($normalized, '.') . '.';
        }

        return null;
    }
}
