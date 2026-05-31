<?php

declare(strict_types=1);

function testLaravelMetadataInferenceHelpers(string $root): void
{
    if (! class_exists('Illuminate\\Database\\Eloquent\\Model')) {
        eval('namespace Illuminate\\Database\\Eloquent; class Model {} class Collection {}');
    }

    if (! class_exists('Illuminate\\Database\\Eloquent\\Relations\\Relation')) {
        eval('namespace Illuminate\\Database\\Eloquent\\Relations; class Relation {} class HasManyThrough extends Relation {} class MorphTo extends Relation {}');
    }

    if (! class_exists('Illuminate\\Database\\Eloquent\\Attributes\\Scope')) {
        eval('namespace Illuminate\\Database\\Eloquent\\Attributes; #[\\Attribute(\\Attribute::TARGET_METHOD)] class Scope {}');
    }

    if (! class_exists('App\\Models\\Order')) {
        eval('namespace App\\Models; class Order extends \\Illuminate\\Database\\Eloquent\\Model {}');
    }

    if (! enum_exists('LaramagoMetadataStatus')) {
        eval('enum LaramagoMetadataStatus: string { case Draft = "draft"; }');
    }

    require_once $root . '/resources/laravel-model-metadata.php';

    $propertyCases = [
        ['immutable_datetime', 'datetime', false, '\\Carbon\\CarbonImmutable'],
        ['encrypted:array', 'json', true, 'array|null'],
        ['collection', 'json', false, '\\Illuminate\\Support\\Collection'],
        ['Illuminate\\Database\\Eloquent\\Casts\\AsArrayObject', 'json', false, '\\ArrayObject'],
        ['LaramagoMetadataStatus', 'varchar', false, '\\LaramagoMetadataStatus'],
    ];

    foreach ($propertyCases as [$cast, $databaseType, $nullable, $expected]) {
        $actual = propertyType($cast, $databaseType, $nullable);

        if ($actual !== $expected) {
            fail("property type inference returned {$actual}; expected {$expected}");
        }
    }

    $hasManyThrough = relationType('Illuminate\\Database\\Eloquent\\Relations\\HasManyThrough', 'App\\Models\\Order');

    if ($hasManyThrough !== '\\Illuminate\\Database\\Eloquent\\Collection<int, \\App\\Models\\Order>') {
        fail('HasManyThrough relation type inference returned an unexpected type');
    }

    $morphTo = relationType('Illuminate\\Database\\Eloquent\\Relations\\MorphTo', 'App\\Models\\Order');

    if ($morphTo !== '\\Illuminate\\Database\\Eloquent\\Model|null') {
        fail('MorphTo relation type inference returned an unexpected type');
    }

    if (! trait_exists('LaramagoScopeFixtureTrait')) {
        eval('trait LaramagoScopeFixtureTrait { protected function scopeUseIndex(mixed $query, array|string $index): mixed { return $query; } }');
    }

    if (! class_exists('LaramagoScopeFixtureModel')) {
        eval('class LaramagoScopeFixtureModel extends \\Illuminate\\Database\\Eloquent\\Model { use \\LaramagoScopeFixtureTrait; #[\\Illuminate\\Database\\Eloquent\\Attributes\\Scope] protected function active(mixed $query): mixed { return $query; } }');
    }

    $scopes = modelScopes('LaramagoScopeFixtureModel');

    if (! in_array([
        'name' => 'useIndex',
        'parameters' => 'array|string $index',
    ], $scopes, true)) {
        fail('model scope discovery missed a trait-defined local scope');
    }

    if (! in_array([
        'name' => 'active',
        'parameters' => '',
    ], $scopes, true)) {
        fail('model scope discovery missed an attribute-defined protected scope');
    }
}

