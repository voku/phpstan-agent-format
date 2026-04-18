<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Context;

use Voku\PhpstanAgentFormat\Dto\ContextTrace;
use Voku\PhpstanAgentFormat\Dto\FileLocation;
use Voku\PhpstanAgentFormat\Dto\SymbolContext;
use Voku\PhpstanAgentFormat\Dto\TraceHop;
use Voku\PhpstanAgentFormat\Support\PhpstanTipHints;

final class ContextTraceBuilder
{
    private const TIP_IGNORED_PREFIX_CHARS = "• \t-";

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
        $hops = [];
        $this->appendHop($hops, new TraceHop(
            kind: 'primary',
            location: $location,
            summary: 'Primary static-analysis finding.',
            symbol: $this->bestSymbol($symbolContext),
            ruleIdentifier: $ruleIdentifier,
        ));

        if ($nodeLocation !== null) {
            $this->appendHop($hops, new TraceHop(
                kind: 'ast-node',
                location: $nodeLocation,
                summary: sprintf('Attached AST node: %s.', $this->describeNodeType($nodeType)),
                symbol: $this->bestSymbol($symbolContext),
                ruleIdentifier: $ruleIdentifier,
            ));
        }

        if ($symbolContext->typeOrigin !== null) {
            $this->appendHop($hops, new TraceHop(
                kind: 'type-origin',
                location: $location,
                summary: $symbolContext->typeOrigin === 'phpdoc'
                    ? 'Type certainty comes from PHPDoc.'
                    : sprintf('Type origin: %s.', $symbolContext->typeOrigin),
                symbol: $symbolContext->inferredType,
                ruleIdentifier: $ruleIdentifier,
            ));
        }

        $tipSummary = $this->tipSummary($tip, $symbolContext->typeOrigin);
        if ($tipSummary !== null) {
            $this->appendHop($hops, new TraceHop(
                kind: 'phpstan-tip',
                location: $nodeLocation ?? $location,
                summary: $tipSummary,
                symbol: $symbolContext->inferredType ?? $this->bestSymbol($symbolContext),
                ruleIdentifier: $ruleIdentifier,
            ));
        }

        if ($traitLocation !== null) {
            $this->appendHop($hops, new TraceHop(
                kind: 'trait-declaration',
                location: $traitLocation,
                summary: 'Related declaration comes from an imported trait.',
                symbol: $this->bestSymbol($symbolContext),
                ruleIdentifier: $ruleIdentifier,
            ));
        }

        if (str_contains(strtolower($message), 'null')) {
            $this->appendHop($hops, new TraceHop(
                kind: 'nullable-propagation',
                location: $location,
                summary: 'Nullable value propagated into a stricter type expectation.',
                symbol: $this->bestSymbol($symbolContext),
                ruleIdentifier: $ruleIdentifier,
            ));
        }

        return new ContextTrace($hops);
    }

    /**
     * @param list<TraceHop> $hops
     */
    private function appendHop(array &$hops, TraceHop $hop): void
    {
        foreach ($hops as $existing) {
            if (
                $existing->kind === $hop->kind
                && $existing->location->file === $hop->location->file
                && $existing->location->line === $hop->location->line
                && $existing->summary === $hop->summary
                && $existing->symbol === $hop->symbol
                && $existing->ruleIdentifier === $hop->ruleIdentifier
            ) {
                return;
            }
        }

        $hops[] = $hop;
    }

    private function bestSymbol(SymbolContext $symbolContext): ?string
    {
        if ($symbolContext->methodName !== null && $symbolContext->parameterName !== null) {
            return sprintf('%s($%s)', $symbolContext->methodName, $symbolContext->parameterName);
        }

        if ($symbolContext->propertyName !== null) {
            return $symbolContext->className !== null
                ? sprintf('%s::$%s', $symbolContext->className, $symbolContext->propertyName)
                : '$' . $symbolContext->propertyName;
        }

        return $symbolContext->methodName ?? $symbolContext->functionName ?? $symbolContext->className;
    }

    private function describeNodeType(?string $nodeType): string
    {
        if ($nodeType === null || $nodeType === '') {
            return 'unknown';
        }

        $nodeType = trim($nodeType);
        if ($nodeType === '') {
            return 'unknown';
        }

        $nodeType = rtrim($nodeType, '\\');
        if ($nodeType === '') {
            return 'unknown';
        }

        $shortName = str_contains($nodeType, '\\') ? substr($nodeType, strrpos($nodeType, '\\') + 1) : $nodeType;

        return $shortName !== '' ? $shortName : 'unknown';
    }

    private function tipSummary(?string $tip, ?string $typeOrigin): ?string
    {
        if ($tip === null) {
            return null;
        }

        $plain = PhpstanTipHints::stripFormatting($tip);

        $lines = preg_split('/\R+/', trim($plain));
        if (!is_array($lines)) {
            return null;
        }

        foreach ($lines as $line) {
            $normalized = trim(ltrim($line, self::TIP_IGNORED_PREFIX_CHARS));
            if ($normalized === '' || str_starts_with($normalized, 'Learn more at ')) {
                continue;
            }

            if ($typeOrigin === 'phpdoc' && str_contains(strtolower($normalized), PhpstanTipHints::PHPDOC_TYPE_ORIGIN_FRAGMENT)) {
                return 'PHPStan reports that the current type certainty comes from PHPDoc.';
            }

            return 'PHPStan tip: ' . rtrim($normalized, '.') . '.';
        }

        return null;
    }
}
