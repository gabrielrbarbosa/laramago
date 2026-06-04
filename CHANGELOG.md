# Changelog

## v0.2.6

- Accepted Larastan-style `HasFactory<UserFactory>` trait annotations by making the generated HasFactory overlay generic.
- Added concrete Eloquent `whereLike` and `orWhereLike` chain methods to reduce query builder return-type false positives.
- Narrowed simple `throw_unless($value instanceof Type, ...)` and `throw_if(! ($value instanceof Type), ...)` guards in source compatibility overlays.
- Excluded Laravel IDE helper stubs such as `_ide_helper*.php`, `.phpstorm.meta.php`, and Laravel Idea helper directories from runtime analysis without reintroducing them as excluded-symbol stubs.

## v0.2.5

- Enabled missing parameter and return type-hint checks in the runtime analyzer profile.
- Made default analysis fail on warning-level diagnostics while preserving PHPStan-level compatibility ignores for Mago-only imprecise array and constant-type warnings.

## v0.2.4

- Drained Mago proxy warmup output while materializing the native binary so first-run downloads cannot deadlock on progress output.

## v0.2.3

- Materialized Mago's native binary before large overlay substitution runs so projects do not fall back to the Composer PHP proxy for full analysis.
- Skipped missing PHPStan discovery include paths during migration, avoiding stale `bootstrap.php` entries in generated `mago.toml` files.

## v0.2.2

- Updated the bundled Mago runtime constraint to 1.30.
- Wrote generated Eloquent model overlay sources with a non-PHP cache-file extension so IDEs do not index them as duplicate application class definitions.

## v0.2.1

- Hardened Mago option parsing so separate option values, such as reporting formats, are not mistaken for source paths when building overlays.
- Added Eloquent relation scope overlay parity so local scopes discovered on application models are also available on relationship query chains.

## v0.2.0

- Added `laramago compare` to run raw Mago and Laramago side by side during migrations.
- Improved PHPStan/Larastan parity for Laravel request class factories, reflection return-type checks, guarded dynamic class instantiation, implicit array accumulators, Eloquent trait host methods, framework contracts, request helpers, collection macros, and source compatibility overlays.
- Expanded generated Laravel framework and Eloquent overlays while keeping the PHPStan/Larastan level gate explicit through `--phpstan-level`.
- Documented overlay opt-out flags and moved detailed model overlay documentation into `docs/laravel-model-overlays.md`.
- Kept public migration comparison data sanitized with aggregate counts only.
