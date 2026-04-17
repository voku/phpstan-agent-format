# voku/phpstan-agent-format

`voku/phpstan-agent-format` adds a custom PHPStan formatter named `agent` that emits compact, deterministic repair envelopes for coding agents.

## Why

PHPStan default output is optimized for humans and CI logs.
This package groups related findings, deduplicates repeated symptoms, and includes compact context traces (not fake runtime stack traces).

## Install

```bash
composer require --dev voku/phpstan-agent-format
```

## Configure

```neon
includes:
    - vendor/voku/phpstan-agent-format/extension.neon

parameters:
    level: max
    paths:
        - src

    agentFormat:
        outputMode: json
        maxClusters: 30
        maxIssuesPerCluster: 3
        snippetLinesBefore: 2
        snippetLinesAfter: 3
        includeDocblock: false
        includeRelatedDefinition: true
        tokenBudget: 12000
        redactPatterns:
            - '(?i)password\s*=\s*.+'
            - '(?i)api[_-]?key\s*=\s*.+'
            - '(?i)secret\s*=\s*.+'
```

Run:

```bash
vendor/bin/phpstan analyse --error-format=agent
```

The repository CI dogfoods both modes by running PHPStan once with the default formatter and once with `--error-format=agent`.

## Output modes

- `agentJson` (default)
- `agentNdjson`
- `agentMarkdown`
- `agentCompact`

Accepted aliases for `outputMode`: `json`, `ndjson`, `markdown`, `compact`.

## Envelope shape (JSON)

```json
{
  "tool": "phpstan-agent-format",
  "version": "0.1.0",
  "phpstanVersion": "2.1.x",
  "summary": {
    "totalIssues": 3,
    "clusters": 1,
    "suppressedDuplicates": 2,
    "tokenStats": {
      "estimatedTokens": 420,
      "tokenBudget": 12000,
      "wasReduced": false
    }
  },
  "clusters": [
    {
      "clusterId": "6fdafecf6214",
      "kind": "nullable-propagation",
      "ruleIdentifier": "argument.type",
      "rootCauseSummary": "Nullable value reaches a non-null expectation.",
      "repairStrategySummary": "Constrain nullability earlier or widen the target type to accept null.",
      "confidence": 0.7,
      "affectedFiles": ["src/Example.php"],
      "representativeIssues": [],
      "suppressedDuplicateCount": 2
    }
  ]
}
```

## Clustering strategy (v1)

First-pass clustering groups by:

- same rule identifier
- same symbol context when detected
- same file + nearby line bucket
- same type-origin hint

Cluster kinds include:

- nullable propagation
- missing type declaration
- array shape drift
- undefined member from inferred type
- stale ignore/baseline noise
- fallback: repeated same-rule same-symbol

## Token budget reduction strategy

When estimated tokens exceed `tokenBudget`, degradation is deterministic and ordered:

1. remove verbose/secondary details (secondary locations)
2. shrink snippets to focused lines
3. reduce representative issues per cluster
4. keep root cause + repair strategy summaries

## Security and privacy

Snippets are redacted using configurable regex patterns before serialization.

## Example outputs

See `/examples/`:

- `agent-json-example.json`
- `agent-ndjson-example.ndjson`
- `agent-markdown-example.md`
- `agent-compact-example.txt`

## Trade-offs in v1

- Symbol extraction is heuristic from messages (fast and deterministic, not full AST context reconstruction).
- Type origin and propagation hops are conservative and compact.
- JSON schema is stable and versioned, designed to extend without breaking top-level keys.

## Future improvements

- Deeper type-origin tracing from richer PHPStan internals.
- Optional ingestion path from PHPStan JSON export.
- Additional cluster heuristics for generics/template source drift.
