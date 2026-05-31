# Laravel Model Overlays

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
- `@method static` entries for common Eloquent builder chains and terminals such as `where`, `whereIn`, `join`, `leftJoin`, `groupBy`, `having`, variadic `with`, `withCount`, variadic `select`, `orderBy`, `first`, `firstOrCreate`, `updateOrCreate`, `get`, `pluck`, `exists`, `count`, `insert`, `destroy`, `find`, and `findOrFail`;
- merged generated metadata with existing model class PHPDoc, so project annotations such as `@mixin`, `@method`, `@property`, and generic hints stay visible;
- attribute-safe model metadata insertion, so models using Laravel attributes such as `#[ScopedBy]` still receive analyzer-visible generated PHPDoc;
- `createToken` return types for models using Laravel Sanctum's `HasApiTokens` trait;
- `@method static` entries for classic `scopeFoo()` local scopes, trait-defined local scopes, inherited local scopes, and Laravel `#[Scope]` methods;
- Laravel `HasFactory` return types without app-level generic boilerplate;
- Laravel `Scope` and Laravel Excel `FromCollection` compatibility without app-level generic boilerplate;
- Laravel framework signatures that use `func_get_args()` at runtime, such as multi-column `select()` / `addSelect()` / `distinct()`, multi-relation `load()` / `loadMissing()` / `loadCount()`, model attribute `only()` / `except()`, and controller middleware `only()` / `except()`;
- auth helper, guard, manager, and facade return types for the configured Laravel user model;
- Composer autoload and autoload-dev type discovery for application namespaces that live outside the analyzed source paths;
- excluded-path symbol discovery so `exclude` can omit legacy code from analysis without turning referenced classes into false missing-class errors;
- Laravel request input compatibility for dynamic `$request->field`, `$this->request->field`, trait-provided Request properties used by pagination/filtering helpers, and helper parameters that accept `mixed $request` but clearly use Laravel request APIs;
- Laravel JSON resource delegated property and method metadata for normal `$this->field` / `$this->relation()` resource transformations;
- Laravel resource collection compatibility for common paginated `ResourceCollection` transforms that map Eloquent models from `$this->resource->getCollection()`;
- Laravel query builder callback compatibility for common nested `where`, `whereIn`, and `whereExists` closures;
- Laravel observer model inference from `static::observe(...)` and `#[ObservedBy(...)]` registrations, so observer lifecycle method parameters see the concrete Eloquent model type;
- Laravel Excel event callback compatibility for `BeforeExport`, `BeforeWriting`, `BeforeSheet`, and `AfterSheet` closures;
- Eloquent static builder delegation for lock-based query chains such as `Model::lockForUpdate()->...`;
- PHPStan suppression pragma compatibility for `@phpstan-ignore`, `@phpstan-ignore-next-line`, and `@phpstan-ignore-line` comments through generated temporary overlays;
- path-scoped suppression of unused generated Mago pragmas inside overlays, so PHPStan compatibility comments do not create new analyzer noise after Laramago resolves the original issue;
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
