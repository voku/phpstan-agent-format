<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Integration;

use HelgeSverre\Toon\Toon;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class PhpstanCliNoticesIntegrationTest
{
    public static function run(): void
    {
        $root = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/phpstan-agent-notices-' . sha1((string) microtime(true));
        mkdir($dir);

        try {
            self::assertDisabledNoticesKeepOutputIdentical($root, $dir);
            self::assertNoticeWithoutErrorsKeepsExitCodeZero($root, $dir);
            self::assertNoticeWithErrorKeepsErrorsAndExitCode($root, $dir);
            self::assertWarmCacheStillDeliversChangedNotices($root, $dir);
            self::assertNoticesAreRedactedAndBudgeted($root, $dir);
            self::assertInvalidNoticesFileFailsLoudly($root, $dir);
        } finally {
            self::removeDir($dir);
        }
    }

    private static function assertDisabledNoticesKeepOutputIdentical(string $root, string $dir): void
    {
        [$without, $withoutExit] = self::analyse($root, $dir, 'Fixture/MethodOnString.php', 'json', null);
        [$explicitNull, $explicitNullExit] = self::analyse($root, $dir, 'Fixture/MethodOnString.php', 'json', null, 'noticesFile: null');

        TestCase::assertSame(1, $withoutExit, 'Error fixture should fail.');
        TestCase::assertSame($without, $explicitNull, 'noticesFile: null must be byte-identical to omitting it.');
        TestCase::assertSame(1, $explicitNullExit, 'Exit code must not change.');
        TestCase::assertTrue(!str_contains($without, 'notices'), 'Without notices the report must not mention notices.');
    }

    private static function assertNoticeWithoutErrorsKeepsExitCodeZero(string $root, string $dir): void
    {
        $noticesFile = self::writeNotices($dir, [self::notice('n1', 'Guidance one')]);

        foreach (['json', 'toon'] as $mode) {
            [$output, $exitCode] = self::analyse($root, $dir, 'Fixture/CleanRun.php', $mode, $noticesFile);
            TestCase::assertSame(0, $exitCode, 'Notices must not fail a clean run (' . $mode . ').');

            $decoded = self::decode($output, $mode);
            TestCase::assertSame(0, $decoded['summary']['totalIssues'], 'Notices must not count as issues.');
            TestCase::assertSame(1, $decoded['summary']['notices'], 'Summary should count notices separately.');
            TestCase::assertSame('Guidance one', $decoded['notices'][0]['message'], 'Notice must be visible (' . $mode . ').');
            TestCase::assertSame(\Voku\PhpstanAgentFormat\Dto\PresentationResult::NOTICES_ADVISORY, $decoded['noticesAdvisory'], 'Advisory marker expected.');
        }
    }

    private static function assertNoticeWithErrorKeepsErrorsAndExitCode(string $root, string $dir): void
    {
        $noticesFile = self::writeNotices($dir, [self::notice('n1', 'Guidance one')]);

        [$plain, $plainExit] = self::analyse($root, $dir, 'Fixture/MethodOnString.php', 'json', null);
        [$withNotice, $noticeExit] = self::analyse($root, $dir, 'Fixture/MethodOnString.php', 'json', $noticesFile);

        TestCase::assertSame($plainExit, $noticeExit, 'Exit code must be unchanged by notices.');
        TestCase::assertTrue($noticeExit !== 0, 'Real errors must still fail.');

        $a = self::decode($plain, 'json');
        $b = self::decode($withNotice, 'json');
        TestCase::assertSame($a['summary']['totalIssues'], $b['summary']['totalIssues'], 'Error count must be unchanged.');
        TestCase::assertSame($a['clusters'], $b['clusters'], 'Clusters must be unchanged.');
        TestCase::assertSame(1, count($b['notices']), 'Notice must be visible next to errors.');
    }

    private static function assertWarmCacheStillDeliversChangedNotices(string $root, string $dir): void
    {
        $first = self::writeNotices($dir, [self::notice('n1', 'First guidance')]);
        self::analyse($root, $dir, 'Fixture/CleanRun.php', 'json', $first);
        self::analyse($root, $dir, 'Fixture/CleanRun.php', 'json', $first);

        $second = self::writeNotices($dir, [self::notice('n2', 'Second guidance')]);
        [$output] = self::analyse($root, $dir, 'Fixture/CleanRun.php', 'json', $second);
        $decoded = self::decode($output, 'json');

        TestCase::assertSame('Second guidance', $decoded['notices'][0]['message'], 'A warm PHPStan result cache must not hide notices.');
    }

    private static function assertNoticesAreRedactedAndBudgeted(string $root, string $dir): void
    {
        $noticesFile = self::writeNotices($dir, [self::notice('n1', 'Use token=abc123 carefully, ' . str_repeat('long ', 200))]);

        [$output] = self::analyse($root, $dir, 'Fixture/CleanRun.php', 'json', $noticesFile, "redactPatterns:\n            - 'token=\\w+'\n        tokenBudget: 50");
        $decoded = self::decode($output, 'json');

        TestCase::assertTrue(!str_contains($output, 'abc123'), 'Notice text must be redacted.');
        TestCase::assertTrue($decoded['summary']['tokenStats']['wasReduced'], 'Notices must take part in token-budget reduction.');
        TestCase::assertSame('', $decoded['notices'][0]['message'], 'Over budget, notice message is dropped last.');
        TestCase::assertSame('Title n1', $decoded['notices'][0]['title'], 'Notice title survives reduction.');
    }

    private static function assertInvalidNoticesFileFailsLoudly(string $root, string $dir): void
    {
        $file = $dir . '/broken.json';
        file_put_contents($file, '{"notices": [{"id": "x"}]}');

        [$output, $exitCode] = self::analyse($root, $dir, 'Fixture/CleanRun.php', 'json', $file);

        TestCase::assertTrue($exitCode !== 0, 'An invalid notices file must not be silently ignored.');
        TestCase::assertTrue(str_contains($output, 'needs a non-empty string'), 'Error should explain the invalid notice.');
    }

    /**
     * @return array{id:string,title:string,message:string,subject:string,refs:list<string>}
     */
    private static function notice(string $id, string $message): array
    {
        return ['id' => $id, 'title' => 'Title ' . $id, 'message' => $message, 'subject' => 'Foo::bar', 'refs' => ['docs/adr/x.md']];
    }

    /**
     * @param list<array<string,mixed>> $notices
     */
    private static function writeNotices(string $dir, array $notices): string
    {
        $file = $dir . '/notices-' . sha1(serialize($notices)) . '.json';
        file_put_contents($file, json_encode(['notices' => $notices], JSON_THROW_ON_ERROR));

        return $file;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function analyse(string $root, string $dir, string $fixture, string $mode, ?string $noticesFile, string $extra = ''): array
    {
        $lines = [
            'includes:',
            '    - ' . $root . '/extension.neon',
            'parameters:',
            '    level: max',
            '    tmpDir: ' . $dir . '/cache',
            '    paths:',
            '        - ' . $root . '/tests/' . $fixture,
            '    agentFormat:',
            '        outputMode: ' . $mode,
        ];
        if ($noticesFile !== null) {
            $lines[] = '        noticesFile: ' . $noticesFile;
        }
        if ($extra !== '') {
            $lines[] = '        ' . $extra;
        }

        $config = $dir . '/phpstan-' . sha1(implode("\n", $lines)) . '.neon';
        file_put_contents($config, implode("\n", $lines) . "\n");

        return TestCase::runPhpstan($root, $config, 'agent');
    }

    /**
     * @return array{
     *   summary: array{totalIssues: int, notices: int, tokenStats: array{wasReduced: bool}},
     *   clusters: list<array<mixed>>,
     *   noticesAdvisory: string,
     *   notices: list<array{id: string, title: string, message: string, subject: string|null, refs: list<string>}>
     * }
     */
    private static function decode(string $output, string $mode): array
    {
        /** @var array{summary: array{totalIssues: int, notices: int, tokenStats: array{wasReduced: bool}}, clusters: list<array<mixed>>, noticesAdvisory: string, notices: list<array{id: string, title: string, message: string, subject: string|null, refs: list<string>}>} $decoded */
        $decoded = $mode === 'toon' ? Toon::decode($output) : json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private static function removeDir(string $dir): void
    {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
