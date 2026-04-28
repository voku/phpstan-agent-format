# Changelog

All notable changes to `voku/phpstan-agent-format` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-04-28

### Added

- Initial release of `voku/phpstan-agent-format`.
- Added a custom PHPStan error formatter named `agent`.
- Added compact, deterministic repair envelopes for coding agents.
- Added clustering for related PHPStan findings to reduce repeated symptom-fixing.
- Added structured repair metadata, including:
    - schema information
    - representative issue context
    - symbol hints
    - source snippets
    - context traces
    - root-cause summaries
    - repair-strategy summaries
- Added multiple output modes for different agent and CI workflows:
    - TOON
    - JSON
    - NDJSON
    - Markdown
    - compact text
- Added default formatter configuration through `extension.neon`.
- Added support for reformatting existing PHPStan `--error-format=json` exports through
  `AgentErrorFormatter::formatPhpstanJsonExport()`.
- Added richer issue normalization based on PHPStan identifiers, symbol context, and type-origin hints.
- Added metadata normalization for sparse PHPStan issues while preserving nested metadata.
- Added support for detecting generic type identifiers that contain digits.
