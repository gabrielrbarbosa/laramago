# Laramago

[![Packagist](https://img.shields.io/packagist/v/laramago/laramago.svg?style=flat-square)](https://github.com/gabrielrbarbosa/laramago)
[![Packagist Downloads](https://img.shields.io/packagist/dt/laramago/laramago.svg?style=flat-square)](https://github.com/gabrielrbarbosa/laramago)
[![Packagist License](https://img.shields.io/packagist/l/laramago/laramago.svg?style=flat-square)](https://github.com/gabrielrbarbosa/laramago)
[![Laravel Compatibility](https://badge.laravel.cloud/badge/laramago/laramago)](https://packagist.org/packages/laramago/laramago)

## Your Laravel CI should run in seconds, not minutes!

Laravel-aware static analysis for [Mago](https://github.com/carthage-software/mago).

[Buy me a coffee](https://buymeacoffee.com/gabrielrbarbosa)

Laramago is a Composer package that gives Laravel applications a practical migration path from Larastan/PHPStan to Mago. It ships a Laravel runtime preset, a stable `vendor/bin/laramago` command, generated Eloquent model metadata overlays, and a baseline workflow designed for existing applications with real legacy noise.

The goal is simple: keep the developer workflow that teams already use for static analysis, but run it on Mago's fast analyzer without baking one project's strictness level into the package.

## Migration Snapshot

The first production migration target is a private Laravel application. This is a sanitized count-only comparison: repository names, paths, classes, and domain details are intentionally omitted.

| Run | Result |
| --- | --- |
| Plain Mago 1.29 with the application's source config, before Laramago compatibility overlays | 28,980 errors and 10,004 warnings |
| After installing Laramago and running the matching PHPStan/Larastan level gate | No reported issues |

The gap is mostly Laravel framework magic, Eloquent metadata, PHPStan pragma compatibility, excluded legacy symbols, and PHPStan/Larastan level semantics. Laramago's job is to make those migration concerns explicit and reusable, so teams can evaluate the real remaining analyzer findings instead of sorting through framework noise.

## Status

Mago 1.29 does not expose a Composer-loaded analyzer extension API equivalent to PHPStan's extension system. That means Laramago is not a direct port of every Larastan internal rule. Instead, it provides the production-safe replacement layer that can be built on top of Mago today:

- a Laravel-oriented runtime Mago preset managed by Laramago;
- generated Eloquent PHPDoc overlays from real application model metadata;
- generated Laravel framework overlays for application-specific auth model types;
- generated symbol stubs for excluded legacy application paths, so references stay resolvable without analyzing those files;
- Composer `autoload` and `autoload-dev` PSR-4/classmap paths included for type discovery, so classes outside `app` such as seeders stay resolvable;
- a default Laravel compatibility profile that filters noisy mixed-data diagnostics common in Eloquent, request, resource, and legacy payload workflows, while leaving Mago's dead-code checks opt-in to match normal Larastan/PHPStan gates;
- baseline usage for existing applications that still have analyzer debt or want to migrate gradually;
- path translation so diagnostics point back to application files instead of generated cache files;
- Composer commands that can replace existing `phpstan` scripts;
- a small public surface that can absorb native Mago extension hooks when Mago exposes them.

## Installation

```bash
composer require --dev laramago/laramago
```

Laramago requires PHP 8.3 or newer, declares Laravel 13 compatibility through `illuminate/support:^13.0`, and installs `carthage-software/mago` as its analyzer runtime.

## Quick Start

Generate the project source configuration:

```bash
vendor/bin/laramago init
```

Or migrate the source paths and excluded paths from an existing PHPStan/Larastan config:

```bash
vendor/bin/laramago migrate-phpstan
```

Run analysis:

```bash
vendor/bin/laramago analyze --reporting-format=count
```

To mimic an existing PHPStan/Larastan gate during migration, opt in explicitly:

```bash
vendor/bin/laramago analyze --phpstan-level=6 --reporting-format=count
```

The `--phpstan-level=0..10|max` profile keeps Laramago level agnostic, while filtering Mago diagnostics that are outside the requested Larastan/PHPStan migration gate. Level 6 is common for existing Laravel applications, but the option is not tied to one project.

For existing applications, create a baseline only when Mago reports issues that are not part of the migration scope yet:

```bash
vendor/bin/laramago baseline --phpstan-level=6
```

Then run analysis normally:

```bash
vendor/bin/laramago analyze --phpstan-level=6
```

When `laramago-analyzer-baseline.toml` exists, `laramago analyze` automatically passes it to Mago. When no baseline exists, analysis runs unbaselined. Laramago also writes a generated runtime config to `.laramago/cache/mago.toml` and passes that file to Mago, so application repositories only need to keep project-specific source settings in `mago.toml`.

## Laravel Model Overlays

Laramago generates temporary Eloquent model, Laravel framework, excluded-symbol, and PHPStan pragma overlays so Mago can understand common Laravel application code without modifying the source tree.

See [Laravel Model Overlays](docs/laravel-model-overlays.md) for the full discovery process, generated metadata, cache behavior, and raw-Mago opt-out flags.

## Commands

```bash
vendor/bin/laramago init [--force] [--source=app] [--exclude=path/**]
vendor/bin/laramago migrate-phpstan [--force] [--phpstan-config=phpstan.neon] [--update-composer]
vendor/bin/laramago prepare
vendor/bin/laramago analyze [--phpstan-level=0..10|max] [--find-unused-definitions] [--no-phpstan-pragma-overlays] [mago analyze options] [path ...]
vendor/bin/laramago baseline [--force] [--phpstan-level=0..10|max] [--find-unused-definitions]
vendor/bin/laramago verify-baseline [--phpstan-level=0..10|max] [--find-unused-definitions]
vendor/bin/laramago doctor
vendor/bin/laramago count [path ...]
vendor/bin/laramago codes [path ...]
vendor/bin/laramago clear
```

### `init`

Writes a minimal `mago.toml` with project source settings. Existing configs are preserved unless `--force` is provided.

Laramago does not exclude application paths by default. Add `--exclude=path/**` only for project-specific legacy areas that should be omitted from analysis.

### `migrate-phpstan`

Reads a PHPStan/Larastan NEON file and writes the equivalent Laramago `mago.toml` source settings. It imports common `parameters.paths`, type-discovery inputs such as `scanDirectories`, `scanFiles`, `bootstrapFiles`, and `stubFiles`, flat `parameters.excludePaths`, nested `parameters.excludePaths.analyse` / `parameters.excludePaths.analyseAndScan`, scoped `ignoreErrors` identifiers, and numeric or `max` levels so it can print the matching explicit `--phpstan-level` migration command. Local NEON files referenced from `includes` are read recursively; vendor extension includes such as Larastan's own extension file are skipped.

By default it searches `phpstan.neon`, `phpstan.neon.dist`, `phpstan-ci.neon`, and `phpstan-parallel.neon`. Use `--phpstan-config=path/to/phpstan.neon` for a custom file.

Add `--update-composer` to rewrite the common `phpstan`, `phpstan:ci`, and `phpstan:ci:debug` Composer scripts to Laramago commands and add a `laramago:baseline` script. It also replaces direct PHPStan `analyse`/`analyze` commands in custom Composer scripts while preserving aliases such as `@phpstan` and unrelated scripts.

### `prepare`

Builds Laravel model overlays without running analysis. This is useful when debugging generated metadata.

### `analyze`

Runs `mago analyze` with Laramago's generated runtime config, model overlays, and the project baseline when present.

When multiple Laramago commands run in the same project, `analyze` waits for the project lock before preparing overlays and invoking Mago. This keeps CI logs reliable even when Composer scripts or local terminals overlap.

`analyze`, `baseline`, and `verify-baseline` fail before invoking Mago when the configured source paths contain no PHP files and no explicit PHP target path was provided. This prevents a misconfigured CI job from passing after analyzing nothing.

By default Laramago disables Mago's unused-definition pass because Larastan/PHPStan level gates do not normally fail Laravel applications for unused app methods and properties. Pass `--find-unused-definitions` when you intentionally want that stricter Mago dead-code signal.

### `baseline`

Generates `laramago-analyzer-baseline.toml`. With model overlays enabled, the committed baseline uses real application paths, not cache paths.

### `verify-baseline`

Runs Mago baseline verification using the same Laramago runtime mapping as `analyze`.

If the baseline was generated for a PHPStan/Larastan migration level, pass the same `--phpstan-level` value when verifying it. For example, a baseline generated with `vendor/bin/laramago baseline --phpstan-level=6` should be verified with `vendor/bin/laramago verify-baseline --phpstan-level=6`.

### `doctor`

Checks whether Mago is installed, `mago.toml` exists, the baseline exists, Laravel can be detected, and model overlays can be prepared.

### `clear`

Removes `.laramago/cache`.

## CI

A typical CI static-analysis lane only needs:

```bash
composer install --no-interaction --prefer-dist
vendor/bin/laramago analyze --phpstan-level=6 --reporting-format=count
```

Commit this file:

```text
mago.toml
```

Commit `laramago-analyzer-baseline.toml` only if your migration still needs a baseline.

Ignore generated cache files:

```text
/.laramago/
```

## Example Migration

1. Remove Larastan/PHPStan-only dev dependencies when no other tool needs them.
2. Install `laramago/laramago`.
3. Run `vendor/bin/laramago migrate-phpstan` or `vendor/bin/laramago init`.
4. Run `vendor/bin/laramago baseline --phpstan-level=6` if you are migrating a level 6 Larastan gate, or use the level from your existing PHPStan config.
5. Replace the old `phpstan` Composer script with `vendor/bin/laramago analyze --phpstan-level=6 --reporting-format=count` when migrating a level 6 gate.
6. Run `composer test`.

## Configuration

The committed `mago.toml` should stay small and project-specific:

- source path: `app`;
- vendor included for type discovery;
- app-specific excluded paths, if needed.

Laramago owns Laravel integration, not your project's strictness level. During `analyze`, `baseline`, and `verify-baseline`, it generates `.laramago/cache/mago.toml` with:

- Laravel linter integration enabled;
- Pint-compatible formatter defaults;
- analyzer settings suitable for Laravel applications, including Laravel dynamic-data compatibility ignores for mixed request, model, collection, and payload flows, with unused-definition checks disabled unless `--find-unused-definitions` is passed;
- excluded-path symbol stubs added to runtime includes when project excludes are present;
- the project source settings copied from the committed `mago.toml`.

Laramago's default analyzer profile intentionally suppresses Laravel dynamic-data diagnostics that Mago cannot model without framework-specific analyzer extensions yet, while keeping concrete type mismatches and control-flow issues visible. It also leaves unused-definition detection off by default for PHPStan/Larastan parity. Use `laramago-analyzer-baseline.toml`, `--find-unused-definitions`, project `mago.toml` settings, or Mago flags such as `--minimum-fail-level` and `--minimum-report-level` for project-specific debt that is outside the shared Laravel compatibility profile.

The `--phpstan-level` option is an explicit migration preset for projects that previously used a PHPStan/Larastan level gate. It is opt-in so Laramago stays level agnostic for new projects and stricter teams. `--phpstan-level=max` follows PHPStan's highest level, while running without `--phpstan-level` keeps Mago's native strictness.

You can pass additional Mago flags directly through `laramago analyze`.

## Current Limits

Laramago is built around Mago 1.29. Until Mago exposes a native analyzer extension API, some Larastan behaviors cannot be reproduced exactly. The most important compatibility layer today is Eloquent model metadata, which Laramago handles through generated overlays.

When Mago adds native extension points, Laramago should move Laravel-specific analyzer logic from generated overlays into first-class analyzer plugins.

## License

Laramago is open-sourced software licensed under the MIT license.
