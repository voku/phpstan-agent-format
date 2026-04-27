<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use InvalidArgumentException;
use Voku\PhpstanAgentFormat\Ingestion\PhpstanJsonExportIngestor;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class PhpstanJsonExportIngestorTest
{
    public static function run(): void
    {
        $payload = [
            'files' => [
                '/tmp/a.php' => [
                    'messages' => [
                        [
                            'message' => 'Problem A',
                            'line' => 0,
                            'identifier' => '',
                            'tip' => '',
                            'metadata' => [
                                'shape' => ['foo' => 'bar'],
                                'list' => [
                                    ['key' => 'value'],
                                    'leaf',
                                ],
                            ],
                            'nodeLine' => 7,
                            'nodeType' => 'Expr',
                            'traitFilePath' => '/tmp/trait.php',
                        ],
                        [
                            'message' => '',
                            'line' => 99,
                        ],
                        'skip-me',
                    ],
                ],
                '/tmp/b.php' => [
                    'messages' => 'invalid',
                ],
                'invalid-file-key' => 'invalid-entry',
            ],
            'errors' => [
                'Top level error',
                '',
                ['skip-me'],
            ],
        ];

        $ingestor = new PhpstanJsonExportIngestor();
        $decodedFromArray = $ingestor->ingest($payload);
        $decodedFromString = $ingestor->ingest((string) json_encode($payload, JSON_THROW_ON_ERROR));

        TestCase::assertSame(1, count($decodedFromArray['fileSpecificErrors']), 'Only valid file-specific errors should be imported from array payloads.');
        TestCase::assertSame(['Top level error'], $decodedFromArray['notFileSpecificErrors'], 'Only non-empty top-level string errors should be imported.');
        TestCase::assertSame(1, count($decodedFromString['fileSpecificErrors']), 'String payloads should decode to the same valid file-specific errors.');
        TestCase::assertSame(['Top level error'], $decodedFromString['notFileSpecificErrors'], 'String payloads should decode to the same top-level errors.');

        $error = $decodedFromArray['fileSpecificErrors'][0];
        TestCase::assertSame('/tmp/a.php', $error->getFile(), 'Imported PHPStan errors should preserve file paths.');
        TestCase::assertSame(1, $error->getLine(), 'Imported PHPStan errors should normalize line numbers to be at least one.');
        TestCase::assertSame(null, $error->getIdentifier(), 'Empty identifiers should normalize to null.');
        TestCase::assertSame(null, $error->getTip(), 'Empty tips should normalize to null.');
        TestCase::assertSame(7, $error->getNodeLine(), 'Node lines should be preserved when present.');
        TestCase::assertSame('Expr', $error->getNodeType(), 'Node types should be preserved when present.');
        TestCase::assertSame('/tmp/trait.php', $error->getTraitFilePath(), 'Trait file paths should be preserved when present.');
        TestCase::assertSame(
            [
                'shape' => ['foo' => 'bar'],
                'list' => [
                    ['key' => 'value'],
                    'leaf',
                ],
            ],
            $error->getMetadata(),
            'Metadata should remain normalized without dropping maps or indexed entries.',
        );

        $threw = false;
        try {
            $ingestor->ingest('123');
        } catch (InvalidArgumentException) {
            $threw = true;
        }

        TestCase::assertTrue($threw, 'Scalar JSON payloads should be rejected because PHPStan exports must decode to object-like arrays.');
    }
}
