<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Support;

use Voku\PhpstanAgentFormat\Formatter\AgentErrorFormatter;

final class PhpstanReportParity
{
    public static function assertFixtureParity(string $root, string $configPath): void
    {
        [$phpstanJson, $jsonExitCode] = self::runPhpstan($root, $configPath, 'json');
        [$agentJson, $agentExitCode] = self::runPhpstan($root, $configPath, 'agent');

        TestCase::assertSame(1, $jsonExitCode, 'The default PHPStan JSON report should fail for the shared fixture matrix.');
        TestCase::assertSame(1, $agentExitCode, 'The agent formatter report should fail for the shared fixture matrix.');

        $phpstanDecoded = self::decodePhpstanReport($phpstanJson);
        $agentDecoded = self::decodeAgentReport($agentJson);
        $reformattedDecoded = self::decodeAgentReport(self::formatPhpstanJsonExport($phpstanJson));

        $expectedIssues = self::phpstanIssues($phpstanDecoded);
        $agentIssues = self::agentIssues($agentDecoded);
        $reformattedIssues = self::agentIssues($reformattedDecoded);

        TestCase::assertSame(
            $phpstanDecoded['totals']['errors'] + $phpstanDecoded['totals']['file_errors'],
            $agentDecoded['summary']['totalIssues'],
            'Agent output should preserve the total issue count from the default PHPStan JSON report.',
        );
        TestCase::assertSame($agentDecoded['summary']['totalIssues'], $reformattedDecoded['summary']['totalIssues'], 'Reformatting the default PHPStan JSON report should preserve the agent issue count.');
        TestCase::assertSame($expectedIssues, $agentIssues, 'Agent CLI output should preserve each PHPStan message, rule identifier, and location.');
        TestCase::assertSame($expectedIssues, $reformattedIssues, 'Reformatted PHPStan JSON output should preserve each PHPStan message, rule identifier, and location.');

        $expectedUndefinedMethods = [
            $root . '/tests/Fixture/UndefinedMethods.php:11',
            $root . '/tests/Fixture/UndefinedMethods.php:12',
        ];
        sort($expectedUndefinedMethods);
        TestCase::assertSame($expectedUndefinedMethods, self::affectedFilesForPath($agentDecoded, $root . '/tests/Fixture/UndefinedMethods.php'), 'Agent output should preserve multiple same-file issues as distinct path:line references.');
        TestCase::assertSame($expectedUndefinedMethods, self::affectedFilesForPath($reformattedDecoded, $root . '/tests/Fixture/UndefinedMethods.php'), 'Reformatted PHPStan JSON output should preserve multiple same-file issues as distinct path:line references.');
    }

    public static function assertReducedFixtureParity(string $root, string $configPath): void
    {
        [$phpstanJson, $jsonExitCode] = self::runPhpstan($root, $configPath, 'json');
        [$reducedAgentJson, $agentExitCode] = self::runPhpstan($root, $configPath, 'agent');

        TestCase::assertSame(1, $jsonExitCode, 'The default PHPStan JSON report should fail for the reduced fixture.');
        TestCase::assertSame(1, $agentExitCode, 'The reduced agent formatter report should fail for the reduced fixture.');

        $phpstanDecoded = self::decodePhpstanReport($phpstanJson);
        $fullAgentDecoded = self::decodeAgentReport(self::formatPhpstanJsonExport($phpstanJson));
        $reducedAgentDecoded = self::decodeAgentReport($reducedAgentJson);

        $expectedIssues = self::phpstanIssues($phpstanDecoded);
        $fullAgentIssues = self::agentIssues($fullAgentDecoded);

        TestCase::assertSame(
            $phpstanDecoded['totals']['errors'] + $phpstanDecoded['totals']['file_errors'],
            $fullAgentDecoded['summary']['totalIssues'],
            'Full grouped output should preserve the total issue count before reduction.',
        );
        TestCase::assertSame(
            $fullAgentDecoded['summary']['totalIssues'],
            $reducedAgentDecoded['summary']['totalIssues'],
            'Reduced grouped output should preserve the total issue count.',
        );
        TestCase::assertSame($expectedIssues, $fullAgentIssues, 'Full grouped output should preserve each PHPStan message, rule identifier, and location before reduction.');
        TestCase::assertTrue($reducedAgentDecoded['summary']['tokenStats']['wasReduced'], 'Reduced comparison should exercise token-budget reduction.');

        self::assertReducedSemanticParity($fullAgentDecoded, $reducedAgentDecoded);

        $duplicatePath = $root . '/tests/Fixture/DuplicateUndefinedMethod.php';
        $expectedDuplicateLocations = [
            $duplicatePath . ':11',
            $duplicatePath . ':12',
        ];
        sort($expectedDuplicateLocations);
        TestCase::assertSame($expectedDuplicateLocations, self::affectedFilesForPath($reducedAgentDecoded, $duplicatePath), 'Reduced output should preserve same-file duplicate issues as distinct path:line references.');
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function runPhpstan(string $root, string $configPath, string $errorFormat): array
    {
        $outputLines = [];
        $exitCode = 0;

        exec(sprintf(
            '%s %s analyse --configuration %s --error-format=%s --no-progress 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/vendor/bin/phpstan'),
            escapeshellarg($configPath),
            escapeshellarg($errorFormat),
        ), $outputLines, $exitCode);

        return [implode("\n", $outputLines), $exitCode];
    }

