<?php

declare(strict_types=1);

function testLaravelRequestPropertyReadOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/app/Http/Controllers', 0777, true);

    file_put_contents($project . '/app/Http/Controllers/WithSearchPagination.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

trait WithSearchPagination
{
    private Request $requestPagination;
}
PHP);

    file_put_contents($project . '/app/Http/Controllers/SearchController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SearchController
{
    use WithSearchPagination;

    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->requestPagination = $request;
    }

    public function index(Request $request): mixed
    {
        $value = $request->search;
        $stored = $this->request->per_page;
        $sortBy = $this->requestPagination->sortBy;
        $isFirstStep = $request->step == 1;
        $isSecondStep = $this->request->step === 2;
        $hasSearch = isset($request->search);
        $request->search = 'changed';

        return [$value, $stored, $sortBy, $isFirstStep, $isSecondStep, $hasSearch];
    }

    public function helper(mixed $request): array
    {
        return [
            $request->input('name'),
            $request->method,
        ];
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/Http/Controllers/SearchController.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '$value = $request->input(\'search\');')
            && str_contains($overlay, '$stored = $this->request->input(\'per_page\');')
            && str_contains($overlay, '$sortBy = $this->requestPagination->input(\'sortBy\');')
            && str_contains($overlay, '$isFirstStep = $request->input(\'step\') == 1;')
            && str_contains($overlay, '$isSecondStep = $this->request->input(\'step\') === 2;')
            && str_contains($overlay, '$hasSearch = $request->input(\'search\') !== null;')
            && str_contains($overlay, '$request->merge([\'search\' => \'changed\']);')
            && str_contains($overlay, 'public function helper(\Illuminate\Http\Request $request): array')
            && str_contains($overlay, '$request->input(\'method\')')) {
            return;
        }
    }

    fail('Laravel request property read overlay did not rewrite dynamic input access safely');
}

function testLaravelRequestInputArrayVariableOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/app/Http/Controllers/Requests', 0777, true);

    file_put_contents($project . '/app/Http/Controllers/Requests/RequestInputController.php', <<<'PHP'
<?php

namespace App\Http\Controllers\Requests;

use Illuminate\Http\Request;

final class RequestInputController
{
    public function index(Request $request): array
    {
        $columns = $request->input('columns');
        $model = $request->input('model');

        foreach ($columns as $column) {
            $selected[] = $column;
        }

        return [$selected ?? [], $model];
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/Http/Controllers/Requests/RequestInputController.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var array<array-key, mixed> $columns */')
            && ! str_contains($overlay, '/** @var array<array-key, mixed> $model */')) {
            return;
        }
    }

    fail('Laravel request input overlay did not annotate array-like request variables safely');
}

function testLaravelJsonResourceDynamicMemberOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/app/Http/Resources', 0777, true);

    file_put_contents($project . '/app/Http/Resources/OrderResource.php', <<<'PHP'
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'issued_at' => $this->issued_at->format('Y-m-d'),
            'status_label' => $this->statusLabel(),
            'resource' => $this->resource,
        ];
    }
}
PHP);

    file_put_contents($project . '/app/Http/Resources/OrderCollection.php', <<<'PHP'
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class OrderCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        $orders = $this->resource;

        return $orders
            ->getCollection()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'issued_at' => $order->issued_at->format('Y-m-d'),
                ];
            })
            ->toArray();
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    $foundResource = false;
    $foundCollection = false;

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/Http/Resources/OrderResource.php' || ! is_string($entry['overlay'] ?? null)) {
            if (($entry['original'] ?? null) !== 'app/Http/Resources/OrderCollection.php' || ! is_string($entry['overlay'] ?? null)) {
                continue;
            }

            $overlay = file_get_contents($project . '/' . $entry['overlay']);

            if (is_string($overlay)
                && str_contains($overlay, '/** @var \Illuminate\Pagination\AbstractPaginator $orders */')
                && str_contains($overlay, '/** @var \Illuminate\Database\Eloquent\Model $order */')
                && str_contains($overlay, '$order->issued_at->format(\'Y-m-d\')')) {
                $foundCollection = true;
            }

            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '@mixin \Illuminate\Database\Eloquent\Model')
            && str_contains($overlay, '@property mixed $id')
            && str_contains($overlay, '@property mixed $issued_at')
            && ! str_contains($overlay, '@property mixed $resource')
            && str_contains($overlay, '@method mixed statusLabel(mixed ...$parameters)')) {
            $foundResource = true;
        }
    }

    if (! $foundResource || ! $foundCollection) {
        fail('Laravel JsonResource overlay did not document delegated resource members');
    }
}

function testLaravelFormRequestDynamicPropertyOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/app/Http/Requests/Orders', 0777, true);

    file_put_contents($project . '/app/Http/Requests/Orders/TracksTenant.php', <<<'PHP'
<?php

namespace App\Http\Requests\Orders;

trait TracksTenant
{
    private int $clienteSistemaId;
}
PHP);

    file_put_contents($project . '/app/Http/Requests/Orders/StoreOrderRequest.php', <<<'PHP'
<?php

namespace App\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    use TracksTenant;

    public function prepareForValidation(): void
    {
        $local = new \stdClass();
        $this->service = new \stdClass();
        $this->merge(['product' => $this->product]);
    }

    public function rules(): array
    {
        return [
            'quantity' => "lte:{$this->quantityLimit}",
        ];
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/Http/Requests/Orders/StoreOrderRequest.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '@property mixed $service')
            && str_contains($overlay, '@property mixed $product')
            && str_contains($overlay, '@property mixed $quantityLimit')
            && ! str_contains($overlay, '@property mixed $local')
            && ! str_contains($overlay, '@property mixed $clienteSistemaId')
            && str_contains($overlay, 'public function __set(string $key, mixed $value): void')) {
            return;
        }
    }

    fail('Laravel FormRequest overlay did not document dynamic request properties');
}
