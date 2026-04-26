# voku/phpstan-agent-format

`voku/phpstan-agent-format` adds a custom PHPStan formatter named `agent` that emits compact, deterministic v2 repair envelopes for coding agents.

## Why

PHPStan default output is optimized for humans and CI logs.
This package groups related findings, deduplicates repeated symptoms, and includes compact context traces (not fake runtime stack traces).

## Quick start

### 1. Install

```bash
composer require --dev voku/phpstan-agent-format
```

### 2. Enable the formatter

Add the bundled extension to your PHPStan config:

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

The bundled extension registers `--error-format=agent`, ships sensible defaults, and declares the `agentFormat` config schema so partial config blocks are valid.

### 3. Run PHPStan with the agent formatter

```bash
vendor/bin/phpstan analyse --error-format=agent
```

That is enough to start producing agent-friendly output.

## Easiest ways to use it

### Save structured output for an agent

JSON is the default mode and works well when your coding agent can read files directly:

```bash
vendor/bin/phpstan analyse --error-format=agent > phpstan-agent.json
```

### Save paste-friendly output for chat-based workflows

If you want to paste the result into Copilot, Codex, Claude, Cursor, or another coding assistant, markdown is often the easiest format:

```bash
vendor/bin/phpstan analyse --error-format=agent > phpstan-agent.md
```

```neon
parameters:
    agentFormat:
        outputMode: markdown
```

### Reformat an existing PHPStan JSON export

If you already have `--error-format=json` output, you can reformat it through `AgentErrorFormatter::formatPhpstanJsonExport()`.

## Coding agent starter instructions

Use this as a copy-paste prompt for your coding agent:

```text
Read the attached phpstan-agent-format report first.
Treat each cluster as a single underlying problem and fix the root cause instead of patching duplicates.
Keep changes minimal, preserve existing behavior, and rerun PHPStan after each fix.
Prefer fixing clusters in the order they appear unless a later cluster is clearly blocked by an earlier one.
```

Recommended workflow:

1. Run PHPStan with `--error-format=agent`.
2. Save the output as JSON or Markdown.
3. Give that file to your coding agent together with the starter instructions above.
4. Let the agent fix one cluster at a time and rerun PHPStan after each round.

The repository CI dogfoods both modes by running PHPStan once with the default formatter, once with `--error-format=agent` on the library itself, and again against committed failing/clean fixture configs that exercise the agent envelope on real PHPStan fixture output.
v2 also supports formatting a prior `--error-format=json` PHPStan export through `AgentErrorFormatter::formatPhpstanJsonExport()`.

## Output modes

- `agentJson` (default)
- `agentNdjson`
- `agentMarkdown`
- `agentCompact`

Accepted aliases for `outputMode`: `json`, `ndjson`, `markdown`, `compact`.

Use `agentJson` for structured automation, `agentMarkdown` for copy/paste workflows, `agentNdjson` for streaming pipelines, and `agentCompact` for terse terminal output.

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
- `agent-ndjson-example.ndjson`
- `agent-markdown-example.md`
- `agent-compact-example.txt`

## What's new in v2

- Symbol extraction now prefers structured PHPStan metadata when available and falls back to deterministic message heuristics.
- Type-origin and propagation traces stay compact while exposing stable hop kinds for downstream consumers.
- JSON output includes an explicit `schema` descriptor so the envelope can evolve without breaking the existing top-level contract.
- PHPStan JSON exports can be reformatted through `AgentErrorFormatter::formatPhpstanJsonExport()`.
