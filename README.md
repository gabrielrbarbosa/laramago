# Laramago

[![Packagist](https://img.shields.io/packagist/v/laramago/laramago.svg?style=flat-square)](https://github.com/gabrielrbarbosa/laramago)
[![Packagist Downloads](https://img.shields.io/packagist/dt/laramago/laramago.svg?style=flat-square)](https://github.com/gabrielrbarbosa/laramago)
[![Packagist License](https://img.shields.io/packagist/l/laramago/laramago.svg?style=flat-square)](https://github.com/gabrielrbarbosa/laramago)
[![Laravel Compatibility](https://badge.laravel.cloud/badge/laramago/laramago)](https://packagist.org/packages/laramago/laramago)

## Your Laravel CI should run in seconds, not minutes!

Laravel-aware static analysis for [Mago](https://github.com/carthage-software/mago).

[Buy me a coffee](https://buymeacoffee.com/gabrielrbarbosa)

Laramago is a Composer package that gives Laravel applications a practical migration path from Larastan/PHPStan to Mago. It ships a Laravel runtime preset, a stable `vendor/bin/laramago` command, generated Eloquent model metadata overlays, and a baseline workflow designed for existing applications with real legacy noise.

The goal is simple: keep the developer workflow that teams already use for static analysis, but run it on Mago's fast analyzer.

## Status

Mago 1.29 does not expose a Composer-loaded analyzer extension API equivalent to PHPStan's extension system. That means Laramago is not a direct port of every Larastan internal rule. Instead, it provides the production-safe replacement layer that can be built on top of Mago today:

- a Laravel-oriented runtime Mago preset managed by Laramago;
- generated Eloquent PHPDoc overlays from real application model metadata;
- generated Laravel framework overlays for application-specific auth model types;
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

Generate the project source configuration:

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

When `laramago-analyzer-baseline.toml` exists, `laramago analyze` automatically passes it to Mago. Laramago also writes a generated runtime config to `.laramago/cache/mago.toml` and passes that file to Mago, so application repositories only need to keep project-specific source settings in `mago.toml`.

## Laravel Model Overlays

Larastan understands many Eloquent conventions through PHPStan extensions. Mago does not currently load equivalent Laravel analyzer extensions, so Laramago generates temporary model overlays instead.

During `analyze`, `baseline`, and `verify-baseline`, Laramago:

1. boots the Laravel application;
2. discovers Eloquent models in `app/Models`;
3. reads database columns, casts, accessors, local scopes, and public relation methods;
4. reads `config/auth.php` to detect the application auth user model;
5. writes generated files to `.laramago/cache/model-overlays` and `.laramago/cache/framework-overlays`;
6. passes those files to Mago through `--substitute`;
7. translates baseline and diagnostic paths back to the original app files.

The application source tree is not modified.

Generated overlays currently add:

- `@property` entries for database columns with cast-aware types;
- `@property-read` entries for legacy `getFooAttribute()` accessors and `Attribute` accessors;
- `@property-read` entries for Eloquent relations;
- `@method static` entries for classic `scopeFoo()` local scopes and Laravel `#[Scope]` methods;
- auth guard and facade return types for the configured Laravel user model;
- baseline and output path translation so generated overlay paths do not leak into application diagnostics.

Disable overlays when you want raw Mago behavior:

```bash
vendor/bin/laramago analyze --no-laravel-model-overlays
```

Disable Laravel framework overlays when you want raw framework vendor types:

```bash
vendor/bin/laramago analyze --no-laravel-framework-overlays
```

## Commands

```bash
vendor/bin/laramago init [--force] [--source=app] [--exclude=path/**]
vendor/bin/laramago prepare
vendor/bin/laramago analyze [mago analyze options] [path ...]
vendor/bin/laramago baseline [--force]
vendor/bin/laramago verify-baseline
vendor/bin/laramago doctor
vendor/bin/laramago count [path ...]
vendor/bin/laramago codes [path ...]
vendor/bin/laramago clear
```

### `init`

Writes a minimal `mago.toml` with project source settings. Existing configs are preserved unless `--force` is provided.

### `prepare`

Builds Laravel model overlays without running analysis. This is useful when debugging generated metadata.

### `analyze`

Runs `mago analyze` with Laramago's generated runtime config, model overlays, and the project baseline when present.

### `baseline`

Generates `laramago-analyzer-baseline.toml`. With model overlays enabled, the committed baseline uses real application paths, not cache paths.

### `verify-baseline`

Runs Mago baseline verification using the same Laramago runtime mapping as `analyze`.

### `doctor`

Checks whether Mago is installed, `mago.toml` exists, the baseline exists, Laravel can be detected, and model overlays can be prepared.

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

The committed `mago.toml` should stay small and project-specific:

- source path: `app`;
- vendor included for type discovery;
- app-specific excluded paths, if needed.

Laramago owns the compatibility policy. During `analyze`, `baseline`, and `verify-baseline`, it generates `.laramago/cache/mago.toml` with:

- Laravel linter integration enabled;
- Pint-compatible formatter defaults;
- analyzer settings suitable for legacy Laravel applications;
- noisy mixed-type analyzer codes ignored for application code;
- the project source settings copied from the committed `mago.toml`.

You can pass additional Mago flags directly through `laramago analyze`.

## Current Limits

Laramago is built around Mago 1.29. Until Mago exposes a native analyzer extension API, some Larastan behaviors cannot be reproduced exactly. The most important compatibility layer today is Eloquent model metadata, which Laramago handles through generated overlays.

When Mago adds native extension points, Laramago should move Laravel-specific analyzer logic from generated overlays into first-class analyzer plugins.

## License

Laramago is open-sourced software licensed under the MIT license.
