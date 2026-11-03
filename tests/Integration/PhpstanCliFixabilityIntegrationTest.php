<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Integration;

use Composer\InstalledVersions;
use Voku\PhpstanAgentFormat\Formatter\AgentErrorFormatter;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class PhpstanCliFixabilityIntegrationTest
{
    public static function run(): void
    {
        $root = dirname(__DIR__, 2);
        $config = $root . '/tests/Config/phpstan-agent-fixability.neon';

        [$phpstanJson, $jsonExit] = TestCase::runPhpstan($root, $config, 'json');
        [$agentJson, $agentExit] = TestCase::runPhpstan($root, $config, 'agent');

        TestCase::assertSame(1, $jsonExit, 'PHPStan native JSON must expose the useless-cast fixtures as errors.');
        TestCase::assertSame(1, $agentExit, 'Agent formatter must preserve the PHPStan exit code.');

        /** @var array{files: array<string, array{messages: list<array{message: string, identifier?: string, fixable?: bool}>}>} $native */
        $native = json_decode($phpstanJson, true, 512, JSON_THROW_ON_ERROR);
        /** @var array{clusters: list<array{representativeIssues: list<array{message: string, ruleIdentifier: ?string, fixable?: bool}>}>} $agent */
        $agent = json_decode($agentJson, true, 512, JSON_THROW_ON_ERROR);

        $reformatted = (new AgentErrorFormatter(['outputMode' => 'json', 'maxIssuesPerCluster' => 10]))
            ->formatPhpstanJsonExport($phpstanJson);
        /** @var array{clusters: list<array{representativeIssues: list<array{message: string, ruleIdentifier: ?string, fixable?: bool}>}>} $imported */
        $imported = json_decode($reformatted, true, 512, JSON_THROW_ON_ERROR);

        $nativeIssues = [];
        foreach ($native['files'] as $entry) {
            foreach ($entry['messages'] as $issue) {
                $nativeIssues[$issue['message']] = $issue;
            }
        }

        $agentIssues = self::byMessage($agent);
        $importedIssues = self::byMessage($imported);
        $integerCast = "Casting to int something that's already int.";
        $stringCast = "Casting to string something that's already string.";

        TestCase::assertSame(2, count($nativeIssues), 'Fixture must produce exactly two upstream cast issues.');
        TestCase::assertSame(2, count($agentIssues), 'CLI output must preserve both upstream cast issues.');
        TestCase::assertSame(2, count($importedIssues), 'Reformatted JSON output must preserve both upstream cast issues.');

        // PHPStan 2.1 does not expose Error::isFixable(); PHPStan 2.3.5 and strict-rules 2.1.1 do.
        $upstreamSupportsFixes = version_compare(InstalledVersions::getPrettyVersion('phpstan/phpstan') ?? '0.0.0', '2.3.5', '>=');
        TestCase::assertSame($upstreamSupportsFixes, ($nativeIssues[$integerCast]['fixable'] ?? null) === true, 'Native PHPStan output must provide the expected upstream fixability evidence.');

        foreach ([$agentIssues, $importedIssues] as $issues) {
            TestCase::assertSame($upstreamSupportsFixes, ($issues[$integerCast]['fixable'] ?? null) === true, 'Agent output must preserve proven fixability on the integer cast.');
            TestCase::assertTrue(!array_key_exists('fixable', $issues[$stringCast]), 'A string cast must not be marked automatically fixable.');
            TestCase::assertSame('cast.useless', $issues[$integerCast]['ruleIdentifier'], 'Fixable issue must retain the upstream identifier.');
        }
    }

    /**
     * @param array{clusters: list<array{representativeIssues: list<array{message: string, ruleIdentifier: ?string, fixable?: bool}>}>} $report
     * @return array<string, array{message: string, ruleIdentifier: ?string, fixable?: bool}>
     */
    private static function byMessage(array $report): array
    {
        $issues = [];
        foreach ($report['clusters'] as $cluster) {
            foreach ($cluster['representativeIssues'] as $issue) {
                $issues[$issue['message']] = $issue;
            }
        }

        return $issues;
    }
}
