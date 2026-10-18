<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Integration;

use RuntimeException;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

/**
 * End-to-end contract of `agentFormat.itpContext` with the real PHPStan CLI and the real voku/itp-context:
 * architecture notices belong to the files that carry file-specific PHPStan issues.
 * Needs a checkout with installed dependencies in ITP_CONTEXT_DIR (CI sets ITP_CONTEXT_REQUIRED=1 so it cannot be skipped).
 */
final class PhpstanCliItpContextTest
{
    private const NS = 'Voku\PhpstanAgentFormat\Tests\Fixture\ItpContext';
    private const DATABASE = 'architecture[' . self::NS . '\Rules::Database]';
    private const MAIL = 'architecture[' . self::NS . '\Rules::Mail]';

    /** Files without any issue: the whole annotated fixture project. */
    private const GREEN = ['ItpContext'];
    /** Error in an unannotated file. */
    private const PLAIN_ERROR = 'MethodOnString.php';

    public static function run(): void
    {
        $root = dirname(__DIR__, 2);
        $itpContext = getenv('ITP_CONTEXT_DIR');
        $installed = is_string($itpContext) && is_file($itpContext . '/vendor/autoload.php');

        if (!$installed && getenv('ITP_CONTEXT_REQUIRED') === '1') {
            throw new RuntimeException('ITP_CONTEXT_REQUIRED=1 but ITP_CONTEXT_DIR has no installed voku/itp-context.');
        }

        $dir = sys_get_temp_dir() . '/phpstan-agent-itp-' . sha1((string) microtime(true));
        mkdir($dir);

        try {
            self::assertMissingPackageFailsLoudly($root, $dir);

            if (!$installed) {
                echo "[SKIP] itp-context E2E: set ITP_CONTEXT_DIR to a voku/itp-context checkout.\n";

                return;
            }

            self::assertNoIssuesMeansNoNotices($root, $dir);
            self::assertIssuesInUnannotatedFilesMeanNoNotices($root, $dir);
            self::assertIssuesSelectTheirRules($root, $dir);
            self::assertTraitIssuesSelectTraitRule($root, $dir);
            self::assertBrokenArchitectureContextNeverHidesRealIssues($root, $dir);
            self::assertFileIndependentErrorsCarryNoNotices($root, $dir);
            self::assertWarmCacheIsIdentical($root, $dir);
            self::assertNoticesNeverChangeExitCode($root, $dir);
            self::assertDisabledIntegrationAddsNothing($root, $dir);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    private static function assertMissingPackageFailsLoudly(string $root, string $dir): void
    {
        if (is_dir($root . '/vendor/voku/itp-context')) {
            return; // package is a dev dependency here, the failure mode cannot be reproduced
        }

        // Green run on purpose: a misconfiguration must not hide until the first issue.
        [$output, $exit] = self::analyse($root, $dir, ['ItpContext/Plain.php'], false, true, 'missing');

        TestCase::assertTrue($exit !== 0, 'itpContext without the package must not pass silently.');
        TestCase::assertTrue(str_contains($output, 'agentFormat.itpContext requires voku/itp-context.'), 'Error must name the missing package.');
    }

    private static function assertNoIssuesMeansNoNotices(string $root, string $dir): void
    {
        [$output, $exit] = self::analyse($root, $dir, self::GREEN, true, true, 'green');

        TestCase::assertSame(0, $exit, 'A green run stays green.');
        TestCase::assertTrue(!str_contains($output, 'architecture[') && !isset(self::decode($output)['notices']), 'A run without issues gets no architecture notices, however many rules the project has.');
    }

    private static function assertIssuesInUnannotatedFilesMeanNoNotices(string $root, string $dir): void
    {
        [$output, $exit] = self::analyse($root, $dir, [...self::GREEN, self::PLAIN_ERROR, 'ItpContextBroken/PlainBroken.php'], true, true, 'plain');

        TestCase::assertSame(1, $exit, 'The real errors fail the run.');
        TestCase::assertTrue(self::decode($output)['summary']['totalIssues'] >= 1, 'The real errors are reported.');
        TestCase::assertSame([], self::ids($output), 'Errors outside annotated files must not pull in the project\'s rules.');
    }

    private static function assertIssuesSelectTheirRules(string $root, string $dir): void
    {
        [$one, $oneExit] = self::withIssuesIn($root, $dir, ['ItpContextBroken/DbBroken.php']);
        TestCase::assertSame(1, $oneExit, 'The issue decides the exit code.');
        TestCase::assertSame([self::DATABASE], self::ids($one), 'An issue in a Database file selects only the Database rule, not Mail.');
        $notice = self::decode($one)['notices'][0] ?? [];
        TestCase::assertSame('Use the database abstraction.', $notice['title'] ?? null, 'Title is the rule statement.');
        TestCase::assertSame(['docs/adr/database.md'], $notice['refs'] ?? null, 'Refs come from the rule definition.');
        TestCase::assertTrue(!array_key_exists('subject', $notice), 'The rule id lives in the notice id only.');
        TestCase::assertSame(
            "Applies to: Voku\\PhpstanAgentFormat\\Tests\\Fixture\\ItpContextBroken\\DbBroken\nWhen changing: Read docs/adr/database.md first.",
            $notice['message'] ?? null,
            'Only the annotated symbols of files with issues are listed, not every Database class of the project.',
        );

        [$sameRule] = self::withIssuesIn($root, $dir, ['ItpContextBroken/DbBroken.php', 'ItpContextBroken/DbBroken2.php']);
        TestCase::assertSame([self::DATABASE], self::ids($sameRule), 'Issues in two files with the same rule give one notice.');
        TestCase::assertTrue(
            str_contains(self::decode($sameRule)['notices'][0]['message'] ?? '', 'DbBroken, ') || str_contains(self::decode($sameRule)['notices'][0]['message'] ?? '', 'DbBroken2'),
            'The single notice lists both files\' symbols.',
        );

        [$twoRules] = self::withIssuesIn($root, $dir, ['ItpContextBroken/DbBroken.php', 'ItpContextBroken/MailBroken.php']);
        TestCase::assertSame([self::DATABASE, self::MAIL], self::ids($twoRules), 'Issues in files with two different rules give two notices.');

        [$other] = self::withIssuesIn($root, $dir, ['ItpContextBroken/MailBroken.php']);
        TestCase::assertSame([self::MAIL], self::ids($other), 'An issue in a Mail file selects only the Mail rule.');
    }

    private static function assertTraitIssuesSelectTraitRule(string $root, string $dir): void
    {
        // The diagnostic arises inside an annotated trait used by a class without Rule attributes.
        // This exercises the real PHPStan Error path contract rather than the unit-test FakeError.
        [$output, $exit] = self::analyse(
            $root,
            $dir,
            [...self::GREEN, 'ItpContextBroken/AnnotatedBrokenTrait.php', 'ItpContextBroken/TraitConsumer.php'],
            true,
            true,
            'trait',
        );

        TestCase::assertSame(1, $exit, 'The error inside the used trait must fail PHPStan.');
        TestCase::assertSame([self::MAIL], self::ids($output), 'An error inside an annotated trait must expose the trait rule.');
        $message = self::decode($output)['notices'][0]['message'] ?? '';
        TestCase::assertTrue(str_contains($message, 'AnnotatedBrokenTrait'), 'The notice must name the annotated trait, not invent a rule on its unannotated consumer.');
    }

    /**
     * The annotated green project plus the given broken fixtures.
     *
     * @param list<string> $broken
     * @return array{0: string, 1: int}
     */
    private static function withIssuesIn(string $root, string $dir, array $broken): array
    {
        return self::analyse($root, $dir, [...self::GREEN, ...$broken], true, true, 'select');
    }

    private static function assertBrokenArchitectureContextNeverHidesRealIssues(string $root, string $dir): void
    {
        // A rule whose enum does not exist cannot be resolved. The report must survive and still carry the real issues.
        [$output, $exit] = self::analyse($root, $dir, [...self::GREEN, 'ItpContextBroken/UnresolvableRule.php', 'ItpContextBroken/DbBroken.php'], true, true, 'unresolvable');
        $report = self::decode($output);

        TestCase::assertSame(1, $exit, 'The real PHPStan errors decide the exit code.');
        TestCase::assertTrue($report['summary']['totalIssues'] >= 2, 'The real issues are reported even though a rule cannot be resolved.');
        TestCase::assertSame(['architecture[unavailable]'], self::ids($output), 'The unresolvable rule becomes one advisory notice instead of aborting the report.');
        TestCase::assertTrue(str_contains($report['notices'][0]['message'] ?? '', 'NoSuchRules::Database'), 'The notice names the rule that could not be resolved.');

        // A rule id that matches redactPatterns is dropped and counted, not rewritten and not fatal.
        [$redacted, $redactedExit] = self::analyse($root, $dir, [...self::GREEN, 'ItpContextBroken/DbBroken.php', 'ItpContextBroken/MailBroken.php'], true, true, 'sensitive', '', "redactPatterns:\n            - 'Rules.{2}Database'");
        TestCase::assertSame(1, $redactedExit, 'A sensitive rule id does not change the exit code.');
        TestCase::assertSame([self::MAIL, 'architecture[omitted]'], self::ids($redacted), 'The safe notice stays and the sensitive one is replaced by an omitted marker.');
        TestCase::assertTrue(!str_contains($redacted, 'Rules::Database'), 'The sensitive rule id never reaches the report.');
    }

    private static function assertFileIndependentErrorsCarryNoNotices(string $root, string $dir): void
    {
        // An unmatched ignoreErrors pattern is reported by PHPStan as an error without a file (full analysis only).
        [$output, $exit] = self::analyse($root, $dir, self::GREEN, true, true, 'global', "    ignoreErrors:\n        - '#^this pattern never matches$#'");

        TestCase::assertSame(1, $exit, 'The file-independent PHPStan error fails the run.');
        TestCase::assertTrue(self::decode($output)['summary']['totalIssues'] >= 1, 'The file-independent error is reported.');
        TestCase::assertSame([], self::ids($output), 'A file-independent error has no file whose rules could apply.');
    }

    private static function assertWarmCacheIsIdentical(string $root, string $dir): void
    {
        $paths = [...self::GREEN, 'ItpContextBroken/DbBroken.php', 'ItpContextBroken/MailBroken.php'];
        [$cold] = self::analyse($root, $dir, $paths, true, true, 'warm');
        [$warm] = self::analyse($root, $dir, $paths, true, true, 'warm');

        TestCase::assertSame($cold, $warm, 'A warm PHPStan result cache must yield identical architecture notices.');
        TestCase::assertSame([self::DATABASE, self::MAIL], self::ids($warm), 'The warm run still carries both rules.');

        [$narrower] = self::analyse($root, $dir, [...self::GREEN, 'ItpContextBroken/MailBroken.php'], true, true, 'warm');
        TestCase::assertSame([self::MAIL], self::ids($narrower), 'A narrower run on a populated cache is scoped to its own issues.');
    }

    private static function assertNoticesNeverChangeExitCode(string $root, string $dir): void
    {
        $paths = [...self::GREEN, 'ItpContextBroken/DbBroken.php'];
        [, $exitOn] = self::analyse($root, $dir, $paths, true, true, 'exit');
        [, $exitOff] = self::analyse($root, $dir, $paths, true, false, 'exit');

        TestCase::assertSame(1, $exitOn, 'A real PHPStan error plus a notice must exit 1.');
        TestCase::assertSame($exitOff, $exitOn, 'The integration does not change the exit code.');
    }

    private static function assertDisabledIntegrationAddsNothing(string $root, string $dir): void
    {
        $paths = [...self::GREEN, 'ItpContextBroken/DbBroken.php'];
        [$omitted, $omittedExit] = self::analyse($root, $dir, $paths, true, null, 'off');
        [$explicit] = self::analyse($root, $dir, $paths, true, false, 'off');

        TestCase::assertSame($omitted, $explicit, 'itpContext: false must be byte-identical to omitting the option.');
        TestCase::assertTrue(!str_contains($omitted, 'architecture[') && !str_contains($omitted, 'notices'), 'Without the integration the report has no notices.');
        TestCase::assertSame(1, $omittedExit, 'The real error still fails.');
    }

    /**
     * @return list<string>
     */
    private static function ids(string $output): array
    {
        return array_column(self::decode($output)['notices'] ?? [], 'id');
    }

    /**
     * Runs plain `phpstan analyse` (no --error-format): the format comes from `parameters.errorFormat`.
     *
     * @param list<string> $paths relative to tests/Fixture
     * @return array{0: string, 1: int}
     */
    private static function analyse(string $root, string $dir, array $paths, bool $withBootstrap, ?bool $itpContext, string $cache, string $extraParameters = '', string $agentFormatExtra = ''): array
    {
        $fixtures = $root . '/tests/Fixture';
        $lines = [
            'includes:',
            '    - ' . $root . '/extension.neon',
            'parameters:',
            '    level: max',
            '    errorFormat: agent',
            '    tmpDir: ' . $dir . '/cache-' . $cache,
            '    paths:',
        ];
        foreach ($paths as $path) {
            $lines[] = '        - ' . $fixtures . '/' . $path;
        }
        if ($withBootstrap) {
            $lines[] = '    bootstrapFiles:';
            $lines[] = '        - ' . $fixtures . '/ItpContext/bootstrap.php';
        }
        if ($extraParameters !== '') {
            $lines[] = $extraParameters;
        }
        $lines[] = '    agentFormat:';
        $lines[] = '        outputMode: json';
        if ($agentFormatExtra !== '') {
            $lines[] = '        ' . $agentFormatExtra;
        }
        if ($itpContext !== null) {
            $lines[] = '        itpContext: ' . ($itpContext ? 'true' : 'false');
        }

        $config = $dir . '/phpstan-' . sha1(implode("\n", $lines)) . '.neon';
        file_put_contents($config, implode("\n", $lines) . "\n");

        $output = [];
        $exitCode = 0;
        exec(sprintf(
            '%s analyse --configuration %s --no-progress 2>&1',
            TestCase::phpstanCommand($root),
            escapeshellarg($config),
        ), $output, $exitCode);

        return [implode("\n", $output), $exitCode];
    }

    /**
     * @return array{
     *   summary: array{totalIssues: int, notices?: int},
     *   notices?: list<array{id: string, title: string, message: string, subject?: string, refs: list<string>}>
     * }
     */
    private static function decode(string $output): array
    {
        /** @var array{summary: array{totalIssues: int, notices?: int}, notices?: list<array{id: string, title: string, message: string, subject?: string, refs: list<string>}>} $decoded */
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
