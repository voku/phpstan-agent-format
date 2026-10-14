<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Integration;

use RuntimeException;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

/**
 * End-to-end contract of `agentFormat.itpContext` with the real PHPStan CLI and the real voku/itp-context.
 * Needs a checkout with installed dependencies in ITP_CONTEXT_DIR (CI sets ITP_CONTEXT_REQUIRED=1 so it cannot be skipped).
 */
final class PhpstanCliItpContextTest
{
    private const DATABASE = 'architecture[Voku\PhpstanAgentFormat\Tests\Fixture\ItpContext\Rules::Database]';
    private const MAIL = 'architecture[Voku\PhpstanAgentFormat\Tests\Fixture\ItpContext\Rules::Mail]';

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

            self::assertFullRunReportsEachRuleOnce($root, $dir);
            self::assertScopeFollowsAnalysedFiles($root, $dir);
            self::assertWarmCacheKeepsNoticesAndScope($root, $dir);
            self::assertNoticesNeverChangeExitCodeButErrorsDo($root, $dir);
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

        [$output, $exit] = self::analyse($root, $dir, ['Plain.php'], false, true, 'missing');

        TestCase::assertTrue($exit !== 0, 'itpContext without the package must not pass silently.');
        TestCase::assertTrue(str_contains($output, 'agentFormat.itpContext requires voku/itp-context.'), 'Error must name the missing package.');
    }

    private static function assertFullRunReportsEachRuleOnce(string $root, string $dir): void
    {
        [$output, $exit] = self::analyse($root, $dir, [''], true, true, 'full');
        $report = self::decode($output);

        TestCase::assertSame(0, $exit, 'Architecture notices alone must exit 0.');
        TestCase::assertSame([self::DATABASE, self::MAIL], array_column($report['notices'] ?? [], 'id'), 'Full run: Database and Mail, each exactly once.');
        TestCase::assertSame(0, $report['summary']['totalIssues'], 'Notices are not PHPStan issues.');
        TestCase::assertSame(2, $report['summary']['notices'], 'Summary counts the two rule notices.');

        $database = ($report['notices'] ?? [])[0];
        TestCase::assertSame('Use the database abstraction.', $database['title'], 'Title is the rule statement.');
        TestCase::assertSame(['docs/adr/database.md'], $database['refs'], 'Refs come from the rule definition.');
        TestCase::assertSame(
            "Applies to: Voku\\PhpstanAgentFormat\\Tests\\Fixture\\ItpContext\\Bar, Voku\\PhpstanAgentFormat\\Tests\\Fixture\\ItpContext\\Foo\nWhen changing: Read docs/adr/database.md first.",
            $database['message'],
            'Two annotated classes still produce one notice listing both.',
        );
    }

    private static function assertScopeFollowsAnalysedFiles(string $root, string $dir): void
    {
        TestCase::assertSame([self::DATABASE], self::noticeIds($root, $dir, ['Foo.php']), 'Analysing Foo.php reports only its rule.');
        TestCase::assertSame([self::MAIL], self::noticeIds($root, $dir, ['Mailer.php']), 'Analysing Mailer.php reports only its rule.');
        TestCase::assertSame([self::DATABASE], self::noticeIds($root, $dir, ['Foo.php', 'Bar.php']), 'Two files with the same rule give one notice.');

        [$plain] = self::analyse($root, $dir, ['Plain.php'], true, true, 'scope');
        TestCase::assertTrue(!str_contains($plain, 'architecture['), 'A file without rules gets no architecture notice.');
        TestCase::assertTrue(!isset(self::decode($plain)['notices']), 'No notices section at all for an unannotated file.');
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private static function noticeIds(string $root, string $dir, array $paths): array
    {
        return array_column(self::decode(self::analyse($root, $dir, $paths, true, true, 'scope')[0])['notices'] ?? [], 'id');
    }

    private static function assertWarmCacheKeepsNoticesAndScope(string $root, string $dir): void
    {
        [$cold] = self::analyse($root, $dir, [''], true, true, 'warm');
        [$warm] = self::analyse($root, $dir, [''], true, true, 'warm');
        TestCase::assertSame($cold, $warm, 'A warm PHPStan result cache must yield identical architecture notices.');

        [$subset] = self::analyse($root, $dir, ['Mailer.php'], true, true, 'warm');
        TestCase::assertSame([self::MAIL], array_column(self::decode($subset)['notices'] ?? [], 'id'), 'A narrower run on a populated cache is scoped to its own files.');
    }

    private static function assertNoticesNeverChangeExitCodeButErrorsDo(string $root, string $dir): void
    {
        [$output, $exit] = self::analyse($root, $dir, ['Foo.php', '../MethodOnString.php'], true, true, 'exit');
        $report = self::decode($output);

        TestCase::assertSame(1, $exit, 'A real PHPStan error plus a notice must still exit 1.');
        TestCase::assertTrue($report['summary']['totalIssues'] >= 1, 'The real error is reported.');
        TestCase::assertSame([self::DATABASE], array_column($report['notices'] ?? [], 'id'), 'The notice is reported next to the error.');
    }

    private static function assertDisabledIntegrationAddsNothing(string $root, string $dir): void
    {
        [$omitted, $omittedExit] = self::analyse($root, $dir, ['Foo.php', '../MethodOnString.php'], true, null, 'off');
        [$explicit] = self::analyse($root, $dir, ['Foo.php', '../MethodOnString.php'], true, false, 'off');

        TestCase::assertSame($omitted, $explicit, 'itpContext: false must be byte-identical to omitting the option.');
        TestCase::assertTrue(!str_contains($omitted, 'architecture[') && !str_contains($omitted, 'notices'), 'Without the integration the report has no notices.');
        TestCase::assertSame(1, $omittedExit, 'The real error still fails.');
    }

    /**
     * Runs plain `phpstan analyse` (no --error-format): the format comes from `parameters.errorFormat`.
     *
     * @param list<string> $paths relative to tests/Fixture/ItpContext, '' meaning the whole directory
     * @return array{0: string, 1: int}
     */
    private static function analyse(string $root, string $dir, array $paths, bool $withBootstrap, ?bool $itpContext, string $cache): array
    {
        $fixtures = $root . '/tests/Fixture/ItpContext';
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
            $lines[] = '        - ' . rtrim($fixtures . '/' . $path, '/');
        }
        if ($withBootstrap) {
            $lines[] = '    bootstrapFiles:';
            $lines[] = '        - ' . $fixtures . '/bootstrap.php';
        }
        $lines[] = '    agentFormat:';
        $lines[] = '        outputMode: json';
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
     *   summary: array{totalIssues: int, notices: int},
     *   notices?: list<array{id: string, title: string, message: string, subject: string|null, refs: list<string>}>
     * }
     */
    private static function decode(string $output): array
    {
        /** @var array{summary: array{totalIssues: int, notices: int}, notices?: list<array{id: string, title: string, message: string, subject: string|null, refs: list<string>}>} $decoded */
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
