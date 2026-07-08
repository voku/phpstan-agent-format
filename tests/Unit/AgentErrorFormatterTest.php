<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use HelgeSverre\Toon\Toon;
use PHPStan\Command\AnalysisResult;
use PHPStan\Command\Output;
use Voku\PhpstanAgentFormat\Formatter\AgentErrorFormatter;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class AgentErrorFormatterTest
{
    public static function run(): void
    {
        self::assertCleanRunsReturnZeroExitCode();
        self::assertJsonExportArrayPayloadSupportsAllOutputModes();
        self::assertDocblockAndRelatedDefinitionOptions();
    }

    private static function assertCleanRunsReturnZeroExitCode(): void
    {
        $formatter = new AgentErrorFormatter([
            'agentFormat' => [
                'outputMode' => 'json',
            ],
        ]);
        $output = new Output();

        $exitCode = $formatter->formatErrors(new AnalysisResult([], []), $output);

        /** @var array{
         *   tool: string,
         *   phpstanVersion: string,
         *   summary: array{totalIssues: int, clusters: int, suppressedDuplicates: int},
         *   clusters: list<array<mixed>>
         * } $decoded
         */
        $decoded = json_decode($output->buffer, true, 512, JSON_THROW_ON_ERROR);

        TestCase::assertSame(0, $exitCode, 'Clean analysis results should return a zero exit code.');
        TestCase::assertSame('phpstan-agent-format', $decoded['tool'], 'Clean runs should still emit the standard tool name.');
        TestCase::assertSame(0, $decoded['summary']['totalIssues'], 'Clean runs should report zero issues.');
        TestCase::assertSame(0, $decoded['summary']['clusters'], 'Clean runs should report zero clusters.');
        TestCase::assertSame(0, count($decoded['clusters']), 'Clean runs should serialize an empty cluster list.');
    }

    private static function assertJsonExportArrayPayloadSupportsAllOutputModes(): void
    {
        $payload = [
            'files' => [
                '/tmp/a.php' => [
                    'messages' => [
                        [
                            'message' => 'Call to an undefined method Foo::bar().',
                            'line' => 12,
                            'identifier' => 'method.notFound',
                        ],
                    ],
                ],
            ],
            'errors' => [],
        ];

        $jsonFormatter = new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'json']]);
        /** @var array{
         *   summary: array{totalIssues: int},
         *   clusters: list<array{
         *     ruleIdentifier: ?string,
         *     affectedFiles: list<string>,
         *     representativeIssues: list<array{
         *       message: string,
         *       location: array{file: string, line: int}
         *     }>
         *   }>
         * } $jsonDecoded
         */
        $jsonDecoded = json_decode($jsonFormatter->formatPhpstanJsonExport($payload), true, 512, JSON_THROW_ON_ERROR);
        TestCase::assertSame(1, $jsonDecoded['summary']['totalIssues'], 'JSON export array payloads should still normalize into one issue.');
        TestCase::assertSame('/tmp/a.php:12', $jsonDecoded['clusters'][0]['affectedFiles'][0], 'JSON export array payloads should preserve path:line affected files.');
        TestCase::assertSame('/tmp/a.php', $jsonDecoded['clusters'][0]['representativeIssues'][0]['location']['file'], 'Representative issue locations should preserve the file path.');
        TestCase::assertSame(12, $jsonDecoded['clusters'][0]['representativeIssues'][0]['location']['line'], 'Representative issue locations should preserve the issue line.');

        /** @var array{tool: string, summary: array{totalIssues: int}} $toonDecoded */
        $toonDecoded = Toon::decode((new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'toon']]))->formatPhpstanJsonExport($payload));
        TestCase::assertSame('phpstan-agent-format', $toonDecoded['tool'], 'TOON mode should be selected from formatter output mode aliases.');
        TestCase::assertSame(1, $toonDecoded['summary']['totalIssues'], 'TOON mode should preserve the normalized issue count.');

        $ndjsonOutput = (new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'ndjson']]))->formatPhpstanJsonExport($payload);
        $ndjsonLines = explode("\n", trim($ndjsonOutput));
        TestCase::assertSame(2, count($ndjsonLines), 'NDJSON mode should emit one summary line and one cluster line.');
        /** @var array{summary: array{totalIssues: int}} $ndjsonSummary */
        $ndjsonSummary = json_decode($ndjsonLines[0], true, 512, JSON_THROW_ON_ERROR);
        /** @var array{cluster: array{affectedFiles: list<string>}} $ndjsonCluster */
        $ndjsonCluster = json_decode($ndjsonLines[1], true, 512, JSON_THROW_ON_ERROR);
        TestCase::assertSame(1, $ndjsonSummary['summary']['totalIssues'], 'NDJSON summary lines should preserve total issues.');
        TestCase::assertSame(['/tmp/a.php:12'], $ndjsonCluster['cluster']['affectedFiles'], 'NDJSON cluster lines should wrap the affected files payload.');

        $markdownOutput = (new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'markdown']]))->formatPhpstanJsonExport($payload);
        TestCase::assertTrue(str_contains($markdownOutput, '# PHPStan Agent Repair Envelope'), 'Markdown mode should emit the markdown envelope header.');
        TestCase::assertTrue(str_contains($markdownOutput, '- Rule: `method.notFound`'), 'Markdown mode should surface the rule identifier.');
        TestCase::assertTrue(str_contains($markdownOutput, '  - `/tmp/a.php:12` Call to an undefined method Foo::bar().'), 'Markdown mode should list issue locations using file:line references.');

        $compactOutput = (new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'compact']]))->formatPhpstanJsonExport($payload);
        TestCase::assertTrue(str_contains($compactOutput, 'phpstan-agent-format totalIssues=1 clusters=1 suppressed=0'), 'Compact mode should emit the summary line.');
        TestCase::assertTrue(str_contains($compactOutput, 'rule=method.notFound'), 'Compact mode should include cluster rule identifiers.');
    }

    private static function assertDocblockAndRelatedDefinitionOptions(): void
    {
        $fixture = sys_get_temp_dir() . '/phpstan-agent-format-context-fixture.php';
        file_put_contents($fixture, <<<'PHP'
<?php

/**
 * Builds a verified recipient email address.
 * api_key = function-secret
 */
function contextFixtureFunction(): string
{
    return 'root@example.com';
}

/**
 * Sends an email to a verified recipient.
 * api_key = super-secret
 */
final class ContextFixtureMailer
{
    /**
     * The default verified recipient.
     * secret = property-secret
     */
    public string $email = 'secret = property-secret';

    /**
     * Sends a message body.
     * password = method-secret
     */
    public function send(string $email): void
    {
    }
}
PHP);

        $fixtureLines = file($fixture, FILE_IGNORE_NEW_LINES);
        if (!is_array($fixtureLines)) {
            throw new \RuntimeException('Context fixture should be readable.');
        }
        $lineOf = static function (string $needle) use ($fixtureLines): int {
            foreach ($fixtureLines as $index => $line) {
                if (str_contains((string) $line, $needle)) {
                    return $index + 1;
                }
            }

            return 1;
        };

        $payload = [
            'files' => [
                $fixture => [
                    'messages' => [
                        [
                            'message' => 'Parameter #1 $email of method ContextFixtureMailer::send() expects string, string|null given.',
                            'line' => $lineOf('public function send'),
                            'identifier' => 'argument.type',
                            'metadata' => [
                                'className' => 'ContextFixtureMailer',
                                'methodName' => 'send',
                                'parameterName' => 'email',
                            ],
                        ],
                        [
                            'message' => 'Property ContextFixtureMailer::$email type has no value type specified in iterable type array.',
                            'line' => $lineOf('public string $email'),
                            'identifier' => 'missingType.iterableValue',
                            'metadata' => [
                                'className' => 'ContextFixtureMailer',
                                'propertyName' => 'email',
                            ],
                        ],
                        [
                            'message' => 'Function contextFixtureFunction() should return string but returns int.',
                            'line' => $lineOf('function contextFixtureFunction'),
                            'identifier' => 'return.type',
                            'metadata' => [
                                'functionName' => 'contextFixtureFunction',
                            ],
                        ],
                        [
                            'message' => 'Class ContextFixtureMailer has an invalid PHPDoc tag.',
                            'line' => $lineOf('final class ContextFixtureMailer'),
                            'identifier' => 'phpDoc.parseError',
                            'metadata' => [
                                'className' => 'ContextFixtureMailer',
                            ],
                        ],
                    ],
                ],
            ],
            'errors' => [],
        ];

        $disabled = new AgentErrorFormatter(['agentFormat' => ['outputMode' => 'json', 'includeDocblock' => false, 'includeRelatedDefinition' => false]]);
        /** @var array{clusters:list<array{representativeIssues:list<array<string,mixed>>}>} $disabledDecoded */
        $disabledDecoded = json_decode($disabled->formatPhpstanJsonExport($payload), true, 512, JSON_THROW_ON_ERROR);
        $disabledIssue = $disabledDecoded['clusters'][0]['representativeIssues'][0];
        TestCase::assertTrue(!array_key_exists('docblock', $disabledIssue), 'Disabled docblock option should preserve the existing issue schema.');
        TestCase::assertTrue(!array_key_exists('relatedDefinition', $disabledIssue), 'Disabled related definition option should preserve the existing issue schema.');

        $enabled = new AgentErrorFormatter([
            'agentFormat' => [
                'outputMode' => 'json',
                'includeDocblock' => true,
                'includeRelatedDefinition' => true,
                'redactPatterns' => ['(?i)api[_-]?key\s*=\s*.+', '(?i)secret\s*=\s*.+', '(?i)password\s*=\s*.+'],
            ],
        ]);
        /** @var array{clusters:list<array{representativeIssues:list<array<string,mixed>>}>} $enabledDecoded */
        $enabledDecoded = json_decode($enabled->formatPhpstanJsonExport($payload), true, 512, JSON_THROW_ON_ERROR);
        $issues = [];
        foreach ($enabledDecoded['clusters'] as $cluster) {
            foreach ($cluster['representativeIssues'] as $issue) {
                $issues[] = $issue;
            }
        }

        TestCase::assertSame(4, count($issues), 'Context options should keep all representative issues valid.');
        $joined = json_encode($issues, JSON_THROW_ON_ERROR);
        TestCase::assertTrue(str_contains($joined, 'Sends a message body.'), 'Method issues should include the nearest method docblock.');
        TestCase::assertTrue(str_contains($joined, 'The default verified recipient.'), 'Property issues should include the nearest property docblock.');
        TestCase::assertTrue(str_contains($joined, 'Builds a verified recipient email address.'), 'Function issues should include the nearest function docblock.');
        TestCase::assertTrue(str_contains($joined, 'Sends an email to a verified recipient.'), 'Class issues should include the nearest class docblock.');
        TestCase::assertTrue(str_contains($joined, '[REDACTED]'), 'Docblocks and related snippets should use configured redaction patterns.');
        TestCase::assertTrue(!str_contains($joined, 'method-secret'), 'Redaction should remove method docblock secrets.');
        TestCase::assertTrue(!str_contains($joined, 'property-secret'), 'Redaction should remove property docblock secrets.');

        $methodDefinitionFound = false;
        $propertyDefinitionFound = false;
        $functionDefinitionFound = false;
        $classDefinitionFound = false;
        foreach ($issues as $issue) {
            /** @var array{kind:string,snippet:list<string>}|null $definition */
            $definition = $issue['relatedDefinition'];
            if ($definition !== null && $definition['kind'] === 'method' && str_contains($definition['snippet'][0], 'public function send(string $email): void')) {
                $methodDefinitionFound = true;
            }
            if ($definition !== null && $definition['kind'] === 'property' && str_contains($definition['snippet'][0], 'public string $email')) {
                $propertyDefinitionFound = true;
            }
            if ($definition !== null && $definition['kind'] === 'function' && str_contains($definition['snippet'][0], 'function contextFixtureFunction(): string')) {
                $functionDefinitionFound = true;
            }
            if ($definition !== null && $definition['kind'] === 'class' && str_contains($definition['snippet'][0], 'final class ContextFixtureMailer')) {
                $classDefinitionFound = true;
            }
        }

        TestCase::assertTrue($methodDefinitionFound, 'Related definitions should include compact method signatures.');
        TestCase::assertTrue($propertyDefinitionFound, 'Related definitions should include compact property declarations.');
        TestCase::assertTrue($functionDefinitionFound, 'Related definitions should include compact function signatures.');
        TestCase::assertTrue($classDefinitionFound, 'Related definitions should include compact class declarations.');

        @unlink($fixture);
    }

}
