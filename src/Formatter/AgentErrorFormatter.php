<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Formatter;

use Exception;
use InvalidArgumentException;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\ErrorFormatter\ErrorFormatter;
use PHPStan\Command\Output;
use Voku\PhpstanAgentFormat\Budget\TokenBudgetReducer;
use Voku\PhpstanAgentFormat\Cluster\IssueClusterer;
use Voku\PhpstanAgentFormat\Config\AgentFormatConfig;
use Voku\PhpstanAgentFormat\Contract\AgentSerializerInterface;
use Voku\PhpstanAgentFormat\Context\ContextExtractor;
use Voku\PhpstanAgentFormat\Context\ContextTraceBuilder;
use Voku\PhpstanAgentFormat\Context\DocblockExtractor;
use Voku\PhpstanAgentFormat\Context\RelatedDefinitionExtractor;
use Voku\PhpstanAgentFormat\Context\Redactor;
use Voku\PhpstanAgentFormat\Context\PhpSymbolScanner;
use Voku\PhpstanAgentFormat\Dto\Notice;
use Voku\PhpstanAgentFormat\ItpContext\ArchitectureNoticeBuilder;
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
    private readonly Redactor $redactor;

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        array $parameters = [],
        ?IssueNormalizer $issueNormalizer = null,
        ?IssueClusterer $issueClusterer = null,
        ?TokenBudgetReducer $tokenBudgetReducer = null,
        ?AgentFormatConfig $config = null,
        private readonly ArchitectureNoticeBuilder $architectureNotices = new ArchitectureNoticeBuilder(),
    ) {
        $this->config = $config ?? AgentFormatConfig::fromParameters($parameters);
        $symbolScanner = new PhpSymbolScanner();
        $redactor = $this->redactor = new Redactor($this->config->redactPatterns);
        $this->presentationBuilder = new AgentPresentationBuilder(
            $this->config,
            $issueNormalizer ?? new IssueNormalizer(
                new ContextExtractor($this->config, $redactor),
                new ContextTraceBuilder(),
                $this->config,
                new DocblockExtractor($symbolScanner, $redactor),
                new RelatedDefinitionExtractor($symbolScanner, $redactor),
            ),
            $issueClusterer ?? new IssueClusterer($this->config),
            $tokenBudgetReducer ?? new TokenBudgetReducer($this->config),
        );
    }

    public function formatErrors(AnalysisResult $analysisResult, Output $output): int
    {
        $serialized = $this->serializerForMode($this->config->outputMode)
            ->serialize($this->presentationBuilder->buildFromAnalysisResult($analysisResult, $this->architectureNotices($analysisResult)));

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

    /**
     * Derived from the files that carry file-specific PHPStan issues in this very report, so nothing can go stale
     * and a green run or a file-independent error gets no architecture context.
     *
     * @return list<Notice>
     */
    private function architectureNotices(AnalysisResult $analysisResult): array
    {
        if (!$this->config->itpContext) {
            return [];
        }

        // A misconfiguration must be loud on green runs too, not only once the first error appears.
        $this->architectureNotices->assertAvailable();

        if ($analysisResult->getFileSpecificErrors() === []) {
            return [];
        }

        // Architecture context is advisory: whatever goes wrong while building it must never hide the real PHPStan issues.
        try {
            $notices = $this->architectureNotices->fromAnalysisResult($analysisResult);
        } catch (Exception $exception) {
            $notices = [new Notice(
                id: 'architecture[unavailable]',
                title: 'Architecture context unavailable',
                message: $exception->getMessage(),
            )];
        }

        $redacted = [];
        $omitted = 0;
        foreach ($notices as $notice) {
            try {
                $redacted[] = $notice->mapStrings($this->redactor->redact(...));
            } catch (InvalidArgumentException) {
                // Notice IDs are never rewritten (that could merge distinct notices); a sensitive one is dropped and counted.
                $omitted++;
            }
        }

        if ($omitted > 0) {
            $redacted[] = new Notice(
                id: 'architecture[omitted]',
                title: 'Architecture notices omitted',
                message: sprintf('%d architecture notice(s) omitted because their rule id matches redactPatterns.', $omitted),
            );
        }

        return $redacted;
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
