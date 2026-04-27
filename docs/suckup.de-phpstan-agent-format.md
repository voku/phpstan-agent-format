# Stop Feeding AI Agents Console Garbage

## Why do we give AI coding agents unreadable output?

I think we are mostly lazy.

We already know this problem from code. We name things after database columns, APIs, framework internals, or whatever random structure was nearby when we wrote the first version. Then five years later somebody has to understand `getUser()`, `processData()`, `$array2use`, or some other little crime scene. The existing SUCKUP articles already say the important part: code should describe the current situation, not the accidental implementation detail.

And now we do the same thing with tools.

We take PHPStan output, dump it into CI, copy it into an AI prompt, and then act surprised when the agent starts fixing symptoms instead of the problem.

**bad:**

```bash
vendor/bin/phpstan analyse
```

Then copy 500 lines of terminal output into an AI tool and ask:

```text
please fix
```

Congratulations. You built a slot machine.

**better:**

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

---

Static analysis is already useful because it prevents stupid bugs before runtime. Use types. Use PHPStan. Use automation. Do not wait until production explains your mistake to the customer. This has been the point for years: prevent bugs, automate refactoring, use constants/classes/properties instead of random strings, and stop making the code harder to analyze than necessary.

But static analysis output for humans is not automatically good input for agents.

Humans can read noisy CI logs and guess the hidden connection between ten errors. We can say: "Ah, this is probably one nullable value leaking through the service layer." Then we fix the source.

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

---

[`voku/phpstan-agent-format`](https://github.com/voku/phpstan-agent-format) fixes exactly this boring but important problem. It adds a PHPStan formatter called `agent`, which emits compact deterministic repair envelopes for coding agents. The default output uses TOON, so the agent does not waste half its context window reading decorative JSON brackets like it is paid by the token.

It groups related findings.

It deduplicates repeated symptoms.

It includes compact context traces.

It gives the agent something closer to a repair plan instead of a wall of terminal sadness.

**bad:**

```
Line 42: Parameter #1 expects string, string|null given.
Line 84: Parameter #1 expects string, string|null given.
Line 129: Parameter #1 expects string, string|null given.
```

Agent reaction: *Make everything nullable?*

No. Bad agent. Sit.

**better:**

```
kind: nullable-propagation
rootCauseSummary: Nullable value reaches a non-null expectation.
repairStrategySummary: Constrain nullability earlier or widen the target type if the domain allows it.
affectedFiles: src/UserMailer.php
```

Now we can work.

---

And yes, this is still your job as a developer. The agent should not "decide architecture". The agent should repair one cluster, with one small change, and then PHPStan should confirm that the problem disappeared.

Same rule as with legacy code:

First think.

Then change.

Then check.

Not the other way around.

## Five-minute setup

Install the package:

```bash
composer require --dev voku/phpstan-agent-format
```

Enable the bundled PHPStan extension:

```neon
includes:
    - vendor/voku/phpstan-agent-format/extension.neon

parameters:
    level: max
    paths:
        - src

    agentFormat:
        outputMode: toon
```

Then run PHPStan with the new formatter:

```bash
vendor/bin/phpstan analyse --error-format=agent
```

That is enough to get agent-ready output. The default TOON mode is already optimized for token-efficient repair loops.

## Workflow

For automation-friendly flows, the default TOON output is a good first choice:

```bash
vendor/bin/phpstan analyse --error-format=agent > phpstan-agent.toon
```

If a tool specifically expects standard JSON, switch the formatter explicitly:

```neon
parameters:
    agentFormat:
        outputMode: json
```

```bash
vendor/bin/phpstan analyse --error-format=agent > phpstan-agent.json
```

For chat-based flows, switch to markdown and save a file you can paste or attach:

```neon
parameters:
    agentFormat:
        outputMode: markdown
```

```bash
vendor/bin/phpstan analyse --error-format=agent > phpstan-agent.md
```

Then hand the report to your coding agent with instructions like this:

```text
Read the phpstan-agent-format report first.
Treat each cluster as one underlying problem.
Fix root causes instead of patching duplicates.
Keep changes minimal and preserve behavior.
Rerun PHPStan after each fix.
```

## Good defaults, but still configurable

The bundled extension already defines defaults and a schema for `agentFormat`, so you can start with a minimal config and tune later.

Useful options include:

- `outputMode` — chooses the serializer, with TOON as the default and JSON/Markdown/NDJSON/compact text available for other workflows.
- `maxClusters` — limits how many issue groups are kept in the final report.
- `maxIssuesPerCluster` — controls how many representative issues are shown for each cluster.
- `snippetLinesBefore` — adds a configurable amount of source context before the reported line.
- `snippetLinesAfter` — adds a configurable amount of source context after the reported line.
- `includeDocblock` — includes nearby docblocks when that extra type/context information is useful.
- `includeRelatedDefinition` — attaches the related class, method, or function definition when available.
- `tokenBudget` — caps the report size so large outputs are reduced deterministically instead of growing without bound.
- `redactPatterns` — removes secrets or sensitive values from snippets with regular-expression based redaction.

## Also useful for existing JSON exports

If you already produce `phpstan --error-format=json`, the package can also reformat that payload through `AgentErrorFormatter::formatPhpstanJsonExport()`.

So you do not need to rebuild your whole workflow just to experiment with agent-friendly output.
