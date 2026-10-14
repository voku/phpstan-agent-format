<?php

declare(strict_types=1);

namespace ItpContext\Model {
    if (!class_exists(ApplicableRule::class)) {
        final readonly class ApplicableRule
        {
            /**
             * @param list<string> $refs
             * @param list<string> $subjects
             */
            public function __construct(
                public string $ruleId,
                public string $statement,
                public ?string $changeGuidance,
                public array $refs,
                public array $subjects,
            ) {
            }
        }
    }
}

namespace ItpContext\Service {
    if (!class_exists(ApplicableRuleResolver::class)) {
        /**
         * Test double for voku/itp-context. The `ITP_CONTEXT_STUB_RULES` global maps file => list<ApplicableRule>.
         */
        final class ApplicableRuleResolver
        {
            /**
             * @param iterable<string> $files
             * @return list<\ItpContext\Model\ApplicableRule>
             */
            public function resolveFiles(iterable $files): array
            {
                /** @var array<string, list<\ItpContext\Model\ApplicableRule>> $byFile */
                $byFile = $GLOBALS['ITP_CONTEXT_STUB_RULES'] ?? [];
                $rules = [];
                foreach ($files as $file) {
                    foreach ($byFile[$file] ?? [] as $rule) {
                        $rules[$rule->ruleId] = $rule;
                    }
                }
                ksort($rules);

                return array_values($rules);
            }
        }
    }
}
