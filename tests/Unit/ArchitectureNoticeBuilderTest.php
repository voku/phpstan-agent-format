<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use ItpContext\Model\ApplicableRule;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\Output;
use Voku\PhpstanAgentFormat\Formatter\AgentErrorFormatter;
use Voku\PhpstanAgentFormat\ItpContext\ArchitectureNoticeBuilder;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class ArchitectureNoticeBuilderTest
{
    public static function run(): void
    {
        self::assertRulesBecomeOneNoticeEach();
        self::assertSubjectsAreTruncated();
        self::assertOnlyFilesWithIssuesAreResolved();
        self::assertFormatterOnlyAddsNoticesWhenEnabledAndIssuesExist();
        self::assertBrokenArchitectureContextNeverHidesRealIssues();
    }

    private static function assertRulesBecomeOneNoticeEach(): void
    {
        $GLOBALS['ITP_CONTEXT_STUB_RULES'] = [
            '/p/Foo.php' => [new ApplicableRule('R::Database', 'Use the database abstraction.', 'Read the ADR first.', ['docs/adr/database.md'], ['App\\Foo'])],
            '/p/Mailer.php' => [new ApplicableRule('R::Mail', 'Use the mailer.', null, [], ['App\\Mailer::send'])],
        ];

        $notices = (new ArchitectureNoticeBuilder())->fromAnalysisResult(self::result(['/p/Mailer.php', '/p/Foo.php', '/p/Plain.php', '/p/Foo.php']));

        TestCase::assertSame(['architecture[R::Database]', 'architecture[R::Mail]'], array_map(static fn ($n): string => $n->id, $notices), 'One notice per rule, ordered by rule id.');
        TestCase::assertSame('Use the database abstraction.', $notices[0]->title, 'Title is the rule statement.');
        TestCase::assertSame(null, $notices[0]->subject, 'The rule id lives in the notice id only.');
        TestCase::assertSame("Applies to: App\\Foo\nWhen changing: Read the ADR first.", $notices[0]->message, 'Message lists subjects and change guidance.');
        TestCase::assertSame(['docs/adr/database.md'], $notices[0]->refs, 'Refs are preserved.');
        TestCase::assertSame('Applies to: App\\Mailer::send', $notices[1]->message, 'Guidance is omitted when the rule has none.');
    }

    private static function assertSubjectsAreTruncated(): void
    {
        $subjects = array_map(static fn (int $i): string => 'App\\C' . $i, range(1, 12));
        $GLOBALS['ITP_CONTEXT_STUB_RULES'] = ['/p/A.php' => [new ApplicableRule('R::Database', 'S', null, [], $subjects)]];

        $notices = (new ArchitectureNoticeBuilder())->fromAnalysisResult(self::result(['/p/A.php']));

        TestCase::assertSame('Applies to: App\\C1, App\\C2, App\\C3, +9 more', $notices[0]->message, 'Long subject lists are cut with a remainder count.');
    }

    private static function assertOnlyFilesWithIssuesAreResolved(): void
    {
        $GLOBALS['ITP_CONTEXT_STUB_RULES'] = [
            '/p/Foo.php' => [new ApplicableRule('R::Database', 'S', null, [], ['App\\Foo'])],
            '/p/Other.php' => [new ApplicableRule('R::Other', 'S', null, [], ['App\\Other'])],
            '/p/Trait.php' => [new ApplicableRule('R::Trait', 'S', null, [], ['App\\T'])],
        ];

        $builder = new ArchitectureNoticeBuilder();
        $ids = static fn (AnalysisResult $result): array => array_map(static fn ($n): string => $n->id, $builder->fromAnalysisResult($result));

        TestCase::assertSame(['architecture[R::Database]'], $ids(self::result(['/p/Foo.php'])), 'Only files with issues contribute rules.');
        TestCase::assertSame([], $ids(self::result([])), 'No file-specific issues, no notices.');
        TestCase::assertSame([], $ids(new AnalysisResult([], ['a global PHPStan error'])), 'File-independent errors carry no architecture context.');
        TestCase::assertSame(
            ['architecture[R::Database]', 'architecture[R::Trait]'],
            $ids(new AnalysisResult([new FakeError('/p/Foo.php', '/p/Trait.php')], [])),
            'An error reported in a trait resolves the rules of both files.',
        );
        TestCase::assertSame(
            ['architecture[R::Database]', 'architecture[R::Trait]'],
            $ids(new AnalysisResult([new FakeError('Trait display label', '/p/Foo.php', '/p/Trait.php')], [])),
            'The trait source path must contribute rules even when getFile() is only a display label.',
        );
    }

    private static function assertFormatterOnlyAddsNoticesWhenEnabledAndIssuesExist(): void
    {
        $GLOBALS['ITP_CONTEXT_STUB_RULES'] = ['/p/Foo.php' => [new ApplicableRule('R::Database', 'S', null, [], ['App\\Foo'])]];
        $withIssue = self::result(['/p/Foo.php']);

        $render = static function (AnalysisResult $result, array $agentFormat): array {
            $output = new Output();
            $exit = (new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'json'] + $agentFormat]))->formatErrors($result, $output);

            return [$output->buffer, $exit];
        };

        [$disabled, $disabledExit] = $render($withIssue, []);
        [$explicit] = $render($withIssue, ['itpContext' => false]);
        [$enabled, $enabledExit] = $render($withIssue, ['itpContext' => true]);

        TestCase::assertSame($disabled, $explicit, 'itpContext: false must equal omitting the option.');
        TestCase::assertTrue(!str_contains($disabled, 'notice'), 'Disabled integration must not mention notices.');
        TestCase::assertTrue(str_contains($enabled, 'architecture[R::Database]'), 'Enabled integration renders the rule notice.');
        TestCase::assertSame($disabledExit, $enabledExit, 'Architecture notices never change the exit code.');
        TestCase::assertSame(1, $enabledExit, 'The real error still fails.');

        [$green, $greenExit] = $render(new AnalysisResult([], []), ['itpContext' => true]);
        TestCase::assertSame(0, $greenExit, 'A green run stays green.');
        TestCase::assertTrue(!str_contains($green, 'architecture['), 'A green run gets no architecture notices.');

        [$global, $globalExit] = $render(new AnalysisResult([], ['a global PHPStan error']), ['itpContext' => true]);
        TestCase::assertSame(1, $globalExit, 'A file-independent error still fails.');
        TestCase::assertTrue(!str_contains($global, 'architecture['), 'A file-independent error gets no architecture notices.');

        unset($GLOBALS['ITP_CONTEXT_STUB_RULES']);
    }

    private static function assertBrokenArchitectureContextNeverHidesRealIssues(): void
    {
        $withIssue = self::result(['/p/Foo.php']);
        $render = static function (array $agentFormat) use ($withIssue): array {
            $output = new Output();
            $exit = (new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'json', 'itpContext' => true] + $agentFormat]))->formatErrors($withIssue, $output);

            return [$output->buffer, $exit];
        };

        // An unresolvable rule: the report must still contain the real issue and the exit code must be unchanged.
        $GLOBALS['ITP_CONTEXT_STUB_FAILURE'] = new \RuntimeException('Rule "Gone::Rule" cannot be resolved.');
        [$output, $exit] = $render([]);
        $decoded = self::decode($output);
        TestCase::assertSame(1, $exit, 'A failing rule resolver does not change the exit code.');
        TestCase::assertSame(1, $decoded['summary']['totalIssues'], 'The real issue is still reported.');
        TestCase::assertSame(['architecture[unavailable]'], array_column($decoded['notices'], 'id'), 'The failure becomes one advisory notice.');
        TestCase::assertSame('Rule "Gone::Rule" cannot be resolved.', $decoded['notices'][0]['message'], 'The notice says what went wrong.');

        // The failure text is redacted like any other notice text.
        [$redacted] = $render(['redactPatterns' => ['Gone::\\w+']]);
        TestCase::assertTrue(!str_contains($redacted, 'Gone::Rule'), 'The failure notice is redacted.');
        unset($GLOBALS['ITP_CONTEXT_STUB_FAILURE']);

        // A rule id matching redactPatterns is never rewritten: it is dropped and counted instead of aborting the report.
        $GLOBALS['ITP_CONTEXT_STUB_RULES'] = [
            '/p/Foo.php' => [
                new ApplicableRule('Secret\\Rules::Alpha', 'S', null, [], ['App\\Foo']),
                new ApplicableRule('Plain\\Rules::Beta', 'S', null, [], ['App\\Foo']),
            ],
        ];
        [$partial, $partialExit] = $render(['redactPatterns' => ['Secret\\\\Rules::\\w+']]);
        $decoded = self::decode($partial);
        TestCase::assertSame(1, $partialExit, 'A sensitive rule id does not change the exit code.');
        TestCase::assertSame(1, $decoded['summary']['totalIssues'], 'The real issue is still reported.');
        TestCase::assertSame(['architecture[Plain\\Rules::Beta]', 'architecture[omitted]'], array_column($decoded['notices'], 'id'), 'Safe notices stay, the sensitive one is replaced by an omitted marker.');
        TestCase::assertTrue(!str_contains($partial, 'Secret'), 'The sensitive id never reaches the report.');
        unset($GLOBALS['ITP_CONTEXT_STUB_RULES']);
    }

    /**
     * @return array{summary: array{totalIssues: int}, notices: list<array{id: string, message: string}>}
     */
    private static function decode(string $json): array
    {
        /** @var array{summary: array{totalIssues: int}, notices: list<array{id: string, message: string}>} $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param list<string> $filesWithIssues
     */
    private static function result(array $filesWithIssues): AnalysisResult
    {
        return new AnalysisResult(array_map(static fn (string $file): FakeError => new FakeError($file, $file), $filesWithIssues), []);
    }
}

/**
 * Minimal stand-in for PHPStan\Analyser\Error: `file` is the analysed file, `filePath` the file the error sits in.
 */
final class FakeError
{
    public function __construct(
        private string $file,
        private string $filePath,
        private ?string $traitFilePath = null,
    ) {
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getTraitFilePath(): ?string
    {
        return $this->traitFilePath;
    }

    public function getMessage(): string
    {
        return 'Fake PHPStan error.';
    }

    public function getLine(): int
    {
        return 1;
    }
}
