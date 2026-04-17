<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Formatter;

use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;
use RuntimeException;
use Voku\PhpstanAgentFormat\Budget\TokenBudgetReducer;
use Voku\PhpstanAgentFormat\Cluster\IssueClusterer;
use Voku\PhpstanAgentFormat\Config\AgentFormatConfig;
use Voku\PhpstanAgentFormat\Contract\AgentSerializerInterface;
use Voku\PhpstanAgentFormat\Context\ContextExtractor;
use Voku\PhpstanAgentFormat\Context\ContextTraceBuilder;
use Voku\PhpstanAgentFormat\Dto\PresentationResult;
use Voku\PhpstanAgentFormat\Dto\TokenStats;
use Voku\PhpstanAgentFormat\Normalizer\IssueNormalizer;
use Voku\PhpstanAgentFormat\Serializer\CompactTextAgentSerializer;
use Voku\PhpstanAgentFormat\Serializer\JsonAgentSerializer;
use Voku\PhpstanAgentFormat\Serializer\MarkdownAgentSerializer;
use Voku\PhpstanAgentFormat\Serializer\NdjsonAgentSerializer;

final class AgentErrorFormatter implements ErrorFormatter
{
    private readonly AgentFormatConfig $config;
    private readonly IssueNormalizer $issueNormalizer;
    private readonly IssueClusterer $issueClusterer;
    private readonly TokenBudgetReducer $tokenBudgetReducer;

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
        $this->issueNormalizer = $issueNormalizer ?? new IssueNormalizer(new ContextExtractor($this->config), new ContextTraceBuilder());
        $this->issueClusterer = $issueClusterer ?? new IssueClusterer($this->config);
        $this->tokenBudgetReducer = $tokenBudgetReducer ?? new TokenBudgetReducer($this->config);
    }

    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        $issues = $this->issueNormalizer->normalize($analysisResult);
        $clusters = $this->issueClusterer->cluster($issues);

        $suppressedDuplicates = 0;
        foreach ($clusters as $cluster) {
            $suppressedDuplicates += $cluster->suppressedDuplicateCount;
        }

        $presentation = new PresentationResult(
            tool: 'phpstan-agent-format',
            version: '0.1.0',
            phpstanVersion: '2.1.x',
            totalIssues: count($issues),
            suppressedDuplicates: $suppressedDuplicates,
            clusters: $clusters,
            tokenStats: new TokenStats(0, $this->config->tokenBudget, false),
        );

        $reduced = $this->tokenBudgetReducer->reduce($presentation);
        $serialized = $this->serializerForMode($this->config->outputMode)->serialize($reduced);

        if (method_exists($output, 'writeRaw')) {
            $output->writeRaw($serialized);
        } elseif (method_exists($output, 'writeLineFormatted')) {
            $output->writeLineFormatted(rtrim($serialized, "\n"));
        } elseif (method_exists($output, 'writeLine')) {
            $output->writeLine(rtrim($serialized, "\n"));
        } else {
            throw new RuntimeException('Unknown PHPStan output API, cannot write formatted output.');
        }

        return $analysisResult->hasErrors() ? 1 : 0;
    }

    private function serializerForMode(string $mode): AgentSerializerInterface
    {
        return match ($mode) {
            'agentNdjson' => new NdjsonAgentSerializer(),
            'agentMarkdown' => new MarkdownAgentSerializer(),
            'agentCompact' => new CompactTextAgentSerializer(),
            default => new JsonAgentSerializer(),
        };
    }
}
