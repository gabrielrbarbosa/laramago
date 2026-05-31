# Changelog

## v0.2.0

- Added `laramago compare` to run raw Mago and Laramago side by side during migrations.
- Improved PHPStan/Larastan parity for Laravel request class factories, reflection return-type checks, guarded dynamic class instantiation, implicit array accumulators, Eloquent trait host methods, framework contracts, request helpers, collection macros, and source compatibility overlays.
- Expanded generated Laravel framework and Eloquent overlays while keeping the PHPStan/Larastan level gate explicit through `--phpstan-level`.
- Documented overlay opt-out flags and moved detailed model overlay documentation into `docs/laravel-model-overlays.md`.
- Kept public migration comparison data sanitized with aggregate counts only.
