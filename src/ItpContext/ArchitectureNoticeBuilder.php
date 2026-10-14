<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\ItpContext;

use ItpContext\Model\ApplicableRule;
use ItpContext\Service\ApplicableRuleResolver;
use PHPStan\Command\AnalysisResult;
use RuntimeException;
use Voku\PhpstanAgentFormat\Dto\Notice;

/**
 * Turns the architecture rules of the files that carry PHPStan issues into one advisory notice per rule id.
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
        foreach ($analysisResult->getFileSpecificErrors() as $error) {
            // The analysed file and the file the error sits in (they differ for errors reported inside traits).
            foreach ([
                method_exists($error, 'getFile') ? $error->getFile() : null,
                method_exists($error, 'getFilePath') ? $error->getFilePath() : null,
            ] as $file) {
                if (is_string($file) && $file !== '') {
                    $files[$file] = true;
                }
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
