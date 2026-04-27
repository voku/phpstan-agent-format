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

        /** @var array{
         *   totals: array{errors: int, file_errors: int},
         *   files: array<string, array{messages: list<array{message: string, line?: int, identifier?: string}>}>,
         *   errors: list<string>
         * } $phpstanDecoded
         */
        $phpstanDecoded = json_decode($phpstanJson, true, 512, JSON_THROW_ON_ERROR);

        /** @var array{
         *   summary: array{totalIssues: int},
         *   clusters: list<array{
         *     kind: string,
         *     ruleIdentifier: ?string,
         *     affectedFiles: list<string>,
         *     representativeIssues: list<array{
         *       message: string,
         *       ruleIdentifier: ?string,
         *       location: array{file: string, line: int}
         *     }>
         *   }>
         * } $agentDecoded
         */
        $agentDecoded = json_decode($agentJson, true, 512, JSON_THROW_ON_ERROR);

        /** @var array{
         *   summary: array{totalIssues: int},
         *   clusters: list<array{
         *     kind: string,
         *     ruleIdentifier: ?string,
         *     affectedFiles: list<string>,
         *     representativeIssues: list<array{
         *       message: string,
         *       ruleIdentifier: ?string,
         *       location: array{file: string, line: int}
         *     }>
         *   }>
         * } $reformattedDecoded
         */
        $reformattedDecoded = json_decode((new AgentErrorFormatter([
            'agentFormat' => [
                'outputMode' => 'json',
            ],
        ]))->formatPhpstanJsonExport($phpstanJson), true, 512, JSON_THROW_ON_ERROR);

        $expectedIssues = self::phpstanIssuesByMessage($phpstanDecoded);
        $agentIssues = self::agentIssuesByMessage($agentDecoded);
        $reformattedIssues = self::agentIssuesByMessage($reformattedDecoded);

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
     * @param array{
     *   files: array<string, array{messages: list<array{message: string, line?: int, identifier?: string}>}>,
     *   errors: list<string>
     * } $decoded
     * @return array<string, array{file: string, line: int, ruleIdentifier: ?string}>
     */
    private static function phpstanIssuesByMessage(array $decoded): array
    {
        $issues = [];

        foreach ($decoded['files'] as $file => $entry) {
            foreach ($entry['messages'] as $message) {
                $issues[$message['message']] = [
                    'file' => $file,
                    'line' => $message['line'] ?? 1,
                    'ruleIdentifier' => ($message['identifier'] ?? '') !== '' ? $message['identifier'] : null,
                ];
            }
        }

        foreach ($decoded['errors'] as $message) {
            $issues[$message] = [
                'file' => '',
                'line' => 1,
                'ruleIdentifier' => null,
            ];
        }

        ksort($issues);

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
     * @return array<string, array{file: string, line: int, ruleIdentifier: ?string}>
     */
    private static function agentIssuesByMessage(array $decoded): array
    {
        $issues = [];

        foreach ($decoded['clusters'] as $cluster) {
            foreach ($cluster['representativeIssues'] as $issue) {
                $issues[$issue['message']] = [
                    'file' => $issue['location']['file'],
                    'line' => $issue['location']['line'],
                    'ruleIdentifier' => $issue['ruleIdentifier'],
                ];
            }
        }

        ksort($issues);

        return $issues;
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
}
