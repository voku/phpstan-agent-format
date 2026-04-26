# Ship PHPStan output that coding agents can actually fix

PHPStan is excellent at finding problems, but its default output is tuned for humans reading a terminal or CI log. Modern coding agents need something different: fewer duplicates, clearer grouping, compact context, and a format that is stable enough to automate.

That is why I built [`voku/phpstan-agent-format`](https://github.com/voku/phpstan-agent-format). It adds a custom PHPStan formatter named `agent` that turns raw findings into deterministic repair envelopes for coding agents.

## The problem

When you hand regular PHPStan output to a coding agent, a few things usually happen:

- repeated errors get fixed one by one instead of at the root cause
- important context is spread across many lines
- the output is noisy for chat-based workflows
- the agent spends tokens on formatting noise instead of on the actual repair

For humans this is manageable. For agents it is wasteful.

## What this package changes

`phpstan-agent-format` keeps PHPStan as the source of truth, but reshapes the output for repair loops:

- related issues are clustered together
- duplicate symptoms are suppressed
- symbol context is preserved
- context traces stay compact
- output can be emitted as JSON, NDJSON, Markdown, or compact text
- token-budget reduction is deterministic when reports get too large

The result is easier to feed into Copilot, Codex, Claude, Cursor, or custom tooling.

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
        outputMode: json
```

Then run PHPStan with the new formatter:

```bash
vendor/bin/phpstan analyse --error-format=agent
```

That is enough to get agent-ready output.

## The easiest workflow with a coding agent

For automation-friendly flows, save JSON:

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

This is simple, but it changes the quality of the repair loop a lot.

## Why the formatter matters

The formatter is not just a different skin on top of PHPStan output. It adds structure that helps an agent make better decisions:

- cluster identifiers keep related issues together
- repair summaries explain the likely direction of the fix
- affected files stay visible without flooding the report
- snippets and related definitions add context without pretending to be a runtime trace

If the report gets too large, the formatter reduces detail in a predictable order instead of failing into random truncation.

## Good defaults, but still configurable

The bundled extension already defines defaults and a schema for `agentFormat`, so you can start with a minimal config and tune later.

Useful options include:

- `outputMode`
- `maxClusters`
- `maxIssuesPerCluster`
- `snippetLinesBefore`
- `snippetLinesAfter`
- `includeDocblock`
- `includeRelatedDefinition`
- `tokenBudget`
- `redactPatterns`

That makes it practical both for local development and for CI pipelines.

## Also useful for existing JSON exports

If you already produce `phpstan --error-format=json`, the package can also reformat that payload through `AgentErrorFormatter::formatPhpstanJsonExport()`.

So you do not need to rebuild your whole workflow just to experiment with agent-friendly output.

## Closing thought

Static analysis is already one of the best inputs we can give a coding agent. The missing piece is often the presentation layer.

`voku/phpstan-agent-format` keeps PHPStan’s analysis intact, but packages it in a form that is easier to automate, easier to paste into chat tools, and easier for an agent to fix correctly.

If you want to try it, start with the README, switch PHPStan to `--error-format=agent`, and run one repair loop against a real project. The difference becomes obvious very quickly.
