<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Tests\Unit;

use Voku\PhpstanAgentFormat\Context\SourceSymbolContextExtractor;
use Voku\PhpstanAgentFormat\Tests\Support\TestCase;

final class SourceSymbolContextExtractorTest
{
    public static function run(): void
    {
        $classFixture = dirname(__DIR__) . '/Fixture/SourceContextFixture.php';
        $functionFixture = dirname(__DIR__) . '/Fixture/SourceFunctionFixture.php';

        // --- Class method with phpdoc-typed parameter ---
        $extractor = new SourceSymbolContextExtractor();
        $result = $extractor->extract($classFixture, 12, '$value');
        TestCase::assertSame('SourceContextFixture', $result['className'], 'Class name should be extracted from source.');
        TestCase::assertSame('SourceContextFixture::hydrate', $result['methodName'], 'Method name should be qualified with the class name.');
        TestCase::assertSame(null, $result['functionName'], 'Function name should be null inside a class method.');
        TestCase::assertSame('value', $result['parameterName'], 'Leading $ should be stripped from the preferred parameter name.');
        TestCase::assertSame('non-empty-string', $result['expectedType'], 'PHPDoc param type should be surfaced as the expected type.');
        TestCase::assertSame('phpdoc', $result['typeOrigin'], 'Type derived from PHPDoc should yield origin "phpdoc".');
        TestCase::assertSame(null, $result['inferredType'], 'Inferred type is not populated by source extraction.');

        // --- Class method return type (no specific parameter) ---
        $result = $extractor->extract($classFixture, 12);
        TestCase::assertSame('SourceContextFixture::hydrate', $result['methodName'], 'Method should still be resolved when no parameter is specified.');
        TestCase::assertSame('int', $result['expectedType'], 'Native return type should be surfaced when no parameter is specified.');
        TestCase::assertSame('native', $result['typeOrigin'], 'Native return type origin should be "native".');
        TestCase::assertSame(null, $result['parameterName'], 'Parameter name should be null when no preferred parameter is given.');

        // --- Standalone function with phpdoc-typed parameter ---
        $result = $extractor->extract($functionFixture, 10, 'count');
        TestCase::assertSame(null, $result['className'], 'Class name should be null for free functions.');
        TestCase::assertSame(null, $result['methodName'], 'Method name should be null for free functions.');
        TestCase::assertSame('sourceRepeatString', $result['functionName'], 'Free function name should be extracted.');
        TestCase::assertSame('count', $result['parameterName'], 'Preferred parameter should be propagated for free functions.');
        TestCase::assertSame('positive-int', $result['expectedType'], 'PHPDoc param type should be surfaced for free function parameters.');
        TestCase::assertSame('phpdoc', $result['typeOrigin'], 'PHPDoc-derived free-function param type should yield origin "phpdoc".');

        // --- Standalone function return type (no parameter) ---
        $result = $extractor->extract($functionFixture, 10);
        TestCase::assertSame('sourceRepeatString', $result['functionName'], 'Function should still be resolved when no parameter is specified.');
        TestCase::assertSame('string', $result['expectedType'], 'Native return type should be surfaced for free functions.');
        TestCase::assertSame('native', $result['typeOrigin'], 'Native return-type origin should be "native".');

        // --- Non-existent file returns all nulls gracefully ---
        $result = $extractor->extract('/nonexistent/path/to/file.php', 5, 'param');
        TestCase::assertSame(null, $result['className'], 'Non-existent file should yield null class.');
        TestCase::assertSame(null, $result['methodName'], 'Non-existent file should yield null method.');
        TestCase::assertSame(null, $result['functionName'], 'Non-existent file should yield null function.');
        TestCase::assertSame(null, $result['expectedType'], 'Non-existent file should yield null expected type.');
        TestCase::assertSame(null, $result['typeOrigin'], 'Non-existent file should yield null type origin.');

        // --- preferredProperty is stripped and passed through ---
        $result = $extractor->extract($classFixture, 12, null, '$myProp');
        TestCase::assertSame('myProp', $result['propertyName'], 'Leading $ should be stripped from the preferred property name.');

        // --- Result is cached: second call with same file returns same data ---
        $result1 = $extractor->extract($classFixture, 12);
        $result2 = $extractor->extract($classFixture, 12);
        TestCase::assertSame($result1['className'], $result2['className'], 'Repeated calls for the same file should return consistent results (cached).');
    }
}