    /**
     * @return array{
     *   totals: array{errors: int, file_errors: int},
     *   files: array<string, array{messages: list<array{message: string, line?: int, identifier?: string}>}>,
     *   errors: list<string>
     * }
     */
    private static function decodePhpstanReport(string $payload): array
    {
        /** @var array{
         *   totals: array{errors: int, file_errors: int},
         *   files: array<string, array{messages: list<array{message: string, line?: int, identifier?: string}>}>,
         *   errors: list<string>
         * } $decoded
         */
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array{
     *   summary: array{
     *     totalIssues: int,
     *     suppressedDuplicates: int,
     *     tokenStats: array{estimatedTokens: int, tokenBudget: int, wasReduced: bool}
     *   },
     *   clusters: list<array{
     *     clusterId: string,
     *     kind: string,
     *     ruleIdentifier: ?string,
     *     affectedFiles: list<string>,
     *     representativeIssues: list<array{
     *       id: string,
     *       message: string,
     *       ruleIdentifier: ?string,
     *       location: array{file: string, line: int},
     *       snippet: array{startLine: int, highlightLine: int, lines: list<string>},
     *       secondaryLocations: list<array{file: string, line: int}>
     *     }>,
     *     suppressedDuplicateCount: int
     *   }>
     * }
     */
    private static function decodeAgentReport(string $payload): array
    {
        /** @var array{
         *   summary: array{
         *     totalIssues: int,
         *     suppressedDuplicates: int,
         *     tokenStats: array{estimatedTokens: int, tokenBudget: int, wasReduced: bool}
         *   },
         *   clusters: list<array{
         *     clusterId: string,
         *     kind: string,
         *     ruleIdentifier: ?string,
         *     affectedFiles: list<string>,
         *     representativeIssues: list<array{
         *       id: string,
         *       message: string,
         *       ruleIdentifier: ?string,
         *       location: array{file: string, line: int},
         *       snippet: array{startLine: int, highlightLine: int, lines: list<string>},
         *       secondaryLocations: list<array{file: string, line: int}>
         *     }>,
         *     suppressedDuplicateCount: int
         *   }>
         * } $decoded
         */
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private static function formatPhpstanJsonExport(string $payload, int $tokenBudget = 12000): string
    {
        return (new AgentErrorFormatter([
            'agentFormat' => [
                'outputMode' => 'json',
                'tokenBudget' => $tokenBudget,
            ],
        ]))->formatPhpstanJsonExport($payload);
    }

    /**
     * @param array{
     *   files: array<string, array{messages: list<array{message: string, line?: int, identifier?: string}>}>,
     *   errors: list<string>
     * } $decoded
     * @return list<array{message: string, file: string, line: int, ruleIdentifier: ?string}>
     */
    private static function phpstanIssues(array $decoded): array
    {
        $issues = [];

        foreach ($decoded['files'] as $file => $entry) {
            foreach ($entry['messages'] as $message) {
                $issues[] = [
                    'message' => $message['message'],
                    'file' => $file,
                    'line' => $message['line'] ?? 1,
                    'ruleIdentifier' => ($message['identifier'] ?? '') !== '' ? $message['identifier'] : null,
                ];
            }
        }

        foreach ($decoded['errors'] as $message) {
            $issues[] = [
                'message' => $message,
                'file' => '',
                'line' => 1,
                'ruleIdentifier' => null,
            ];
        }

        usort($issues, self::issueSorter(...));

        return $issues;
    }

    /**
     * @param array{
     *   clusters: list<array{
     *     representativeIssues: list<array{
     *       message: string,
     *       ruleIdentifier: ?string,
     *       location: array{file: string, line: int}
     *     }>
     *   }>
     * } $decoded
     * @return list<array{message: string, file: string, line: int, ruleIdentifier: ?string}>
     */
    private static function agentIssues(array $decoded): array
    {
        $issues = [];

        foreach ($decoded['clusters'] as $cluster) {
            foreach ($cluster['representativeIssues'] as $issue) {
                $issues[] = [
                    'message' => $issue['message'],
                    'file' => $issue['location']['file'],
                    'line' => $issue['location']['line'],
                    'ruleIdentifier' => $issue['ruleIdentifier'],
                ];
            }
        }

        usort($issues, self::issueSorter(...));

        return $issues;
    }

    /**
     * @param array{
     *   summary: array{suppressedDuplicates: int},
     *   clusters: list<array{
     *     clusterId: string,
     *     ruleIdentifier: ?string,
     *     affectedFiles: list<string>,
     *     representativeIssues: list<array{
     *       id: string,
     *       message: string,
     *       ruleIdentifier: ?string,
     *       location: array{file: string, line: int},
     *       snippet: array{startLine: int, highlightLine: int, lines: list<string>},
     *       secondaryLocations: list<array{file: string, line: int}>
     *     }>,
     *     suppressedDuplicateCount: int
     *   }>
     * } $fullDecoded
     * @param array{
     *   summary: array{suppressedDuplicates: int},
     *   clusters: list<array{
     *     clusterId: string,
     *     ruleIdentifier: ?string,
     *     affectedFiles: list<string>,
     *     representativeIssues: list<array{
     *       id: string,
     *       message: string,
     *       ruleIdentifier: ?string,
     *       location: array{file: string, line: int},
     *       snippet: array{startLine: int, highlightLine: int, lines: list<string>},
     *       secondaryLocations: list<array{file: string, line: int}>
     *     }>,
     *     suppressedDuplicateCount: int
     *   }>
     * } $reducedDecoded
     */
    private static function assertReducedSemanticParity(array $fullDecoded, array $reducedDecoded): void
    {
        $fullClusters = self::clustersById($fullDecoded);
        $reducedClusters = self::clustersById($reducedDecoded);

        TestCase::assertSame(array_keys($fullClusters), array_keys($reducedClusters), 'Reduction should preserve cluster identities.');

        $trimmedRepresentatives = 0;

        foreach ($fullClusters as $clusterId => $fullCluster) {
            $reducedCluster = $reducedClusters[$clusterId];

            TestCase::assertSame($fullCluster['ruleIdentifier'], $reducedCluster['ruleIdentifier'], 'Reduction should preserve each cluster rule identifier.');
            TestCase::assertSame($fullCluster['affectedFiles'], $reducedCluster['affectedFiles'], 'Reduction should preserve each cluster affectedFiles list.');
            TestCase::assertTrue(count($reducedCluster['representativeIssues']) >= 1, 'Reduction should keep at least one representative issue per cluster.');
            TestCase::assertTrue(count($reducedCluster['representativeIssues']) <= count($fullCluster['representativeIssues']), 'Reduction should not invent new representative issues.');

            $trimmedInCluster = count($fullCluster['representativeIssues']) - count($reducedCluster['representativeIssues']);
            $trimmedRepresentatives += $trimmedInCluster;

            TestCase::assertSame(
                $fullCluster['suppressedDuplicateCount'] + $trimmedInCluster,
                $reducedCluster['suppressedDuplicateCount'],
                'Reduction should account for trimmed representative issues via suppressed duplicates.',
            );

            self::assertReducedClusterStillRepresentsFullIssues($fullCluster, $reducedCluster);
            self::assertAllowedLossiness($fullCluster['representativeIssues'], $reducedCluster['representativeIssues']);
        }

        TestCase::assertSame(
            $fullDecoded['summary']['suppressedDuplicates'] + $trimmedRepresentatives,
            $reducedDecoded['summary']['suppressedDuplicates'],
            'Reduction should preserve trimmed issue counts in the top-level suppressed duplicate total.',
        );
    }

    /**
     * @param array{
     *   ruleIdentifier: ?string,
     *   affectedFiles: list<string>,
     *   representativeIssues: list<array{
     *     id: string,
     *     message: string,
     *     ruleIdentifier: ?string,
     *     location: array{file: string, line: int},
     *     snippet: array{startLine: int, highlightLine: int, lines: list<string>},
     *     secondaryLocations: list<array{file: string, line: int}>
     *   }>
     * } $fullCluster
     * @param array{
     *   ruleIdentifier: ?string,
     *   affectedFiles: list<string>,
     *   representativeIssues: list<array{
     *     id: string,
     *     message: string,
     *     ruleIdentifier: ?string,
     *     location: array{file: string, line: int},
     *     snippet: array{startLine: int, highlightLine: int, lines: list<string>},
     *     secondaryLocations: list<array{file: string, line: int}>
     *   }>
     * } $reducedCluster
     */
    private static function assertReducedClusterStillRepresentsFullIssues(array $fullCluster, array $reducedCluster): void
    {
        $reducedMessages = [];
        $reducedRules = [];

        foreach ($reducedCluster['representativeIssues'] as $issue) {
            $reducedMessages[$issue['message']] = true;
            if ($issue['ruleIdentifier'] !== null) {
                $reducedRules[$issue['ruleIdentifier']] = true;
            }
        }

        foreach ($fullCluster['representativeIssues'] as $issue) {
            TestCase::assertTrue(
                in_array(self::locationReference($issue['location']['file'], $issue['location']['line']), $reducedCluster['affectedFiles'], true),
                'Reduction should keep every representative issue location traceable via affectedFiles.',
            );
            TestCase::assertTrue(isset($reducedMessages[$issue['message']]), 'Reduction should keep each unique representative message visible.');
            if ($issue['ruleIdentifier'] !== null) {
                TestCase::assertTrue(isset($reducedRules[$issue['ruleIdentifier']]), 'Reduction should keep each representative rule identifier visible.');
            }
        }
    }

    /**
     * @param list<array{
     *   id: string,
     *   message: string,
     *   ruleIdentifier: ?string,
     *   location: array{file: string, line: int},
     *   snippet: array{startLine: int, highlightLine: int, lines: list<string>},
     *   secondaryLocations: list<array{file: string, line: int}>
     * }> $fullIssues
     * @param list<array{
     *   id: string,
     *   message: string,
     *   ruleIdentifier: ?string,
     *   location: array{file: string, line: int},
     *   snippet: array{startLine: int, highlightLine: int, lines: list<string>},
     *   secondaryLocations: list<array{file: string, line: int}>
     * }> $reducedIssues
     */
    private static function assertAllowedLossiness(array $fullIssues, array $reducedIssues): void
    {
        $reducedIssuesById = [];
        foreach ($reducedIssues as $issue) {
            $reducedIssuesById[$issue['id']] = $issue;
        }

        foreach ($fullIssues as $issue) {
            if (!isset($reducedIssuesById[$issue['id']])) {
                continue;
            }

            $reducedIssue = $reducedIssuesById[$issue['id']];

            TestCase::assertSame($issue['message'], $reducedIssue['message'], 'Reduction should not rewrite surviving representative messages.');
            TestCase::assertSame($issue['ruleIdentifier'], $reducedIssue['ruleIdentifier'], 'Reduction should not rewrite surviving representative rule identifiers.');
            TestCase::assertSame($issue['location'], $reducedIssue['location'], 'Reduction should not rewrite surviving representative locations.');

            if ($issue['secondaryLocations'] !== $reducedIssue['secondaryLocations']) {
                TestCase::assertSame([], $reducedIssue['secondaryLocations'], 'Reduction may only remove secondary locations.');
            }

            if ($issue['snippet'] !== $reducedIssue['snippet']) {
                TestCase::assertSame($reducedIssue['location']['line'], $reducedIssue['snippet']['startLine'], 'Collapsed snippets should start at the representative issue line.');
                TestCase::assertSame($reducedIssue['location']['line'], $reducedIssue['snippet']['highlightLine'], 'Collapsed snippets should keep the representative issue line highlighted.');
                TestCase::assertSame([self::highlightedSnippetLine($issue['snippet'])], $reducedIssue['snippet']['lines'], 'Collapsed snippets should keep the highlighted line content traceable.');
            }
        }
    }

    /**
     * @param array{
     *   startLine: int,
     *   highlightLine: int,
     *   lines: list<string>
     * } $issue
     */
    private static function highlightedSnippetLine(array $issue): string
    {
        return $issue['lines'][$issue['highlightLine'] - $issue['startLine']] ?? ($issue['lines'][0] ?? '');
    }

    /**
     * @param array{
     *   clusters: list<array{
     *     clusterId: string,
     *     ruleIdentifier: ?string,
     *     affectedFiles: list<string>,
     *     representativeIssues: list<array{
     *       id: string,
     *       message: string,
     *       ruleIdentifier: ?string,
     *       location: array{file: string, line: int},
     *       snippet: array{startLine: int, highlightLine: int, lines: list<string>},
     *       secondaryLocations: list<array{file: string, line: int}>
     *     }>,
     *     suppressedDuplicateCount: int
     *   }>
     * } $decoded
     * @return array<string, array{
     *   clusterId: string,
     *   ruleIdentifier: ?string,
     *   affectedFiles: list<string>,
     *   representativeIssues: list<array{
     *     id: string,
     *     message: string,
     *     ruleIdentifier: ?string,
     *     location: array{file: string, line: int},
     *     snippet: array{startLine: int, highlightLine: int, lines: list<string>},
     *     secondaryLocations: list<array{file: string, line: int}>
     *   }>,
     *   suppressedDuplicateCount: int
     * }>
     */
    private static function clustersById(array $decoded): array
    {
        $clusters = [];
        foreach ($decoded['clusters'] as $cluster) {
            $clusters[$cluster['clusterId']] = $cluster;
        }

        ksort($clusters);

        return $clusters;
    }

    /**
     * @param array{
     *   clusters: list<array{affectedFiles: list<string>}>
     * } $decoded
     * @return list<string>
     */
    private static function affectedFilesForPath(array $decoded, string $path): array
    {
        $matches = [];

        foreach ($decoded['clusters'] as $cluster) {
            foreach ($cluster['affectedFiles'] as $affectedFile) {
                if (str_starts_with($affectedFile, $path . ':')) {
                    $matches[] = $affectedFile;
                }
            }
        }

        sort($matches);

        return $matches;
    }

    /**
     * @param array{message: string, file: string, line: int, ruleIdentifier: ?string} $left
     * @param array{message: string, file: string, line: int, ruleIdentifier: ?string} $right
     */
    private static function issueSorter(array $left, array $right): int
    {
        return [$left['file'], $left['line'], $left['ruleIdentifier'] ?? '', $left['message']]
            <=> [$right['file'], $right['line'], $right['ruleIdentifier'] ?? '', $right['message']];
    }

    private static function locationReference(string $file, int $line): string
    {
        return $file . ':' . $line;
    }
}
