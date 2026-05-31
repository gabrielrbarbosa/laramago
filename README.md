# Laramago

Laravel-aware static analysis for [Mago](https://github.com/carthage-software/mago).

Laramago is a Composer package that gives Laravel applications a practical migration path from Larastan/PHPStan to Mago. It ships a Laravel preset, a stable `vendor/bin/laramago` command, generated Eloquent model metadata overlays, and a baseline workflow designed for existing applications with real legacy noise.

The goal is simple: keep the developer workflow that teams already use for static analysis, but run it on Mago's fast analyzer.

## Status

Mago 1.29 does not expose a Composer-loaded analyzer extension API equivalent to PHPStan's extension system. That means Laramago is not a direct port of every Larastan internal rule. Instead, it provides the production-safe replacement layer that can be built on top of Mago today:

- a Laravel-oriented `mago.toml` preset;
- generated Eloquent PHPDoc overlays from real application model metadata;
- automatic baseline usage for existing projects;
- path translation so diagnostics point back to application files instead of generated cache files;
- Composer commands that can replace existing `phpstan` scripts;
- a small public surface that can absorb native Mago extension hooks when Mago exposes them.

## Installation

```bash
composer require --dev laramago/laramago
```

Laramago requires PHP 8.2 or newer and installs `carthage-software/mago` as its analyzer runtime.

## Quick Start

Generate the default Laravel/Mago configuration:

```bash
vendor/bin/laramago init
```

Create a baseline for the current codebase:

```bash
vendor/bin/laramago baseline
```

Run analysis:

```bash
vendor/bin/laramago analyze
```

When `laramago-analyzer-baseline.toml` exists, `laramago analyze` automatically passes it to Mago.

## Laravel Model Overlays

Larastan understands many Eloquent conventions through PHPStan extensions. Mago does not currently load equivalent Laravel analyzer extensions, so Laramago generates temporary model overlays instead.

During `analyze`, `baseline`, and `verify-baseline`, Laramago:

1. boots the Laravel application;
2. discovers Eloquent models in `app/Models`;
3. reads database columns, casts, and public relation methods;
4. writes generated files to `.laramago/cache/model-overlays`;
5. passes those files to Mago through `--substitute`;
6. translates baseline and diagnostic paths back to the original app files.

The application source tree is not modified.

Disable overlays when you want raw Mago behavior:

```bash
vendor/bin/laramago analyze --no-laravel-model-overlays
```

## Commands

```bash
vendor/bin/laramago init [--force] [--source=app] [--exclude=path/**]
vendor/bin/laramago prepare
vendor/bin/laramago analyze [mago analyze options] [path ...]
vendor/bin/laramago baseline [--force]
vendor/bin/laramago verify-baseline
vendor/bin/laramago count [path ...]
vendor/bin/laramago codes [path ...]
vendor/bin/laramago clear
```

### `init`

Writes `mago.toml` with Laravel-friendly defaults. Existing configs are preserved unless `--force` is provided.

### `prepare`

Builds Laravel model overlays without running analysis. This is useful when debugging generated metadata.

### `analyze`

Runs `mago analyze` with Laramago defaults, model overlays, and the project baseline when present.

### `baseline`

Generates `laramago-analyzer-baseline.toml`. With model overlays enabled, the committed baseline uses real application paths, not cache paths.

### `verify-baseline`

Runs Mago baseline verification using the same Laramago runtime mapping as `analyze`.

### `clear`

Removes `.laramago/cache`.

## Replacing Larastan/PHPStan

Keep your existing Composer script names if your team and CI already call them:

```json
{
  "scripts": {
    "phpstan": "vendor/bin/laramago analyze --reporting-format=count",
    "phpstan:ci": "vendor/bin/laramago analyze --reporting-format=count",
    "phpstan:ci:debug": "vendor/bin/laramago analyze --reporting-format=short",
    "laramago:baseline": "vendor/bin/laramago baseline"
  }
}
```

Then keep `composer test` unchanged if it already calls `@phpstan`.

## CI

A typical CI static-analysis lane only needs:

```bash
composer install --no-interaction --prefer-dist
vendor/bin/laramago analyze --reporting-format=count
```

Commit these files:

```text
mago.toml
laramago-analyzer-baseline.toml
```

Ignore generated cache files:

```text
/.laramago/
```

## Example Migration

1. Remove Larastan/PHPStan-only dev dependencies when no other tool needs them.
2. Install `laramago/laramago`.
3. Run `vendor/bin/laramago init`.
4. Run `vendor/bin/laramago baseline`.
5. Replace the old `phpstan` Composer script with `vendor/bin/laramago analyze --reporting-format=count`.
6. Run `composer test`.

## Configuration

The generated `mago.toml` starts with Laravel-friendly defaults:

- source path: `app`;
- vendor included for type discovery;
- Laravel linter integration enabled;
- noisy mixed-type analyzer codes ignored for existing Laravel applications;
- baseline-driven analyzer workflow.

You can pass additional Mago flags directly through `laramago analyze`.

## Current Limits

Laramago is built around Mago 1.29. Until Mago exposes a native analyzer extension API, some Larastan behaviors cannot be reproduced exactly. The most important compatibility layer today is Eloquent model metadata, which Laramago handles through generated overlays.

When Mago adds native extension points, Laramago should move Laravel-specific analyzer logic from generated overlays into first-class analyzer plugins.

## License

Laramago is open-sourced software licensed under the MIT license.
