<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use Voku\PhpstanAgentFormat\Config\AgentFormatConfig;
use Voku\PhpstanAgentFormat\Context\ContextExtractor;
use Voku\PhpstanAgentFormat\Context\ContextTraceBuilder;
use Voku\PhpstanAgentFormat\Dto\CodeSnippet;
use Voku\PhpstanAgentFormat\Formatter\AgentErrorFormatter;
use Voku\PhpstanAgentFormat\Ingestion\PhpstanJsonExportIngestor;
use Voku\PhpstanAgentFormat\Normalizer\IssueNormalizer;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class PhpstanFixabilityTest
{
    public static function run(): void
    {
        self::assertDirectAnalysisUsesExplicitFixability();
        self::assertMagicCallDoesNotProveFixability();
        self::assertJsonExportUsesExplicitFixability();
    }

    private static function assertDirectAnalysisUsesExplicitFixability(): void
    {
        $file = dirname(__DIR__) . '/Fixture/Sample.php';

        $fixableError = new class ($file) {
            public function __construct(private readonly string $file)
            {
            }

            public function getFile(): string
            {
                return $this->file;
            }

            public function getLine(): int
            {
                return 9;
            }

            public function getMessage(): string
            {
                return 'Upstream confirmed an automatic fix.';
            }

            public function getIdentifier(): string
            {
                return 'cast.useless';
            }

            public function isFixable(): bool
            {
                return true;
            }
        };

        $legacyError = new class ($file) {
            public function __construct(private readonly string $file)
            {
            }

            public function getFile(): string
            {
                return $this->file;
            }

            public function getLine(): int
            {
                return 10;
            }

            public function getMessage(): string
            {
                return 'Legacy PHPStan error without isFixable.';
            }

            public function getIdentifier(): string
            {
                return 'argument.type';
            }
        };

        $config = AgentFormatConfig::fromParameters(['outputMode' => 'json']);
        $normalizer = new IssueNormalizer(
            new ContextExtractor($config),
            new ContextTraceBuilder(),
            $config,
        );
        $issues = $normalizer->normalizeRaw([$fixableError, $legacyError]);
        $byMessage = [];
        foreach ($issues as $issue) {
            $byMessage[$issue->message] = $issue;
        }

        $fixable = $byMessage['Upstream confirmed an automatic fix.'];
        TestCase::assertSame(true, $fixable->fixable, 'Explicit upstream auto-fixability must be kept.');
        TestCase::assertSame(true, $fixable->toArray()['fixable'] ?? null, 'Fixable true must be serialized.');
        TestCase::assertSame(true, $fixable->withSecondaryLocations([])->fixable, 'Secondary-location copies must retain fixability.');
        TestCase::assertSame(true, $fixable->withoutExtractedContext()->fixable, 'Context reductions must retain fixability.');
        TestCase::assertSame(true, $fixable->withSnippet(new CodeSnippet(9, 9, []))->fixable, 'Snippet reductions must retain fixability.');
        TestCase::assertSame(false, $byMessage['Legacy PHPStan error without isFixable.']->fixable, 'Older PHPStan API must be supported.');
        TestCase::assertTrue(
            !array_key_exists('fixable', $byMessage['Legacy PHPStan error without isFixable.']->toArray()),
            'Unknown fixability must not be reported as an automatic fix.',
        );
    }

    private static function assertMagicCallDoesNotProveFixability(): void
    {
        $magicTrue = new class {
            public function getMessage(): string
            {
                return 'Magic __call returns true.';
            }

            /** @param list<mixed> $arguments */
            public function __call(string $name, array $arguments): bool
            {
                return true;
            }
        };

        $magicThrows = new class {
            public function getMessage(): string
            {
                return 'Magic __call throws.';
            }

            /** @param list<mixed> $arguments */
            public function __call(string $name, array $arguments): never
            {
                throw new \\LogicException('Unknown method must not be called: ' . $name);
            }
        };

        $config = AgentFormatConfig::fromParameters(['outputMode' => 'json']);
        $normalizer = new IssueNormalizer(new ContextExtractor($config), new ContextTraceBuilder(), $config);
        $issues = $normalizer->normalizeRaw([$magicTrue, $magicThrows]);

        TestCase::assertSame(2, count($issues), 'Both unsupported API errors must still be reported.');
        foreach ($issues as $issue) {
            TestCase::assertSame(false, $issue->fixable, 'Magic __call cannot prove upstream fixability.');
            TestCase::assertTrue(!array_key_exists('fixable', $issue->toArray()), 'Unsupported fixability must be omitted.');
        }
    }

    private static function assertJsonExportUsesExplicitFixability(): void
    {
        $file = dirname(__DIR__) . '/Fixture/Sample.php';
        $payload = [
            'files' => [
                $file => [
                    'messages' => [
                        ['message' => 'JSON fixable', 'line' => 9, 'identifier' => 'cast.useless', 'fixable' => true],
                        ['message' => 'JSON false', 'line' => 10, 'identifier' => 'argument.type', 'fixable' => false],
                        ['message' => 'JSON unknown', 'line' => 11, 'identifier' => 'argument.type'],
                        ['message' => 'JSON nonboolean', 'line' => 12, 'identifier' => 'argument.type', 'fixable' => 'true'],
                    ],
                ],
            ],
            'errors' => [],
        ];

        $ingestor = new PhpstanJsonExportIngestor();
        $imported = $ingestor->ingest($payload)['fileSpecificErrors'];
        TestCase::assertSame(true, $imported[0]->isFixable(), 'JSON true must survive ingestion.');
        TestCase::assertSame(false, $imported[1]->isFixable(), 'JSON false must not claim automatic fixability.');
        TestCase::assertSame(false, $imported[2]->isFixable(), 'Older JSON exports must remain supported.');
        TestCase::assertSame(false, $imported[3]->isFixable(), 'Nonboolean fixable metadata must not be trusted.');

        $formatter = new AgentErrorFormatter([
            'outputMode' => 'json',
            'maxIssuesPerCluster' => 10,
        ]);
        $json = $formatter->formatPhpstanJsonExport(json_encode($payload, JSON_THROW_ON_ERROR));
        /** @var array{clusters: list<array{representativeIssues: list<array{message: string, fixable?: bool}>}>} $report */
        $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $byMessage = [];
        foreach ($report['clusters'] as $cluster) {
            foreach ($cluster['representativeIssues'] as $issue) {
                $byMessage[$issue['message']] = $issue;
            }
        }

        TestCase::assertSame(4, count($byMessage), 'All fixture issues must remain visible for this contract test.');
        TestCase::assertSame(true, $byMessage['JSON fixable']['fixable'] ?? null, 'JSON exports must preserve proven fixability.');
        foreach (['JSON false', 'JSON unknown', 'JSON nonboolean'] as $message) {
            TestCase::assertTrue(!array_key_exists('fixable', $byMessage[$message]), 'Unproven auto-fixability must be omitted.');
        }
    }
}
