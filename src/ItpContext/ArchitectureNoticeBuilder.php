<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\ItpContext;

use ItpContext\Model\ApplicableRule;
use ItpContext\Service\ApplicableRuleResolver;
use PHPStan\Command\AnalysisResult;
use RuntimeException;
use Voku\PhpstanAgentFormat\Collector\AnalysedFileCollector;
use Voku\PhpstanAgentFormat\Dto\Notice;

/**
 * Turns the architecture rules of the analysed files into one advisory notice per rule id.
 */
final class ArchitectureNoticeBuilder
{
    public const SUBJECT_LIMIT = 3;

    public const MISSING_PACKAGE_MESSAGE = 'agentFormat.itpContext requires voku/itp-context.';

    public function assertAvailable(): void
    {
        if (!class_exists(ApplicableRuleResolver::class)) {
            throw new RuntimeException(self::MISSING_PACKAGE_MESSAGE);
        }
    }

    /**
     * @return list<Notice>
     */
    public function fromAnalysisResult(AnalysisResult $analysisResult): array
    {
        $this->assertAvailable();

        $files = [];
        foreach ($analysisResult->getCollectedData() as $collected) {
            if ($collected->getCollectorType() === AnalysedFileCollector::class) {
                $files[$collected->getFilePath()] = true;
            }
        }
        $files = array_keys($files);
        sort($files);

        return array_map($this->notice(...), (new ApplicableRuleResolver())->resolveFiles($files));
    }

    private function notice(ApplicableRule $rule): Notice
    {
        $subjects = array_slice($rule->subjects, 0, self::SUBJECT_LIMIT);
        $applies = implode(', ', $subjects);
        $more = count($rule->subjects) - count($subjects);
        if ($more > 0) {
            $applies .= sprintf(', +%d more', $more);
        }

        $message = 'Applies to: ' . $applies;
        if ($rule->changeGuidance !== null) {
            $message .= "\nWhen changing: " . $rule->changeGuidance;
        }

        return new Notice(
            id: 'architecture[' . $rule->ruleId . ']',
            title: $rule->statement,
            message: $message,
            refs: $rule->refs,
        );
    }
}
