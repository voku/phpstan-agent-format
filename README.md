# voku/phpstan-agent-format

`voku/phpstan-agent-format` adds a custom PHPStan formatter named `agent` that emits compact, deterministic v2 repair envelopes for coding agents.
The default output now uses TOON (Token-Oriented Object Notation) for better token efficiency in LLM repair loops.

## Why

PHPStan default output is optimized for humans and CI logs.
This package groups related findings, deduplicates repeated symptoms, and includes compact context traces (not fake runtime stack traces).

## Install

```bash
composer require --dev voku/phpstan-agent-format
```

This package ships a PHPStan error formatter named `agent`.
If you include `extension.neon`, `--error-format=agent` emits TOON by default.

## Quick start for coding agents

Use the formatter as if it were a small repair skill:

> You are fixing PHPStan issues in this repository.
> Run PHPStan with `--error-format=agent`.
> Read the TOON envelope.
> Prioritize `rootCauseSummary`, `repairStrategySummary`, `symbolContext`, snippets, and `contextTrace`.
> Make the smallest safe code change that removes the reported issue without changing unrelated behavior.

Typical loop:

```bash
vendor/bin/phpstan analyse --error-format=agent
```

Recommended agent workflow:

1. Read `summary` to understand issue count and clustering.
2. Tackle one cluster at a time using `kind`, `ruleIdentifier`, and `affectedFiles`.
3. Use each representative issue's `symbolContext` and snippet to locate the fix.
4. Re-run PHPStan after the change and confirm the cluster disappears.

When an agent needs JSON instead of TOON, set `agentFormat.outputMode: json`.

## Configure

```neon
includes:
    - vendor/voku/phpstan-agent-format/extension.neon

parameters:
    level: max
    paths:
        - src

    agentFormat:
        outputMode: toon
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

The repository CI dogfoods both modes by running PHPStan once with the default formatter, once with `--error-format=agent` on the library itself, and again against committed failing/clean fixture configs that exercise the agent envelope on real PHPStan fixture output.
The bundled extension also declares the `agentFormat` config schema, so real fixture configs can pass formatter options directly through PHPStan.
v2 also supports formatting a prior `--error-format=json` PHPStan export through `AgentErrorFormatter::formatPhpstanJsonExport()`.

## Output modes

- `agentToon` (default)
- `agentJson`
- `agentNdjson`
- `agentMarkdown`
- `agentCompact`

Accepted aliases for `outputMode`: `toon`, `json`, `ndjson`, `markdown`, `compact`.

## Envelope shape (TOON)

```text
tool: phpstan-agent-format
version: 2.0.0
schema:
  name: phpstan-agent-format
  version: 2.0.0
phpstanVersion: 2.1.50
summary:
  totalIssues: 3
  clusters: 1
  suppressedDuplicates: 2
  tokenStats:
    estimatedTokens: 420
    tokenBudget: 12000
    wasReduced: false
clusters[1]{clusterId,kind,ruleIdentifier,rootCauseSummary,repairStrategySummary,confidence,affectedFiles,representativeIssues,suppressedDuplicateCount}:
  6fdafecf6214,nullable-propagation,argument.type,Nullable value reaches a non-null expectation.,Constrain nullability earlier or widen the target type to accept null.,0.7,[1]: src/Example.php,[0]:,2
```

## Envelope shape (JSON)

Representative issues include structured repair hints inside `symbolContext`, including the targeted parameter/property plus expected and inferred types when PHPStan exposes them.

```json
{
  "tool": "phpstan-agent-format",
  "version": "2.0.0",
  "schema": {
    "name": "phpstan-agent-format",
    "version": "2.0.0"
  },
  "phpstanVersion": "2.1.50",
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

## Clustering strategy (v2)

First-pass clustering groups by:

- same PHPStan error-identifier family when available, otherwise same rule identifier
- same symbol context when detected
- same file + nearby line bucket
- same type-origin hint

Cluster kinds include:

- nullable propagation
- missing type declaration
- generic/template drift
- array shape drift
- undefined member from inferred type
- invalid offset access
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
- `agent-toon-example.toon`
- `agent-ndjson-example.ndjson`
- `agent-markdown-example.md`
- `agent-compact-example.txt`

## What's new in v2

- Symbol extraction now prefers structured PHPStan metadata when available and falls back to deterministic message heuristics.
- Type-origin and propagation traces stay compact while exposing stable hop kinds for downstream consumers.
- JSON output includes an explicit `schema` descriptor so the envelope can evolve without breaking the existing top-level contract.
- PHPStan JSON exports can be reformatted through `AgentErrorFormatter::formatPhpstanJsonExport()`.
