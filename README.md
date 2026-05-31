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

## Status

Mago 1.29 does not expose a Composer-loaded analyzer extension API equivalent to PHPStan's extension system. That means Laramago is not a direct port of every Larastan internal rule. Instead, it provides the production-safe replacement layer that can be built on top of Mago today:

- a Laravel-oriented runtime Mago preset managed by Laramago;
- generated Eloquent PHPDoc overlays from real application model metadata;
- generated Laravel framework overlays for application-specific auth model types;
- generated symbol stubs for excluded legacy application paths, so references stay resolvable without analyzing those files;
- Composer `autoload` and `autoload-dev` PSR-4/classmap paths included for type discovery, so classes outside `app` such as seeders stay resolvable;
- baseline usage for existing applications that already have analyzer debt or want to migrate gradually;
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

Or migrate the source paths and excluded paths from an existing PHPStan/Larastan config:

```bash
vendor/bin/laramago migrate-phpstan
```

Run analysis:

```bash
vendor/bin/laramago analyze --reporting-format=count
```

To mimic a PHPStan/Larastan level 6 gate during migration, opt in explicitly:

```bash
vendor/bin/laramago analyze --phpstan-level=6 --reporting-format=count
```

The `--phpstan-level=6` profile keeps Laramago's default analysis strict, while filtering Mago diagnostics that are outside a typical Larastan/PHPStan level 6 migration gate.

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

Larastan understands many Eloquent conventions through PHPStan extensions. Mago does not currently load equivalent Laravel analyzer extensions, so Laramago generates temporary model overlays instead.

During `analyze`, `baseline`, and `verify-baseline`, Laramago:

1. boots the Laravel application;
2. discovers Eloquent models in the configured project source paths, including `app/Models` and domain/module model folders;
3. reads database columns, Laravel casts, enum casts, accessors, local scopes, and public relation methods;
4. reads `config/auth.php` to detect the application auth user model;
5. reads Composer `autoload` and `autoload-dev` PSR-4/classmap paths for type discovery outside analyzed source paths;
6. writes generated files to `.laramago/cache/model-overlays` and `.laramago/cache/framework-overlays`;
7. writes lightweight symbol stubs for excluded application paths to `.laramago/cache/excluded-symbols`;
8. translates existing PHPStan suppression comments into temporary Mago pragma overlays;
9. passes overlays to Mago through `--substitute` and includes excluded symbol stubs for type discovery;
10. translates baseline and diagnostic paths back to the original app files.

The application source tree is not modified.

Laramago serializes cache-producing commands per project, so overlapping `analyze`, `doctor`, `prepare`, `baseline`, `verify-baseline`, and `clear` invocations cannot mutate the same `.laramago/cache` files at the same time.

Generated overlays currently add:

- `@property` entries for database columns with cast-aware types, including encrypted casts, collection/array object casts, date casts, and enum casts;
- `@property-read` entries for legacy `getFooAttribute()` accessors and `Attribute` accessors;
- `@property-read` entries for Eloquent relations, including through, polymorphic, and many-to-many collection relations;
- `@method static` entries for common Eloquent builder calls such as `where`, `with`, `withCount`, `get`, `pluck`, `count`, `create`, `find`, and `findOrFail`;
- merged generated metadata with existing model class PHPDoc, so project annotations such as `@mixin`, `@method`, `@property`, and generic hints stay visible;
- `createToken` return types for models using Laravel Sanctum's `HasApiTokens` trait;
- `@method static` entries for classic `scopeFoo()` local scopes and Laravel `#[Scope]` methods;
- Laravel `HasFactory` return types without app-level generic boilerplate;
- Laravel `Scope` and Laravel Excel `FromCollection` compatibility without app-level generic boilerplate;
- auth guard and facade return types for the configured Laravel user model;
- Composer autoload and autoload-dev type discovery for application namespaces that live outside the analyzed source paths;
- excluded-path symbol discovery so `exclude` can omit legacy code from analysis without turning referenced classes into false missing-class errors;
- PHPStan suppression pragma compatibility for `@phpstan-ignore`, `@phpstan-ignore-next-line`, and `@phpstan-ignore-line` comments through generated temporary overlays;
- baseline and output path translation so generated overlay paths do not leak into application diagnostics.

Disable overlays when you want raw Mago behavior:

