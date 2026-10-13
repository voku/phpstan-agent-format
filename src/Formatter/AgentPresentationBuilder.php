<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Formatter;

use Composer\InstalledVersions;
use PHPStan\Command\AnalysisResult;
use Voku\PhpstanAgentFormat\Budget\TokenBudgetReducer;
use Voku\PhpstanAgentFormat\Cluster\IssueClusterer;
use Voku\PhpstanAgentFormat\Config\AgentFormatConfig;
use Voku\PhpstanAgentFormat\Dto\Notice;
use Voku\PhpstanAgentFormat\Dto\PresentationResult;
use Voku\PhpstanAgentFormat\Dto\TokenStats;
use Voku\PhpstanAgentFormat\Ingestion\PhpstanJsonExportIngestor;
use Voku\PhpstanAgentFormat\Normalizer\IssueNormalizer;

final readonly class AgentPresentationBuilder
{
    public const TOOL_NAME = 'phpstan-agent-format';

    public function __construct(
        private AgentFormatConfig $config,
        private IssueNormalizer $issueNormalizer,
        private IssueClusterer $issueClusterer,
        private TokenBudgetReducer $tokenBudgetReducer,
    ) {
    }

    /**
     * @param list<Notice> $notices
     */
    public function buildFromAnalysisResult(AnalysisResult $analysisResult, array $notices = []): PresentationResult
    {
        return $this->buildFromNormalizedIssues($this->issueNormalizer->normalize($analysisResult), $notices);
    }

    /**
     * @param array<string, mixed>|string $payload
     * @param list<Notice> $notices
     */
    public function buildFromPhpstanJsonExport(array|string $payload, array $notices = []): PresentationResult
    {
        $ingested = (new PhpstanJsonExportIngestor())->ingest($payload);

        return $this->buildFromNormalizedIssues(
            $this->issueNormalizer->normalizeRaw(
                $ingested['fileSpecificErrors'],
                $ingested['notFileSpecificErrors'],
            ),
            $notices,
        );
    }

    /**
     * @param list<\Voku\PhpstanAgentFormat\Dto\AgentIssue> $issues
     * @param list<Notice> $notices
     */
    private function buildFromNormalizedIssues(array $issues, array $notices): PresentationResult
    {
        $clusters = $this->issueClusterer->cluster($issues);

        $suppressedDuplicates = 0;
        foreach ($clusters as $cluster) {
            $suppressedDuplicates += $cluster->suppressedDuplicateCount;
        }

        $presentation = new PresentationResult(
            tool: self::TOOL_NAME,
            phpstanVersion: $this->phpstanVersion(),
            totalIssues: count($issues),
            suppressedDuplicates: $suppressedDuplicates,
            clusters: $clusters,
            tokenStats: new TokenStats(0, $this->config->tokenBudget, false),
            notices: $notices,
        );

        return $this->tokenBudgetReducer->reduce($presentation);
    }

    private function phpstanVersion(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('phpstan/phpstan')) {
            $version = InstalledVersions::getPrettyVersion('phpstan/phpstan');
            if (is_string($version) && $version !== '') {
                return $version;
            }
        }

        return '2.1.x';
    }
}