function testModelDocblockIncludesLaravelMagic(string $root): void
{
    require_once $root . '/src/Application.php';

    $source = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Existing model annotations should survive generated overlays.
 *
 * @mixin \Illuminate\Database\Eloquent\Builder<Product>
 * @method static \Illuminate\Database\Eloquent\Builder<Product> visible()
 */
class Product extends Model
{
}
PHP;

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'insertModelDocblock');
    $overlay = $method->invoke($application, $source, 'Product', [[
        'name' => 'id',
        'type' => 'int',
    ]], [[
        'name' => 'image_url',
        'type' => 'string|null',
    ]], [[
        'name' => 'orders',
        'type' => '\\Illuminate\\Database\\Eloquent\\Collection<int, \\App\\Models\\Order>',
    ]], [[
        'name' => 'active',
        'parameters' => 'bool $onlyVisible = null',
    ]], true);

    if (! is_string($overlay)) {
        fail('model docblock overlay did not return source');
    }

    foreach ([
        '@property int $id',
        '@property-read string|null $image_url',
        '@property-read \\Illuminate\\Database\\Eloquent\\Collection<int, \\App\\Models\\Order> $orders',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = "and")',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> whereIn(string $column, mixed $values, string $boolean = "and", bool $not = false)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> leftJoin(mixed $table, mixed $first, ?string $operator = null, mixed $second = null)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> groupBy(mixed ...$groups)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> with(array|string ...$relations)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> withCount(array|string $relations)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> select(mixed ...$columns)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> selectRaw(mixed $expression, array $bindings = [])',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> orderByRaw(string|\\Illuminate\\Contracts\\Database\\Query\\Expression $sql, array $bindings = [])',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> limit(int|string|null $value)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> orderBy(mixed $column, mixed $direction = "asc")',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> lockForUpdate()',
        '@method static self create(array $attributes = null)',
        '@method static static|null first(array|string $columns = ["*"])',
        '@method static self firstOrFail(array|string $columns = ["*"])',
        '@method static self firstOrCreate(array $attributes = null, array $values = null)',
        '@method static self updateOrCreate(array $attributes, array $values = null)',
        '@method static self findOrFail(mixed $id, array|string $columns = ["*"])',
        '@method static \\Illuminate\\Database\\Eloquent\\Collection get(array|string $columns = ["*"])',
        '@method static \\Illuminate\\Support\\Collection pluck(string $column, mixed $key = null)',
        '@method static bool exists()',
        '@method static bool insert(array $values)',
        '@method \\Laravel\\Sanctum\\NewAccessToken createToken(string $name, array $abilities = ["*"], ?\\DateTimeInterface $expiresAt = null)',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<static> active(bool $onlyVisible = null)',
        '@mixin \\Illuminate\\Database\\Eloquent\\Builder<Product>',
        '@method static \\Illuminate\\Database\\Eloquent\\Builder<Product> visible()',
        '@return \\Illuminate\\Database\\Eloquent\\Builder<static>',
        '@return static|\\Illuminate\\Database\\Eloquent\\Collection<int, static>|null',
        'public static function where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = \'and\')',
        'public static function select(mixed ...$columns)',
        'public static function withoutglobalscopes(?array $scopes = null)',
    ] as $expected) {
        if (! str_contains($overlay, $expected)) {
            fail('model docblock overlay missed expected Laravel magic: ' . $expected);
        }
    }

    if (substr_count($overlay, '@laramago-generated') !== 1) {
        fail('model docblock overlay should merge generated class metadata once');
    }

    if (str_contains($overlay, '@method static int delete()')) {
        fail('model docblock overlay should not shadow the instance delete method');
    }

    if (strpos($overlay, '@mixin \\Illuminate\\Database\\Eloquent\\Builder<Product>') > strpos($overlay, '@laramago-generated')) {
        fail('model docblock overlay should preserve existing annotations before generated metadata');
    }

    $attributedSource = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

#[ScopedBy([])]
class AttributedProduct extends Model
{
}
PHP;

    $attributedOverlay = $method->invoke($application, $attributedSource, 'AttributedProduct', [[
        'name' => 'id',
        'type' => 'int',
    ]], [], [], [], false);

    if (! is_string($attributedOverlay)
        || strpos($attributedOverlay, '@property int $id') === false
        || strpos($attributedOverlay, '@property int $id') > strpos($attributedOverlay, '#[ScopedBy([])]')) {
        fail('model docblock overlay should be inserted before class attributes');
    }
}

function testLaravelFrameworkOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/config', 0777, true);
    mkdir($project . '/vendor/maatwebsite/excel/src/Concerns', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Database/Query', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Auth', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Broadcasting', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Auth', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Foundation', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Pagination', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Foundation', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Http/Client', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Http/Concerns', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Http/Resources/Json', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Collections', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Notifications', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Pagination', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Routing', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Support/Facades', 0777, true);
    mkdir($project . '/vendor/laravel/framework/src/Illuminate/Validation', 0777, true);
    mkdir($project . '/vendor/laravel/socialite/src/Contracts', 0777, true);
    mkdir($project . '/vendor/laravel/socialite/src/Two', 0777, true);
    mkdir($project . '/vendor/nesbot/carbon/src/Carbon', 0777, true);
    mkdir($project . '/app/Models/Ticket', 0777, true);
    mkdir($project . '/app/Providers', 0777, true);

    file_put_contents($project . '/config/auth.php', <<<'PHP'
<?php

use App\Models\Usuario\Usuario;

return [
    'providers' => [
        'users' => [
            'model' => Usuario::class,
        ],
    ],
];
PHP);

    file_put_contents($project . '/app/Models/Ticket/InteracaoTicket.php', <<<'PHP'
<?php

namespace App\Models\Ticket;

use App\Models\Usuario\Usuario;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InteracaoTicket extends Model
{
    #[Scope]
    protected function visibleTo(Builder $query, Usuario $user): Builder
    {
        return $query;
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query;
    }
}
PHP);

    file_put_contents($project . '/app/Providers/AppServiceProvider.php', <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Collection::macro('paginate', function ($perPage, $total = null, $page = null, $pageName = 'page') {
            return null;
        });
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Auth/Guard.php', '<?php');

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Broadcasting/ShouldBroadcast.php', <<<'PHP'
<?php

namespace Illuminate\Contracts\Broadcasting;

interface ShouldBroadcast
{
    /**
     * @return \Illuminate\Broadcasting\Channel|\Illuminate\Broadcasting\Channel[]|string[]|string
     */
    public function broadcastOn();
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Validation/ValidationException.php', <<<'PHP'
<?php

namespace Illuminate\Validation;

class ValidationException extends \Exception
{
    /**
     * Get all of the validation error messages.
     *
     * @return array
     */
    public function errors()
    {
        return [];
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Auth/AuthManager.php', <<<'PHP'
<?php

namespace Illuminate\Auth;

/**
 * @mixin \Illuminate\Contracts\Auth\StatefulGuard
 */
class AuthManager
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Support/Facades/Auth.php', '<?php');

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Foundation/Application.php', <<<'PHP'
<?php

namespace Illuminate\Contracts\Foundation;

interface Application
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Foundation/helpers.php', <<<'PHP'
<?php

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Translation\Translator;
use Carbon\CarbonInterface;

if (! function_exists('auth')) {
    /**
     * Get the available auth instance.
     *
     * @param  string|null  $guard
     * @return ($guard is null ? \Illuminate\Contracts\Auth\Factory : \Illuminate\Contracts\Auth\Guard)
     */
    function auth($guard = null): AuthFactory|Guard
    {
    }
}

if (! function_exists('now')) {
    function now($tz = null): CarbonInterface
    {
    }
}

if (! function_exists('today')) {
    function today($tz = null): CarbonInterface
    {
    }
}

if (! function_exists('trans')) {
    /**
     * Translate the given message.
     *
     * @param  string|null  $key
     * @param  array  $replace
     * @param  string|null  $locale
     * @return ($key is null ? \Illuminate\Contracts\Translation\Translator : array|string)
     */
    function trans($key = null, $replace = [], $locale = null): Translator|array|string
    {
    }
}

if (! function_exists('__')) {
    /**
     * Translate the given message.
     *
     * @param  string|null  $key
     * @param  array  $replace
     * @param  string|null  $locale
     */
    function __($key = null, $replace = [], $locale = null): string|array|null
    {
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Support/Facades/Http.php', <<<'PHP'
<?php

namespace Illuminate\Support\Facades;

/**
 * @method static \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface get(string $url, array|string|null $query = null)
 * @method static \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface post(string $url, array|\JsonSerializable|\Illuminate\Contracts\Support\Arrayable $data = [])
 * @method static \Illuminate\Http\Client\Response|\Illuminate\Http\Client\Promises\LazyPromise send(string $method, string $url, array $options = [])
 */
class Http
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php', <<<'PHP'
<?php

namespace Illuminate\Http\Client;

class PendingRequest
{
    /**
     * @return \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface
     *
     * @phpstan-return (TAsync is false ?  \Illuminate\Http\Client\Response : \GuzzleHttp\Promise\PromiseInterface)
     */
    public function get(string $url, $query = null)
    {
    }

    /**
     * @return \Illuminate\Http\Client\Response|\Illuminate\Http\Client\Promises\LazyPromise
     *
     * @phpstan-return (TAsync is false ? \Illuminate\Http\Client\Response : \Illuminate\Http\Client\Promises\LazyPromise)
     */
    public function send(string $method, string $url, array $options = [])
    {
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Support/Optional.php', <<<'PHP'
<?php

namespace Illuminate\Support;

class Optional
{
    public function __get($key)
    {
    }

    public function __call($method, $parameters)
    {
    }
}
PHP);

    file_put_contents($project . '/app/UsesOptionalHelper.php', <<<'PHP'
<?php

namespace App;

final class UsesOptionalHelper
{
    public function inspect(mixed $pedido): mixed
    {
        return [
            optional($pedido->cliente)->nome,
            optional($pedido->cliente)->desconto,
            optional($pedido->job)->release(),
        ];
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Support/Carbon.php', <<<'PHP'
<?php

namespace Illuminate\Support;

class Carbon extends \Carbon\Carbon
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Support/Number.php', <<<'PHP'
<?php

namespace Illuminate\Support;

class Number
{
    /**
     * @return string|false
     */
    public static function currency(int|float $number, string $in = '', ?string $locale = null, ?int $precision = null)
    {
    }

    /**
     * @return string|false
     */
    public static function format(int|float $number, ?int $precision = null, ?int $maxPrecision = null, ?string $locale = null)
    {
    }

    /**
     * @return int|float|false
     */
    public static function parse(string $string, ?int $type = null, ?string $locale = null): int|float|false
    {
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Notifications/Notification.php', <<<'PHP'
<?php

namespace Illuminate\Notifications;

class Notification
{
}
PHP);

    file_put_contents($project . '/app/UsesNotificationChannel.php', <<<'PHP'
<?php

namespace App;

use Illuminate\Notifications\Notification;

final class UsesNotificationChannel
{
    public function send(Notification $notification): mixed
    {
        return [$notification->toWhatsapp($this), $notification->sendType];
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/socialite/src/Contracts/Provider.php', <<<'PHP'
<?php

namespace Laravel\Socialite\Contracts;

interface Provider
{
    /**
     * @return \Laravel\Socialite\Contracts\User
     */
    public function user();

    public function redirect();
}
PHP);

    file_put_contents($project . '/vendor/laravel/socialite/src/Two/User.php', <<<'PHP'
<?php

namespace Laravel\Socialite\Two;

class User
{
}
PHP);

    file_put_contents($project . '/vendor/nesbot/carbon/src/Carbon/Carbon.php', <<<'PHP'
<?php

namespace Carbon;

class Carbon extends \DateTime
{
}
PHP);

    file_put_contents($project . '/vendor/nesbot/carbon/src/Carbon/CarbonImmutable.php', <<<'PHP'
<?php

namespace Carbon;

class CarbonImmutable extends \DateTimeImmutable
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php', <<<'PHP'
<?php

namespace Illuminate\Database\Eloquent;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @mixin \Illuminate\Database\Query\Builder
 */
class Builder
{
    /**
     * @param  int|null|\Closure  $perPage
     * @param  array|string  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @param  \Closure|int|null  $total
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, TModel>
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
    }

    /**
     * @param  int|null  $perPage
     * @param  array|string  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Pagination\Paginator<int, TModel>
     */
    public function simplePaginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php', <<<'PHP'
<?php

namespace Illuminate\Database\Eloquent;

class Model
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
    }

    public function load($relations)
    {
    }

    public function loadMissing($relations)
    {
    }

    public function loadCount($relations)
    {
    }

    protected function increment($column, $amount = 1, array $extra = [])
    {
    }

    protected function decrement($column, $amount = 1, array $extra = [])
    {
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return $this
     */
    public function fill(array $attributes)
    {
        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return $this
     */
    public function forceFill(array $attributes)
    {
        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  bool  $exists
     * @return static
     */
    public function newInstance($attributes = [], $exists = false)
    {
        return new static;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        return true;
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php', <<<'PHP'
<?php

namespace Illuminate\Database\Eloquent\Concerns;

trait HasAttributes
{
    public function only($attributes)
    {
    }

    public function except($attributes)
    {
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php', <<<'PHP'
<?php

namespace Illuminate\Database\Query;

class Builder
{
    public function select($columns = ['*'])
    {
    }

    public function addSelect($column)
    {
    }

    public function distinct()
    {
    }

    /**
     * @param  SortDirection|'asc'|'desc'  $direction
     */
    public function orderBy($column, $direction = 'asc')
    {
    }

    /**
     * @param  \Illuminate\Contracts\Database\Query\Expression|literal-string  $sql
     * @return $this
     */
    public function whereRaw($sql, $bindings = [], $boolean = 'and')
    {
    }

    /**
     * @param  int  $value
     * @return $this
     */
    public function limit($value)
    {
    }

    /**
     * @param  int|\Closure  $perPage
     * @param  int|null  $page
     * @param  \Closure|int|null  $total
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
    }

    /**
     * @param  int  $perPage
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null)
    {
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Routing/ControllerMiddlewareOptions.php', <<<'PHP'
<?php

namespace Illuminate\Routing;

class ControllerMiddlewareOptions
{
    public function only($methods)
    {
    }

    public function except($methods)
    {
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Http/Request.php', <<<'PHP'
<?php

namespace Illuminate\Http;

class Request
{
    public function __get($key): mixed
    {
        return null;
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Http/Concerns/InteractsWithInput.php', <<<'PHP'
<?php

namespace Illuminate\Http\Concerns;

trait InteractsWithInput
{
    /**
     * Retrieve a query string item from the request.
     *
     * @param  string|null  $key
     * @param  string|array|null  $default
     * @return string|array|null
     */
    public function query($key = null, $default = null)
    {
    }

    /**
     * Retrieve a request payload item from the request.
     *
     * @param  string|null  $key
     * @param  string|array|null  $default
     * @return string|array|null
     */
    public function post($key = null, $default = null)
    {
    }

    /**
     * Retrieve a file from the request.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return ($key is null ? array<string, \Illuminate\Http\UploadedFile|\Illuminate\Http\UploadedFile[]> : \Illuminate\Http\UploadedFile|\Illuminate\Http\UploadedFile[]|null)
     */
    public function file($key = null, $default = null)
    {
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Collections/Collection.php', <<<'PHP'
<?php

namespace Illuminate\Support;

class Collection
{
    /**
     * @template TGetDefault
     *
     * @param  TKey|null  $key
     * @param  TGetDefault|(\Closure(): TGetDefault)  $default
     * @return TValue|TGetDefault
     */
    public function get($key, $default = null)
    {
    }

    /**
     * @return static<array-key, mixed>
     */
    public function pluck($value, $key = null)
    {
    }
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Http/Resources/Json/ResourceCollection.php', <<<'PHP'
<?php

namespace Illuminate\Http\Resources\Json;

class ResourceCollection
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Http/Resources/Json/AnonymousResourceCollection.php', <<<'PHP'
<?php

namespace Illuminate\Http\Resources\Json;

class AnonymousResourceCollection extends ResourceCollection
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php', <<<'PHP'
<?php

namespace Illuminate\Pagination;

abstract class AbstractPaginator
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Contracts/Pagination/Paginator.php', <<<'PHP'
<?php

namespace Illuminate\Contracts\Pagination;

interface Paginator
{
}
PHP);

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/HasFactory.php', '<?php');

    file_put_contents($project . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Scope.php', '<?php');

    file_put_contents($project . '/vendor/maatwebsite/excel/src/Concerns/FromCollection.php', '<?php');

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'laravelFrameworkSubstitutions');
    $substitutions = $method->invoke($application, $project, []);

    if (! is_array($substitutions) || count($substitutions) !== 64) {
        fail('framework overlay generation returned unexpected substitutions');
    }

    $guardOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Guard.php');
    $authManagerOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/AuthManager.php');
    $authOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Auth.php');
    $applicationContractOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/ApplicationContract.php');
    $httpOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Http.php');
    $pendingRequestOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/PendingRequest.php');
    $optionalOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Optional.php');
    $supportCollectionOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/SupportCollection.php');
    $supportCarbonOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/SupportCarbon.php');
    $supportNumberOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/SupportNumber.php');
    $baseCarbonOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/BaseCarbon.php');
    $baseCarbonImmutableOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/BaseCarbonImmutable.php');
    $foundationHelpersOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/FoundationHelpers.php');
    $notificationOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Notification.php');
    $shouldBroadcastOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/ShouldBroadcast.php');
    $validationExceptionOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/ValidationException.php');
    $eloquentBuilderOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Builder.php');
    $eloquentModelOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/EloquentModel.php');
    $hasAttributesOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/HasAttributes.php');
    $queryBuilderOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/QueryBuilder.php');
    $controllerMiddlewareOptionsOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/ControllerMiddlewareOptions.php');
    $requestOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Request.php');
    $interactsWithInputOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/InteractsWithInput.php');
    $resourceCollectionOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/ResourceCollection.php');
    $anonymousResourceCollectionOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/AnonymousResourceCollection.php');
    $abstractPaginatorOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/AbstractPaginator.php');
    $paginatorContractOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/PaginatorContract.php');
    $hasFactoryOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/HasFactory.php');
    $scopeOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/Scope.php');
    $fromCollectionOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/FromCollection.php');
    $socialiteProviderOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/SocialiteProvider.php');
    $socialiteUserOverlay = file_get_contents($project . '/.laramago/cache/framework-overlays/SocialiteUser.php');

    if (! is_string($guardOverlay) || ! str_contains($guardOverlay, '@return \\App\\Models\\Usuario\\Usuario|null')) {
        fail('guard overlay did not use the configured auth model');
    }

    if (! is_string($authManagerOverlay) || ! str_contains($authManagerOverlay, '@method \\App\\Models\\Usuario\\Usuario|null user()') || ! str_contains($authManagerOverlay, '@method int|string|null id()')) {
        fail('auth manager overlay did not expose delegated guard methods');
    }

    if (! is_string($authOverlay) || ! str_contains($authOverlay, '@method static \\App\\Models\\Usuario\\Usuario|null user()')) {
        fail('auth facade overlay did not use the configured auth model');
    }

    if (! is_string($foundationHelpersOverlay) || ! str_contains($foundationHelpersOverlay, '@return ($guard is null ? \\Illuminate\\Auth\\AuthManager : \\Illuminate\\Contracts\\Auth\\Guard)') || ! str_contains($foundationHelpersOverlay, 'function auth($guard = null): \\Illuminate\\Auth\\AuthManager|Guard') || ! str_contains($foundationHelpersOverlay, 'function now($tz = null): \\Illuminate\\Support\\Carbon')) {
        fail('foundation helpers overlay did not expose the default auth manager return type');
    }

    if (! is_string($foundationHelpersOverlay) || ! str_contains($foundationHelpersOverlay, '@return ($key is null ? \\Illuminate\\Contracts\\Translation\\Translator : string)') || ! str_contains($foundationHelpersOverlay, 'function trans($key = null, $replace = [], $locale = null): Translator|string') || ! str_contains($foundationHelpersOverlay, '@return ($key is null ? null : string)') || ! str_contains($foundationHelpersOverlay, 'function __($key = null, $replace = [], $locale = null): ?string')) {
        fail('foundation helpers overlay did not expose keyed translation helpers as strings');
    }

    if (! is_string($httpOverlay) || ! str_contains($httpOverlay, '@method static \\Illuminate\\Http\\Client\\Response get(') || ! str_contains($httpOverlay, '@method static \\Illuminate\\Http\\Client\\Response send(')) {
        fail('HTTP facade overlay did not expose synchronous response return types');
    }

    if (! is_string($pendingRequestOverlay) || ! str_contains($pendingRequestOverlay, '@return \\Illuminate\\Http\\Client\\Response') || str_contains($pendingRequestOverlay, 'PromiseInterface') || str_contains($pendingRequestOverlay, 'LazyPromise')) {
        fail('PendingRequest overlay did not expose synchronous response return types');
    }

    if (! is_string($optionalOverlay) || ! str_contains($optionalOverlay, '@property mixed $desconto') || ! str_contains($optionalOverlay, '@property mixed $nome') || ! str_contains($optionalOverlay, '@method mixed release(mixed ...$parameters)')) {
        fail('Optional overlay did not document project-used dynamic optional members');
    }

    if (! is_string($supportCollectionOverlay) || ! str_contains($supportCollectionOverlay, '@method \\Illuminate\\Pagination\\LengthAwarePaginator paginate(') || ! str_contains($supportCollectionOverlay, '@return \\Illuminate\\Support\\Collection<array-key, mixed>') || ! str_contains($supportCollectionOverlay, '@param  TKey|false|null  $key')) {
        fail('Support collection overlay did not document project collection macros');
    }

    if (! is_string($applicationContractOverlay) || ! str_contains($applicationContractOverlay, 'public function isProduction(): bool;')) {
        fail('application contract overlay did not expose production environment helper');
    }

    if (! is_string($supportCarbonOverlay) || ! str_contains($supportCarbonOverlay, '@method float diffinseconds(') || ! str_contains($supportCarbonOverlay, '@method $this startofmonth(') || ! str_contains($supportCarbonOverlay, '@method $this locale(string $locale')) {
        fail('support Carbon overlay did not expose lowercase Carbon method aliases');
    }

    if (! is_string($supportNumberOverlay) || ! str_contains($supportNumberOverlay, '@return string') || str_contains($supportNumberOverlay, '@return string|false') || ! str_contains($supportNumberOverlay, '@return int|float|false')) {
        fail('support Number overlay did not expose display helpers as strings while preserving parse failures');
    }

    if (! is_string($baseCarbonOverlay) || ! str_contains($baseCarbonOverlay, '@method static \\Carbon\\Carbon parse(') || ! str_contains($baseCarbonOverlay, '@method static \\Carbon\\Carbon createfromdate(mixed $year = null') || ! str_contains($baseCarbonOverlay, '@method $this subdays(')) {
        fail('base Carbon overlay did not expose lowercase Carbon method aliases');
    }

    if (! is_string($baseCarbonImmutableOverlay) || ! str_contains($baseCarbonImmutableOverlay, '@method static \\Carbon\\CarbonImmutable parse(') || ! str_contains($baseCarbonImmutableOverlay, '@method static \\Carbon\\CarbonImmutable createfromdate(mixed $year = null') || ! str_contains($baseCarbonImmutableOverlay, '@method $this subdays(')) {
        fail('base Carbon immutable overlay did not expose lowercase Carbon method aliases');
    }

    if (! is_string($notificationOverlay) || str_contains($notificationOverlay, '@property mixed $sendType') || ! str_contains($notificationOverlay, 'public function __get(string $key): mixed') || ! str_contains($notificationOverlay, 'public function towhatsapp(mixed $notifiable): mixed {}') || str_contains($notificationOverlay, 'public mixed $sendType;')) {
        fail('notification overlay did not expose project custom channel members');
    }

    if (! is_string($shouldBroadcastOverlay) || ! str_contains($shouldBroadcastOverlay, '@return mixed')) {
        fail('ShouldBroadcast overlay did not relax Laravel broadcast channel return docs');
    }

    if (! is_string($validationExceptionOverlay) || ! str_contains($validationExceptionOverlay, '@return array<string, list<string>>') || ! str_contains($validationExceptionOverlay, 'public function errors(): array')) {
        fail('ValidationException overlay did not expose validation errors as a string-list map');
    }

    if (! is_string($socialiteProviderOverlay) || ! str_contains($socialiteProviderOverlay, '@return \\Laravel\\Socialite\\Contracts\\User|null') || ! str_contains($socialiteProviderOverlay, 'public function with(array $parameters): static;') || ! str_contains($socialiteProviderOverlay, 'public function scopes(array $scopes): static;')) {
        fail('Socialite provider overlay did not expose fluent provider methods');
    }

    if (! is_string($socialiteUserOverlay) || ! str_contains($socialiteUserOverlay, 'public function setAccessTokenResponseBody(array $body): static')) {
        fail('Socialite user overlay did not expose SocialiteProviders extension methods');
    }

    if (str_contains($authOverlay, 'Laravel\\Ui\\UiServiceProvider')) {
        fail('auth facade overlay leaked optional vendor implementation details');
    }

    if (! is_string($eloquentBuilderOverlay) || ! str_contains($eloquentBuilderOverlay, '@method $this leftJoin(mixed $table') || ! str_contains($eloquentBuilderOverlay, '@method $this groupBy(mixed ...$groups)') || ! str_contains($eloquentBuilderOverlay, '@method $this whereNull(') || ! str_contains($eloquentBuilderOverlay, '@method $this select(mixed ...$columns)') || ! str_contains($eloquentBuilderOverlay, '@method $this selectRaw(mixed $expression, array $bindings = [])') || ! str_contains($eloquentBuilderOverlay, '@method $this withoutglobalscopes(') || ! str_contains($eloquentBuilderOverlay, '@method \\Illuminate\\Database\\Eloquent\\Builder<TModel> visibleTo(mixed ...$parameters)') || ! str_contains($eloquentBuilderOverlay, '@method \\Illuminate\\Database\\Eloquent\\Builder<TModel> visibleto(mixed ...$parameters)') || ! str_contains($eloquentBuilderOverlay, '@method \\Illuminate\\Database\\Eloquent\\Builder<TModel> forCustomer(mixed ...$parameters)') || ! str_contains($eloquentBuilderOverlay, '@mixin \\Illuminate\\Database\\Query\\Builder') || ! str_contains($eloquentBuilderOverlay, '@param  int|string|null|\\Closure  $perPage') || ! str_contains($eloquentBuilderOverlay, '@return TModel|null') || ! str_contains($eloquentBuilderOverlay, 'public function first($columns = [\'*\'])') || ! str_contains($eloquentBuilderOverlay, 'public function where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = \'and\'): \\Illuminate\\Database\\Eloquent\\Builder') || ! str_contains($eloquentBuilderOverlay, 'public function whereNull(string|array $columns, string $boolean = \'and\', bool $not = false): \\Illuminate\\Database\\Eloquent\\Builder') || ! str_contains($eloquentBuilderOverlay, 'public function whereIn(string $column, mixed $values, string $boolean = \'and\', bool $not = false): \\Illuminate\\Database\\Eloquent\\Builder') || ! str_contains($eloquentBuilderOverlay, 'public function groupBy(mixed ...$groups): \\Illuminate\\Database\\Eloquent\\Builder') || ! str_contains($eloquentBuilderOverlay, 'public function orderBy(mixed $column, mixed $direction = \'asc\'): \\Illuminate\\Database\\Eloquent\\Builder') || ! str_contains($eloquentBuilderOverlay, 'public function selectRaw(mixed $expression, array $bindings = []): \\Illuminate\\Database\\Eloquent\\Builder') || ! str_contains($eloquentBuilderOverlay, 'public function skip(mixed $value): \\Illuminate\\Database\\Eloquent\\Builder') || ! str_contains($eloquentBuilderOverlay, 'public function take(mixed $value): \\Illuminate\\Database\\Eloquent\\Builder')) {
        fail('Eloquent builder overlay did not preserve source and add delegated chain methods');
    }

    if (! is_string($eloquentModelOverlay) || ! str_contains($eloquentModelOverlay, '@param  array<array-key, mixed>  $attributes') || ! str_contains($eloquentModelOverlay, '@param  array<array-key, mixed>  $options') || ! str_contains($eloquentModelOverlay, 'public function loadMissing($relations, ...$additionalRelations)') || ! str_contains($eloquentModelOverlay, 'public function increment($column, $amount = 1, array $extra = [])') || ! str_contains($eloquentModelOverlay, 'public static function withoutGlobalScopes(?array $scopes = null)') || ! str_contains($eloquentModelOverlay, 'public static function where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = \'and\')') || ! str_contains($eloquentModelOverlay, 'public static function select(mixed ...$columns)') || ! str_contains($eloquentModelOverlay, 'public static function selectRaw(mixed $expression, array $bindings = [])') || ! str_contains($eloquentModelOverlay, 'public static function lockForUpdate()') || ! str_contains($eloquentModelOverlay, '@return \\Illuminate\\Database\\Eloquent\\Builder<static>') || ! str_contains($eloquentModelOverlay, '@return static|\\Illuminate\\Database\\Eloquent\\Collection<int, static>|null')) {
        fail('Eloquent model overlay did not expose dynamic static builder delegation');
    }

    if (! is_string($hasAttributesOverlay) || ! str_contains($hasAttributesOverlay, 'public function only($attributes, ...$additionalAttributes)') || ! str_contains($hasAttributesOverlay, 'public function except($attributes, ...$additionalAttributes)')) {
        fail('HasAttributes overlay did not expose variadic attribute selectors');
    }

    if (! is_string($queryBuilderOverlay) || ! str_contains($queryBuilderOverlay, 'public function select($columns = [\'*\'], ...$additionalColumns)') || ! str_contains($queryBuilderOverlay, 'public function addSelect($column, ...$additionalColumns)') || ! str_contains($queryBuilderOverlay, 'public function distinct(...$columns)') || ! str_contains($queryBuilderOverlay, '@param  SortDirection|string  $direction') || ! str_contains($queryBuilderOverlay, '@param  int|string|null  $value') || ! str_contains($queryBuilderOverlay, '@param  \\Illuminate\\Contracts\\Database\\Query\\Expression|string  $sql') || ! str_contains($queryBuilderOverlay, '@return \\Illuminate\\Pagination\\LengthAwarePaginator<array-key, mixed>') || ! str_contains($queryBuilderOverlay, '@return \\Illuminate\\Contracts\\Pagination\\Paginator<array-key, mixed>') || ! str_contains($queryBuilderOverlay, 'public function first($columns = [\'*\']): ?\\stdClass') || ! str_contains($queryBuilderOverlay, 'public function firstOrFail($columns = [\'*\'], $message = null): \\stdClass') || ! str_contains($queryBuilderOverlay, '@method $this selectRaw(mixed $expression, array $bindings = [])') || ! str_contains($queryBuilderOverlay, '@method $this whereintegernotinraw(')) {
        fail('query builder overlay did not expose variadic column selectors');
    }

    if (! is_string($controllerMiddlewareOptionsOverlay) || ! str_contains($controllerMiddlewareOptionsOverlay, 'public function only($methods, ...$additionalMethods)') || ! str_contains($controllerMiddlewareOptionsOverlay, 'public function except($methods, ...$additionalMethods)')) {
        fail('ControllerMiddlewareOptions overlay did not expose variadic middleware filters');
    }

    if (! is_string($requestOverlay) || ! str_contains($requestOverlay, 'public function __set(string $key, mixed $value): void') || ! str_contains($requestOverlay, 'public function safe(?array $keys = null): \\Illuminate\\Support\\ValidatedInput|array')) {
        fail('Request overlay did not expose safe input helper and dynamic request writes');
    }

    if (! is_string($interactsWithInputOverlay) || ! str_contains($interactsWithInputOverlay, '@param mixed $default') || ! str_contains($interactsWithInputOverlay, '@return mixed') || ! str_contains($interactsWithInputOverlay, '@return ($key is null ? array<string, mixed> : \\Illuminate\\Http\\UploadedFile|null)') || ! str_contains($interactsWithInputOverlay, 'public function file($key = null, $default = null): array|\\Illuminate\\Http\\UploadedFile|null')) {
        fail('InteractsWithInput overlay did not expose keyed request files as UploadedFile instances');
    }

    if (! is_string($resourceCollectionOverlay) || ! str_contains($resourceCollectionOverlay, '@method array all()') || ! str_contains($resourceCollectionOverlay, '@mixin \\Illuminate\\Support\\Collection<array-key, mixed>')) {
        fail('ResourceCollection overlay did not expose delegated collection methods');
    }

    if (! is_string($anonymousResourceCollectionOverlay) || ! str_contains($anonymousResourceCollectionOverlay, '@method array all()')) {
        fail('AnonymousResourceCollection overlay did not expose delegated collection methods');
    }

    if (! is_string($abstractPaginatorOverlay) || ! str_contains($abstractPaginatorOverlay, '@method $this makeHidden(') || ! str_contains($abstractPaginatorOverlay, '@method int total()') || ! str_contains($abstractPaginatorOverlay, '@property mixed $data') || ! str_contains($abstractPaginatorOverlay, 'public function __get(string $key): mixed')) {
        fail('AbstractPaginator overlay did not expose forwarded collection and pagination members');
    }

    if (! is_string($paginatorContractOverlay) || ! str_contains($paginatorContractOverlay, 'public function first(?callable $callback = null, mixed $default = null): mixed;')) {
        fail('Paginator contract overlay did not expose forwarded collection first method');
    }

    if (! is_string($hasFactoryOverlay) || ! str_contains($hasFactoryOverlay, '@return \\Illuminate\\Database\\Eloquent\\Factories\\Factory<static>')) {
        fail('HasFactory overlay did not expose a static model factory return type');
    }

    if (str_contains($hasFactoryOverlay, '@template')) {
        fail('HasFactory overlay still requires application models to pass a trait template parameter');
    }

    if (! is_string($scopeOverlay) || str_contains($scopeOverlay, '@template')) {
        fail('Scope overlay still requires application scopes to pass a template parameter');
    }

    if (! is_string($fromCollectionOverlay) || str_contains($fromCollectionOverlay, '@template')) {
        fail('FromCollection overlay still requires exports to pass template parameters');
    }

    $disabled = $method->invoke($application, $project, ['--no-laravel-framework-overlays']);

    if ($disabled !== []) {
        fail('framework overlays were not disabled by option');
    }
}
