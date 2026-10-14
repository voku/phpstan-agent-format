<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use ItpContext\Model\ApplicableRule;
use PHPStan\Command\AnalysisResult;
use PHPStan\Collectors\CollectedData;
use Voku\PhpstanAgentFormat\Collector\AnalysedFileCollector;
use Voku\PhpstanAgentFormat\Formatter\AgentErrorFormatter;
use Voku\PhpstanAgentFormat\ItpContext\ArchitectureNoticeBuilder;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class ArchitectureNoticeBuilderTest
{
    public static function run(): void
    {
        self::assertRulesBecomeOneNoticeEach();
        self::assertSubjectsAreTruncated();
        self::assertOnlyAnalysedFileDataIsUsed();
        self::assertFormatterOnlyAddsNoticesWhenEnabled();
    }

    private static function assertRulesBecomeOneNoticeEach(): void
    {
        $GLOBALS['ITP_CONTEXT_STUB_RULES'] = [
            '/p/Foo.php' => [new ApplicableRule('R::Database', 'Use the database abstraction.', 'Read the ADR first.', ['docs/adr/database.md'], ['App\\Foo'])],
            '/p/Mailer.php' => [new ApplicableRule('R::Mail', 'Use the mailer.', null, [], ['App\\Mailer::send'])],
        ];

        $notices = (new ArchitectureNoticeBuilder())->fromAnalysisResult(self::result(['/p/Mailer.php', '/p/Foo.php', '/p/Plain.php']));

        TestCase::assertSame(['architecture[R::Database]', 'architecture[R::Mail]'], array_map(static fn ($n): string => $n->id, $notices), 'One notice per rule, ordered by rule id.');
        TestCase::assertSame('Use the database abstraction.', $notices[0]->title, 'Title is the rule statement.');
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

    private static function assertOnlyAnalysedFileDataIsUsed(): void
    {
        $GLOBALS['ITP_CONTEXT_STUB_RULES'] = [
            '/p/Foo.php' => [new ApplicableRule('R::Database', 'S', null, [], ['App\\Foo'])],
            '/p/Other.php' => [new ApplicableRule('R::Other', 'S', null, [], ['App\\Other'])],
        ];
        $result = new AnalysisResult([], [], [
            new CollectedData(['/p/Foo.php'], '/p/Foo.php', AnalysedFileCollector::class),
            new CollectedData(['/p/Other.php'], '/p/Other.php', 'Some\\Other\\Collector'),
        ]);

        $notices = (new ArchitectureNoticeBuilder())->fromAnalysisResult($result);

        TestCase::assertSame(['architecture[R::Database]'], array_map(static fn ($n): string => $n->id, $notices), 'Data of other collectors must not widen the scope.');
        TestCase::assertSame([], (new ArchitectureNoticeBuilder())->fromAnalysisResult(self::result([])), 'No analysed files, no notices.');
    }

    private static function assertFormatterOnlyAddsNoticesWhenEnabled(): void
    {
        $GLOBALS['ITP_CONTEXT_STUB_RULES'] = ['/p/Foo.php' => [new ApplicableRule('R::Database', 'S', null, [], ['App\\Foo'])]];
        $result = self::result(['/p/Foo.php'], ['a PHPStan error']);
        $green = self::result(['/p/Foo.php']);

        $render = static function (array $agentFormat) use ($result): array {
            $output = new \PHPStan\Command\Output();
            $exit = (new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'json'] + $agentFormat]))->formatErrors($result, $output);

            return [$output->buffer, $exit];
        };

        [$disabled, $disabledExit] = $render([]);
        [$explicit, ] = $render(['itpContext' => false]);
        [$enabled, $enabledExit] = $render(['itpContext' => true]);

        TestCase::assertSame($disabled, $explicit, 'itpContext: false must equal omitting the option.');
        TestCase::assertTrue(!str_contains($disabled, 'notice'), 'Disabled integration must not mention notices.');
        TestCase::assertTrue(str_contains($enabled, 'architecture[R::Database]'), 'Enabled integration renders the rule notice.');
        TestCase::assertSame($disabledExit, $enabledExit, 'Architecture notices never change the exit code.');
        TestCase::assertSame(1, $enabledExit, 'The real error still fails.');

        $greenOutput = new \PHPStan\Command\Output();
        $greenExit = (new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'json', 'itpContext' => true]]))->formatErrors($green, $greenOutput);
        TestCase::assertSame(0, $greenExit, 'A green run stays green.');
        TestCase::assertTrue(!str_contains($greenOutput->buffer, 'architecture['), 'A green run gets no architecture notices.');
        TestCase::assertTrue(!str_contains($enabled, '"subject"') || !str_contains($enabled, 'R::Database"'), 'Architecture notices do not repeat the rule id as subject.');

        unset($GLOBALS['ITP_CONTEXT_STUB_RULES']);
    }

    /**
     * @param list<string> $files
     * @param list<string> $errors
     */
    private static function result(array $files, array $errors = []): AnalysisResult
    {
        return new AnalysisResult([], $errors, array_map(
            static fn (string $file): CollectedData => new CollectedData([$file], $file, AnalysedFileCollector::class),
            $files,
        ));
    }
}
