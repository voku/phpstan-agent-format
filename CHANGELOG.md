# Changelog

All notable changes to `voku/phpstan-agent-format` will be documented in this file.

## [Upcoming]

### Added

- Initial release of `voku/phpstan-agent-format` with a custom PHPStan `agent` formatter that groups related findings into compact repair envelopes for coding agents.
- Multiple output modes for agent consumers, including TOON, JSON, NDJSON, Markdown, and compact text, with formatter defaults exposed through `extension.neon`.
- Structured v2 envelope metadata, including explicit schema information, representative issue context, symbol hints, snippets, and context traces.
- Support for reformatting prior PHPStan `--error-format=json` exports through `AgentErrorFormatter::formatPhpstanJsonExport()`.

### Changed

- Default formatter output now uses TOON for better token efficiency in coding-agent repair loops.
- Clustering and issue normalization now prefer richer PHPStan identifier, symbol, and type-origin context to produce more stable repair summaries.

### Fixed

- Generic type detection now handles identifiers that include digits.
- Metadata normalization and sparse issue enrichment now preserve nested metadata while recovering symbol context more reliably.
