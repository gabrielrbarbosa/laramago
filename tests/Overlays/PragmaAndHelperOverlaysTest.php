<?php

declare(strict_types=1);

function testPhpStanPragmaOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/PhpStanIgnored.php', <<<'PHP'
<?php

namespace App;

final class PhpStanIgnored
{
    public function getUser(): mixed
    {
        return null;
    }

    public function nextLine(): void
    {
        // @phpstan-ignore-next-line
        $this->getuser();
    }

    public function sameLine(): mixed
    {
        return $this->missing(); // @phpstan-ignore-line
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $substitutions = $method->invoke($application, $project, [], []);

    if (! is_array($substitutions) || count($substitutions) !== 2) {
        fail('PHPStan pragma overlay generation returned unexpected substitutions');
    }

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    if (! is_array($map) || ($map[0]['original'] ?? null) !== 'app/PhpStanIgnored.php' || ! is_string($map[0]['overlay'] ?? null)) {
        fail('PHPStan pragma overlay generation wrote an unexpected path map');
    }

    $overlay = file_get_contents($project . '/' . $map[0]['overlay']);

    if (! is_string($overlay) || substr_count($overlay, '@mago-ignore all') !== 2) {
        fail('PHPStan pragma overlay generation did not translate ignore comments');
    }

    if (! str_contains($overlay, '@method mixed getuser(mixed ...$arguments)')) {
        fail('source compatibility overlay did not add case-insensitive method aliases');
    }
}

function testLaravelDateHelperOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/routes', 0777, true);

    file_put_contents($project . '/app/UsesDateHelpers.php', <<<'PHP'
<?php

namespace App;

final class UsesDateHelpers
{
    public function handle(): mixed
    {
        $now = now()->subDays(1);
        $today = today();
        $response = response(['ok' => true], 202)->withHeaders(['X-Test' => '1']);
        $factory = response()->json(['ok' => true]);
        $method = $this->now();
        $static = self::today();

        return [$now, $today, $response, $factory, $method, $static];
    }

    private function now(): mixed
    {
        return null;
    }

    private static function today(): mixed
    {
        return null;
    }
}
PHP);

    file_put_contents($project . '/routes/console.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

DB::table('activity_log')->where('created_at', '<', now()->subYear())->delete();
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, ['routes'], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);
    $foundAppOverlay = false;
    $foundRoutesOverlay = false;

    foreach (is_array($map) ? $map : [] as $entry) {
        if (! is_string($entry['original'] ?? null) || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (($entry['original'] ?? null) === 'app/UsesDateHelpers.php'
            && is_string($overlay)
            && str_contains($overlay, '\\Illuminate\\Support\\Carbon::now()->subDays(1)')
            && str_contains($overlay, '\\Illuminate\\Support\\Carbon::today()')
            && str_contains($overlay, '\\Illuminate\\Support\\Facades\\Response::make([\'ok\' => true], 202)->withHeaders')
            && str_contains($overlay, 'response()->json([\'ok\' => true])')
            && str_contains($overlay, '$this->now()')
            && str_contains($overlay, 'self::today()')) {
            $foundAppOverlay = true;
        }

        if (($entry['original'] ?? null) === 'routes/console.php'
            && is_string($overlay)
            && str_contains($overlay, '\\Illuminate\\Support\\Carbon::now()->subYear()')) {
            $foundRoutesOverlay = true;
        }
    }

    if (! $foundAppOverlay || ! $foundRoutesOverlay) {
        fail('Laravel date helper overlay did not rewrite global helper calls safely');
    }
}

function testLaravelCollectionMacroOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/DefinesCollectionMacro.php', <<<'PHP'
<?php

namespace App;

use Illuminate\Support\Collection;

final class DefinesCollectionMacro
{
    public function boot(): void
    {
        Collection::macro('paginate', function ($perPage): mixed {
            return $this->forPage(1, $perPage)->values();
        });
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/DefinesCollectionMacro.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var \Illuminate\Support\Collection $this */')
            && str_contains($overlay, '$this->forPage(1, $perPage)->values()')) {
            return;
        }
    }

    fail('Laravel collection macro overlay did not annotate closure $this safely');
}

function testLaravelCollectionItemObjectOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/UsesCollectionObjectItems.php', <<<'PHP'
<?php

namespace App;

