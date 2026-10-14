<?php

declare(strict_types=1);

namespace Voku\PhpstanAgentFormat\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\FileNode;

/**
 * Records which files belong to an analysis run. PHPStan keeps collector data in its
 * result cache, so the file list is complete for warm-cache runs too. It knows nothing
 * about architecture rules; the formatter decides what to do with the paths.
 *
 * @implements Collector<FileNode, string>
 */
final class AnalysedFileCollector implements Collector
{
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): string
    {
        return $scope->getFile();
    }
}
