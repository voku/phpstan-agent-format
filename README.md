# voku/phpstan-agent-format

> 🚮 Stop feeding AI agents console garbage. Give them structured repair envelopes instead.

`voku/phpstan-agent-format` adds a custom PHPStan formatter named `agent` that emits compact, deterministic repair envelopes for coding agents.
The default output uses [TOON](https://github.com/helgesverre/toon) for token-efficient LLM repair loops.

---

## ⚡ Quick start

```bash
composer require --dev voku/phpstan-agent-format
```

Add the extension to your `phpstan.neon`:

```neon
includes:
    - vendor/voku/phpstan-agent-format/extension.neon

parameters:
    level: max
    paths:
        - src
```

Run PHPStan with the `agent` formatter:

```bash
vendor/bin/phpstan analyse --error-format=agent
```

That is enough to get agent-ready TOON output.

---

## 🤖 Use as an agent skill

Paste this into your coding agent's system prompt or context:

> You are fixing PHPStan issues in this repository.
> Run `vendor/bin/phpstan analyse --error-format=agent`.
> Read the TOON envelope.
> Pick **one cluster** at a time.
> Use `rootCauseSummary`, `repairStrategySummary`, `symbolContext`, snippets, and `contextTrace` to understand the root cause.
> Make the **smallest safe code change** that removes the reported issue without changing unrelated behavior.
> Re-run PHPStan after each change and confirm the cluster disappears before moving on.

Recommended agent loop:

1. Read `summary` — understand total issue count and how many clusters were found.
2. Pick one cluster — use `kind`, `ruleIdentifier`, and `affectedFiles` to locate it.
3. Read the representative issue — `symbolContext` and the code snippet show where to act.
4. Make the smallest safe change, then re-run PHPStan.
5. Confirm the cluster is gone before picking the next one.

When an agent needs JSON instead of TOON (e.g. for structured tool output), set `agentFormat.outputMode: json`.

---

## 🚮 Why this exists

### The slot machine

We take PHPStan output, dump it into CI, copy it into an AI prompt, and then act surprised when the agent starts fixing symptoms instead of the problem.

❌ **bad:**

```bash
vendor/bin/phpstan analyse
```

Then copy 500 lines of terminal output into an AI tool and ask:

```text
please fix
```

Congratulations. You built a slot machine.

✅ **better:**

```bash
vendor/bin/phpstan analyse --error-format=agent
```

Then give the agent structured repair information:

```text
Read the summary.
Pick one cluster.
Understand the root cause.
Make the smallest safe change.
Run PHPStan again.
```

This is not magic. This is basic tooling hygiene.

### The problem with unstructured output

Static analysis output for humans is not automatically good input for agents.

Humans can read noisy CI logs and guess the hidden connection between ten errors. We can say: *"Ah, this is probably one nullable value leaking through the service layer."* Then we fix the source.

Agents are not there yet unless we give them better input.

Without structure, the agent sees this:

```
error
error
error
error
error
```

And then it does this:

```
change
change
change
change
break
```

That is not refactoring. That is automated panic.

### What structured output looks like

❌ **bad** (three separate errors, same root cause, no context):

```
src/UserMailer.php:42  — Parameter #1 $email expects string, string|null given.
src/UserMailer.php:84  — Parameter #1 $email expects string, string|null given.
src/UserRepository.php:129 — Parameter #1 $id expects int, int|null given.
```

Agent reaction: *Make everything nullable?*

No. Bad agent. Sit.

✅ **better** (one cluster, two files, clear repair path):

```
kind: nullable-propagation
rootCauseSummary: Nullable value reaches a non-null expectation.
repairStrategySummary: Constrain nullability earlier or widen the target type if the domain allows it.
affectedFiles:
  - src/UserMailer.php:42
  - src/UserMailer.php:84
  - src/UserRepository.php:129
```

Now we can work.

`voku/phpstan-agent-format` groups related findings, deduplicates repeated symptoms, and includes compact context traces — giving the agent something closer to a repair plan instead of a wall of terminal sadness.

And yes, this is still your job as a developer. The agent should not *decide architecture*. The agent should repair one cluster, with one small change, and then PHPStan should confirm that the problem disappeared.

Same rule as with legacy code: **first think, then change, then check**.

---

## ⚙️ Configure

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

### Configuration options

| Option | Default | Description |
|---|---|---|
| `outputMode` | `toon` | Serializer: `toon`, `json`, `ndjson`, `markdown`, `compact` |
| `maxClusters` | `30` | Maximum number of issue clusters in the report |
| `maxIssuesPerCluster` | `3` | Representative issues shown per cluster |
| `snippetLinesBefore` | `2` | Source context lines before the reported line |
| `snippetLinesAfter` | `3` | Source context lines after the reported line |
| `includeDocblock` | `false` | Include nearby docblocks for extra type context |
| `includeRelatedDefinition` | `true` | Attach related class/method/function definition |
| `tokenBudget` | `12000` | Cap report size; reduction is deterministic when exceeded |
| `redactPatterns` | `[]` | Regex patterns to redact secrets from snippets |

---

## 📋 Output modes

| Mode | Alias | Best for |
|---|---|---|
| `agentToon` | `toon` | Default — token-efficient agent repair loops |
| `agentJson` | `json` | Structured tool output, JSON-consuming agents |
| `agentNdjson` | `ndjson` | Streaming / line-delimited pipelines |
| `agentMarkdown` | `markdown` | Chat-based flows, paste into Copilot/Claude/ChatGPT |
| `agentCompact` | `compact` | Ultra-compact text for tight token budgets |

Switch the mode per workflow:

```neon
parameters:
    agentFormat:
        outputMode: markdown
```

```bash
vendor/bin/phpstan analyse --error-format=agent > phpstan-agent.md
```

Then hand the markdown report to your agent:

```text
Read the phpstan-agent-format report.
Treat each cluster as one underlying problem.
Fix root causes instead of patching duplicates.
Keep changes minimal and preserve behavior.
Rerun PHPStan after each fix.
```

---

## 📐 Envelope shape (TOON)

```text
tool: phpstan-agent-format
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
  6fdafecf6214,nullable-propagation,argument.type,Nullable value reaches a non-null expectation.,Constrain nullability earlier or widen the target type to accept null.,0.7,[3]: src/UserMailer.php:42 src/UserMailer.php:84 src/UserRepository.php:129,[0]:,2
```

## 📐 Envelope shape (JSON)

Representative issues include structured repair hints inside `symbolContext`, including the targeted parameter/property plus expected and inferred types when PHPStan exposes them.

```json
{
  "tool": "phpstan-agent-format",
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
      "affectedFiles": ["src/UserMailer.php:42", "src/UserMailer.php:84", "src/UserRepository.php:129"],
      "representativeIssues": [
        {
          "id": "a1b2c3d4",
          "message": "Parameter #1 $email expects string, string|null given.",
          "ruleIdentifier": "argument.type",
          "location": { "file": "src/UserMailer.php", "line": 42 },
          "symbolContext": {
            "className": "UserMailer",
            "methodName": "send",
            "parameterName": "$email",
            "expectedType": "string",
            "inferredType": "string|null"
          }
        }
      ],
      "suppressedDuplicateCount": 2
    }
  ]
}
```

---

## 🧩 Clustering strategy

First-pass clustering groups by:

- same PHPStan error-identifier family when available, otherwise same rule identifier
- same symbol context when detected
- same file + nearby line bucket
- same type-origin hint

Cluster kinds include:

- 🔴 nullable propagation
- 🟡 missing type declaration
- 🟠 generic/template drift
- 🟣 array shape drift
- 🔵 undefined member from inferred type
- ⚪ invalid offset access
- 🟤 stale ignore/baseline noise
- ⚫ fallback: repeated same-rule same-symbol

---

## 💰 Token budget reduction strategy

When estimated tokens exceed `tokenBudget`, degradation is deterministic and ordered:

1. Remove verbose/secondary details (secondary locations)
2. Shrink snippets to focused lines
3. Reduce representative issues per cluster
4. Keep root cause + repair strategy summaries last

---

## 🔒 Security and privacy

Snippets are redacted using configurable regex patterns before serialization.

```neon
parameters:
    agentFormat:
        redactPatterns:
            - '(?i)password\s*=\s*.+'
            - '(?i)api[_-]?key\s*=\s*.+'
```

---

## 📁 Example outputs

See `/examples/`:

- `agent-toon-example.toon`
- `agent-json-example.json`
- `agent-ndjson-example.ndjson`
- `agent-markdown-example.md`
- `agent-compact-example.txt`

---

## 🔄 Reformat existing JSON exports

If you already produce `phpstan --error-format=json`, reformat that payload without rebuilding your pipeline:

```php
$result = AgentErrorFormatter::formatPhpstanJsonExport($jsonString, $config);
```

The repository CI dogfoods both modes: PHPStan runs once with the default formatter, once with `--error-format=agent`, and again against committed fixture configs that exercise the agent envelope on real output.