final class UsesCollectionObjectItems
{
    public function rows(mixed $rows): mixed
    {
        $objects = $rows->map(function ($row): array {
            return [
                'name' => $row->name,
                'status' => $row->status(),
            ];
        });
        $arrayObjects = $rows->map(function ($entry): array {
            return [
                'id' => $entry['id'],
                'name' => $entry->name,
            ];
        });
        $arrays = $rows->map(function ($payload): array {
            return [
                'id' => $payload['id'],
            ];
        });

        $scalars = $rows->filter(function ($value): bool {
            return $value !== null;
        });

        return [$objects, $arrayObjects, $arrays, $scalars];
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/UsesCollectionObjectItems.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var object $row */')
            && str_contains($overlay, '/** @var object $entry */')
            && ! str_contains($overlay, '/** @var array<array-key, mixed> $payload */')
            && ! str_contains($overlay, '/** @var object $value */')) {
            return;
        }
    }

    fail('Laravel collection item overlay did not annotate object-like callback parameters safely');
}

function testLaravelForeachObjectRowOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/UsesForeachObjectRows.php', <<<'PHP'
<?php

namespace App;

final class UsesForeachObjectRows
{
    public function rows(mixed $rows): array
    {
        foreach ($rows as $row) {
            $names[] = $row->name;
        }

        foreach ($rows as $entry) {
            $names[] = $entry['name'] . $entry->suffix;
        }

        foreach ($rows as $payload) {
            $values[] = $payload['value'];
        }

        foreach ($rows as $scalar) {
            $values[] = (string) $scalar;
        }

        return [$names ?? [], $values ?? []];
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/UsesForeachObjectRows.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var object $row */')
            && str_contains($overlay, '/** @var object $entry */')
            && ! str_contains($overlay, '/** @var array<array-key, mixed> $payload */')
            && ! str_contains($overlay, '/** @var object $scalar */')) {
            return;
        }
    }

    fail('Laravel foreach object row overlay did not annotate object-like loop variables safely');
}

function testDynamicMemberSelectorStringOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/UsesDynamicMemberSelectors.php', <<<'PHP'
<?php

namespace App;

final class UsesDynamicMemberSelectors
{
    public function read(object $row, mixed $columns): mixed
    {
        $primary = $this->columnName();
        $value = $row->{$primary};

        return collect($columns)->mapWithKeys(function ($column) use ($row): array {
            return [$column => $row->{$column}];
        })->all() + ['value' => $value];
    }

    private function columnName(): mixed
    {
        return 'name';
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/UsesDynamicMemberSelectors.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var string $primary */')
            && str_contains($overlay, '/** @var string $column */')) {
            return;
        }
    }

    fail('Dynamic member selector overlay did not infer selector variables as strings');
}

function testEloquentModelArrayAccessAssignmentOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/UsesEloquentModelArrayAccess.php', <<<'PHP'
<?php

namespace App;

use App\Models\Account;

final class UsesEloquentModelArrayAccess
{
    public function show(): array
    {
        $account = Account::with(['status'])
            ->whereNull('deleted_at')
            ->first();

        if (! $account) {
            return [];
        }

        return [
            'id' => $account['id'],
            'name' => $account['name'],
        ];
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/UsesEloquentModelArrayAccess.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var \Illuminate\Database\Eloquent\Model|null $account */')) {
            return;
        }
    }

    fail('Eloquent model array access overlay did not annotate first-result assignments');
}

function testLaravelNumericFallbackAssignmentOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/UsesNumericFallbacks.php', <<<'PHP'
<?php

namespace App;

final class UsesNumericFallbacks
{
    public function total(object $row): int|float
    {
        $subtotal = $row->subtotal ?? 0;
        $discount = $row->discount ?: 0.0;

        return $subtotal - $discount;
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/UsesNumericFallbacks.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var int|float $subtotal */')
            && str_contains($overlay, '/** @var int|float $discount */')) {
            return;
        }
    }

    fail('Laravel numeric fallback overlay did not annotate aggregate numeric assignments safely');
}

function testLaravelExcelEventOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    if (! is_dir($project . '/app/Exports')) {
        mkdir($project . '/app/Exports', 0777, true);
    }

    file_put_contents($project . '/app/Exports/UsesExcelEvents.php', <<<'PHP'
<?php

namespace App\Exports;

use Maatwebsite\Excel\Events\AfterSheet;

final class UsesExcelEvents
{
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function ($event): void {
                $event->sheet->freezePane('A2');
            },
        ];
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/Exports/UsesExcelEvents.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var \Maatwebsite\Excel\Events\AfterSheet $event */')
            && str_contains($overlay, '$event->sheet->freezePane')) {
            return;
        }
    }

    fail('Laravel Excel event overlay did not annotate event callback variables');
}

function testLaravelValidationRuleCallbackOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/UsesValidationCallbacks.php', <<<'PHP'
<?php

namespace App;

final class UsesValidationCallbacks
{
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                function ($attribute, $value, $fail): void {
                    if ($value === '') {
                        $fail("{$attribute} is invalid.");
                    }
                },
            ],
        ];
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/UsesValidationCallbacks.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var callable $fail */')
            && str_contains($overlay, '$fail("{$attribute} is invalid.");')) {
            return;
        }
    }

    fail('Laravel validation rule callback overlay did not annotate the $fail callable');
}

function testLaravelQueryBuilderClosureOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/UsesQueryBuilderClosures.php', <<<'PHP'
<?php

namespace App;

final class UsesQueryBuilderClosures
{
    public function apply(mixed $builder): mixed
    {
        return $builder->whereIn('user_id', function ($sub) {
            $sub->from('users')->select('id');
        })->where(function ($query): void {
            $query->whereExists(function ($nested): void {
                $nested->from('orders')->select('id');
            });
        })->leftJoin('accounts', function ($join): void {
            $join->on('accounts.id', '=', 'users.account_id');
        });
    }

    public function chainedCollection(mixed $builder): mixed
    {
        return $builder
            ->where('active', true)
            ->get()
            ->mapWithKeys(function ($item): array {
                return [$item->status => $item->total];
            });
    }

    public function chainedWhen(mixed $builder): mixed
    {
        return $builder
            ->join('users', 'users.id', '=', 'posts.user_id')
            ->when(true, function ($query): mixed {
                return $query->where('users.active', true);
            });
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/UsesQueryBuilderClosures.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $sub */')
            && str_contains($overlay, '/** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query */')
            && str_contains($overlay, '/** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $nested */')
            && str_contains($overlay, '/** @var \Illuminate\Database\Query\JoinClause $join */')
            && ! str_contains($overlay, '/** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $item */')
            && ! str_contains($overlay, '/** @var \Illuminate\Database\Query\JoinClause $query */')) {
            return;
        }
    }

    fail('Laravel query builder closure overlay did not annotate nested builder callbacks');
}

function testLaravelObserverModelOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    if (! is_dir($project . '/app/Models')) {
        mkdir($project . '/app/Models', 0777, true);
    }

    if (! is_dir($project . '/app/Observers')) {
        mkdir($project . '/app/Observers', 0777, true);
    }

    file_put_contents($project . '/app/Models/Order.php', <<<'PHP'
<?php

namespace App\Models;

use App\Observers\OrderObserver;
use Illuminate\Database\Eloquent\Model;

final class Order extends Model
{
    protected static function booted(): void
    {
        static::observe([OrderObserver::class]);
    }
}
PHP);

    file_put_contents($project . '/app/Models/AttributedOrder.php', <<<'PHP'
<?php

namespace App\Models;

use App\Observers\AttributedOrderObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy([AttributedOrderObserver::class])]
final class AttributedOrder extends Model
{
}
PHP);

    file_put_contents($project . '/app/Observers/OrderObserver.php', <<<'PHP'
<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;

final class OrderObserver
{
    public function created(Model $order): void
    {
        $order->total;
    }

    public function updated(mixed $order): void
    {
        $order->total;
    }

    public function deleted(object $order): void
    {
        $order->total;
    }
}
PHP);

    file_put_contents($project . '/app/Observers/AttributedOrderObserver.php', <<<'PHP'
<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;

final class AttributedOrderObserver
{
    public function created(Model $order): void
    {
        $order->total;
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);
    $foundRegisteredObserver = false;
    $foundAttributedObserver = false;

    foreach (is_array($map) ? $map : [] as $entry) {
        if (! is_string($entry['original'] ?? null) || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (($entry['original'] ?? null) === 'app/Observers/OrderObserver.php'
            && is_string($overlay)
            && str_contains($overlay, 'public function created(\App\Models\Order $order): void')
            && str_contains($overlay, 'public function updated(\App\Models\Order $order): void')
            && str_contains($overlay, 'public function deleted(\App\Models\Order $order): void')) {
            $foundRegisteredObserver = true;
        }

        if (($entry['original'] ?? null) === 'app/Observers/AttributedOrderObserver.php'
            && is_string($overlay)
            && str_contains($overlay, 'public function created(\App\Models\AttributedOrder $order): void')) {
            $foundAttributedObserver = true;
        }
    }

    if (! $foundRegisteredObserver || ! $foundAttributedObserver) {
        fail('Laravel observer overlay did not infer observed model parameters');
    }
}