```bash
vendor/bin/laramago analyze --no-laravel-model-overlays
```

Disable Laravel framework overlays when you want raw framework vendor types:

```bash
vendor/bin/laramago analyze --no-laravel-framework-overlays
```

Disable PHPStan pragma compatibility when you want raw Mago suppression behavior:

```bash
vendor/bin/laramago analyze --no-phpstan-pragma-overlays
```

## Commands

```bash
vendor/bin/laramago init [--force] [--source=app] [--exclude=path/**]
vendor/bin/laramago migrate-phpstan [--force] [--phpstan-config=phpstan.neon] [--update-composer]
vendor/bin/laramago prepare
vendor/bin/laramago analyze [--phpstan-level=6] [--no-phpstan-pragma-overlays] [mago analyze options] [path ...]
vendor/bin/laramago baseline [--force] [--phpstan-level=6]
vendor/bin/laramago verify-baseline
vendor/bin/laramago doctor
vendor/bin/laramago count [path ...]
vendor/bin/laramago codes [path ...]
vendor/bin/laramago clear
```

### `init`

Writes a minimal `mago.toml` with project source settings. Existing configs are preserved unless `--force` is provided.

Laramago does not exclude application paths by default. Add `--exclude=path/**` only for project-specific legacy areas that should be omitted from analysis.

### `migrate-phpstan`

Reads a PHPStan/Larastan NEON file and writes the equivalent Laramago `mago.toml` source settings. It imports common `parameters.paths`, flat `parameters.excludePaths`, nested `parameters.excludePaths.analyse` / `parameters.excludePaths.analyseAndScan`, and detects level 6 so it can print the matching explicit `--phpstan-level=6` migration command.

By default it searches `phpstan.neon`, `phpstan.neon.dist`, `phpstan-ci.neon`, and `phpstan-parallel.neon`. Use `--phpstan-config=path/to/phpstan.neon` for a custom file.

Add `--update-composer` to rewrite the common `phpstan`, `phpstan:ci`, and `phpstan:ci:debug` Composer scripts to Laramago commands and add a `laramago:baseline` script. It also replaces direct PHPStan `analyse`/`analyze` commands in custom Composer scripts while preserving aliases such as `@phpstan` and unrelated scripts.

### `prepare`

Builds Laravel model overlays without running analysis. This is useful when debugging generated metadata.

### `analyze`

Runs `mago analyze` with Laramago's generated runtime config, model overlays, and the project baseline when present.

When multiple Laramago commands run in the same project, `analyze` waits for the project lock before preparing overlays and invoking Mago. This keeps CI logs reliable even when Composer scripts or local terminals overlap.

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
    "phpstan": "vendor/bin/laramago analyze --phpstan-level=6 --reporting-format=count",
    "phpstan:ci": "vendor/bin/laramago analyze --phpstan-level=6 --reporting-format=count",
    "phpstan:ci:debug": "vendor/bin/laramago analyze --phpstan-level=6 --reporting-format=short",
    "laramago:baseline": "vendor/bin/laramago baseline --phpstan-level=6"
  }
}
```

Then keep `composer test` unchanged if it already calls `@phpstan`.

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
4. Run `vendor/bin/laramago baseline --phpstan-level=6` if you are migrating a level 6 Larastan gate.
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
- analyzer settings suitable for legacy Laravel applications;
- excluded-path symbol stubs added to runtime includes when project excludes are present;
- the project source settings copied from the committed `mago.toml`.

Analyzer issue codes are not globally ignored by the package. Use `laramago-analyzer-baseline.toml`, project `mago.toml` settings, or Mago flags such as `--minimum-fail-level` and `--minimum-report-level` to choose the strictness that fits your team.

The `--phpstan-level=6` option is an explicit migration preset for projects that previously used a PHPStan/Larastan level 6 gate. It is opt-in so Laramago stays level agnostic for new projects and stricter teams.

You can pass additional Mago flags directly through `laramago analyze`.

## Current Limits

Laramago is built around Mago 1.29. Until Mago exposes a native analyzer extension API, some Larastan behaviors cannot be reproduced exactly. The most important compatibility layer today is Eloquent model metadata, which Laramago handles through generated overlays.

When Mago adds native extension points, Laramago should move Laravel-specific analyzer logic from generated overlays into first-class analyzer plugins.

## License

Laramago is open-sourced software licensed under the MIT license.
