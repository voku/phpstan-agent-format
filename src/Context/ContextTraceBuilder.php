<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Context;

use Voku\PhpstanAgentFormat\Dto\ContextTrace;
use Voku\PhpstanAgentFormat\Dto\FileLocation;
use Voku\PhpstanAgentFormat\Dto\SymbolContext;
use Voku\PhpstanAgentFormat\Dto\TraceHop;

final class ContextTraceBuilder
{
    public function build(FileLocation $location, SymbolContext $symbolContext, ?string $ruleIdentifier, string $message): ContextTrace
    {
        $hops = [
            new TraceHop(
                location: $location,
                summary: 'Primary static-analysis finding.',
                symbol: $this->bestSymbol($symbolContext),
                ruleIdentifier: $ruleIdentifier,
            ),
        ];

        if ($symbolContext->typeOrigin !== null) {
            $hops[] = new TraceHop(
                location: $location,
                summary: sprintf('Inferred type origin: %s', $symbolContext->typeOrigin),
                symbol: $symbolContext->inferredType,
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
}
