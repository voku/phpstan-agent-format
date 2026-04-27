<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Formatter;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;
use Voku\PhpstanAgentFormat\Budget\TokenBudgetReducer;
use Voku\PhpstanAgentFormat\Cluster\IssueClusterer;
use Voku\PhpstanAgentFormat\Config\AgentFormatConfig;
use Voku\PhpstanAgentFormat\Contract\AgentSerializerInterface;
use Voku\PhpstanAgentFormat\Context\ContextExtractor;
use Voku\PhpstanAgentFormat\Context\ContextTraceBuilder;
use Voku\PhpstanAgentFormat\Normalizer\IssueNormalizer;
use Voku\PhpstanAgentFormat\Serializer\CompactTextAgentSerializer;
use Voku\PhpstanAgentFormat\Serializer\JsonAgentSerializer;
use Voku\PhpstanAgentFormat\Serializer\MarkdownAgentSerializer;
use Voku\PhpstanAgentFormat\Serializer\NdjsonAgentSerializer;
use Voku\PhpstanAgentFormat\Serializer\ToonAgentSerializer;

final class AgentErrorFormatter implements ErrorFormatter
{
    private readonly AgentFormatConfig $config;
    private readonly AgentPresentationBuilder $presentationBuilder;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        array $parameters = [],
        ?IssueNormalizer $issueNormalizer = null,
        ?IssueClusterer $issueClusterer = null,
        ?TokenBudgetReducer $tokenBudgetReducer = null,
        ?AgentFormatConfig $config = null,
    ) {
        $this->config = $config ?? AgentFormatConfig::fromParameters($parameters);
        $this->presentationBuilder = new AgentPresentationBuilder(
            $this->config,
            $issueNormalizer ?? new IssueNormalizer(new ContextExtractor($this->config), new ContextTraceBuilder()),
            $issueClusterer ?? new IssueClusterer($this->config),
            $tokenBudgetReducer ?? new TokenBudgetReducer($this->config),
        );
    }

    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        $serialized = $this->serializerForMode($this->config->outputMode)
            ->serialize($this->presentationBuilder->buildFromAnalysisResult($analysisResult));

        $output->writeRaw($serialized);

        return $analysisResult->hasErrors() ? 1 : 0;
    }

    /**
     * @param array<string, mixed>|string $payload
     */
    public function formatPhpstanJsonExport(array|string $payload): string
    {
        return $this->serializerForMode($this->config->outputMode)
            ->serialize($this->presentationBuilder->buildFromPhpstanJsonExport($payload));
    }

    private function serializerForMode(string $mode): AgentSerializerInterface
    {
        return match ($mode) {
            'agentToon' => new ToonAgentSerializer(),
            'agentNdjson' => new NdjsonAgentSerializer(),
            'agentMarkdown' => new MarkdownAgentSerializer(),
            'agentCompact' => new CompactTextAgentSerializer(),
            default => new JsonAgentSerializer(),
        };
    }
}
