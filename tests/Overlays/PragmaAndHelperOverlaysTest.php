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

    /**
     * @param model-property<User> $column
     * @param list<string> $columns
     * @param non-empty-list<int> $ids
     * @return list<array{content: list<string>, title: string}>
     */
    public function larastanColumn(string $column, array $columns, array $ids): array
    {
        return [['content' => [$column, ...$columns], 'title' => (string) $ids[0]]];
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

    if (str_contains($overlay, 'model-property<User>') || ! str_contains($overlay, '@param string $column')) {
        fail('source compatibility overlay did not translate Larastan model-property pseudo-types');
    }

    if (
        str_contains($overlay, 'list<string>')
        || str_contains($overlay, 'non-empty-list<int>')
        || ! str_contains($overlay, '@param array<int, string> $columns')
        || ! str_contains($overlay, '@param non-empty-array<int, int> $ids')
        || ! str_contains($overlay, '@return array<int, array{content: array<int, string>, title: string}>')
    ) {
        fail('source compatibility overlay did not translate PHPStan list pseudo-types');
    }

    if (! str_contains($overlay, '@method mixed getuser(mixed ...$arguments)')) {
        fail('source compatibility overlay did not add case-insensitive method aliases');
    }
}

function testLaravelPaginatorReturnDocblockOverlayGeneration(string $root): void
{
    require_once $root . '/src/Application.php';

    $source = <<<'PHP'
<?php

namespace App;

use Illuminate\Pagination\LengthAwarePaginator;

final class UsesPaginatorDocs
{
    /**
     * @return LengthAwarePaginator<mixed>
     */
    public function paginate(): LengthAwarePaginator
    {
        throw new \RuntimeException();
    }

    /**
     * @return \Illuminate\Contracts\Pagination\Paginator<int, string>
     */
    public function simple(): \Illuminate\Contracts\Pagination\Paginator
    {
        throw new \RuntimeException();
    }
}
PHP;

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'applySourceCompatibilityOverlayTransforms');
    $overlay = $method->invoke($application, $source, sys_get_temp_dir(), [], 'app/UsesPaginatorDocs.php', []);

    if (! is_string($overlay)
        || str_contains($overlay, 'LengthAwarePaginator<mixed>')
        || str_contains($overlay, 'Paginator<int, string>')
        || ! str_contains($overlay, '@return LengthAwarePaginator')
        || ! str_contains($overlay, '@return \\Illuminate\\Contracts\\Pagination\\Paginator')) {
        fail('source compatibility overlay did not normalize Laravel paginator return docblocks');
    }
}

function testLaravelDateHelperOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/routes', 0777, true);

    file_put_contents($project . '/app/UsesDateHelpers.php', <<<'PHP'
<?php

namespace App;

use Carbon\Carbon;

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
        $message = "Generated at {$now->toDateString()} for month {$now->month}";
        $parsed = now()->parse('2026-05-31');

        return [$now, $today, $response, $factory, $method, $static, $message, $parsed];
    }

    public function unauthorized(): \Illuminate\Http\JsonResponse
    {
        return response(['ok' => false], 401);
    }

    /**
     * @return JsonResponse
     */
    public function documentedUnauthorized()
    {
        return response(['ok' => false], 401);
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
            && str_contains($overlay, '\\Illuminate\\Support\\Facades\\Response::json([\'ok\' => false], 401)')
            && substr_count($overlay, '\\Illuminate\\Support\\Facades\\Response::json([\'ok\' => false], 401)') === 2
            && str_contains($overlay, 'response()->json([\'ok\' => true])')
            && str_contains($overlay, '"Generated at  for month {$now->month}"')
            && ! str_contains($overlay, '{$now->toDateString()}')
            && str_contains($overlay, '\\Illuminate\\Support\\Carbon::parse(\'2026-05-31\')')
            && ! str_contains($overlay, 'Carbon::now()->parse(')
            && str_contains($overlay, 'use Illuminate\\Support\\Carbon;')
            && ! str_contains($overlay, 'use Carbon\\Carbon;')
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

function testReflectionMethodCasingOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/UsesReflectionTypes.php', <<<'PHP'
<?php

namespace App;

use ReflectionMethod;
use ReflectionNamedType;

final class UsesReflectionTypes
{
    public function handle(ReflectionMethod $method): ?string
    {
        $type = $method->getreturntype();

        if (! $type instanceof ReflectionNamedType || $type->isbuiltin()) {
            return null;
        }

        return $type->getname();
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $substitutions = $method->invoke($application, $project, [], []);

    if (! is_array($substitutions) || count($substitutions) < 2) {
        fail('reflection method casing overlay generation returned unexpected substitutions');
    }

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);
    $overlayPath = null;

    foreach (is_array($map) ? $map : [] as $entry) {
        if (is_array($entry) && ($entry['original'] ?? null) === 'app/UsesReflectionTypes.php' && is_string($entry['overlay'] ?? null)) {
            $overlayPath = $entry['overlay'];
            break;
        }
    }

    $overlay = $overlayPath === null ? false : file_get_contents($project . '/' . $overlayPath);

    if (! is_string($overlay)
        || ! str_contains($overlay, '->getReturnType()')
        || ! str_contains($overlay, '->isBuiltin()')
        || ! str_contains($overlay, '->getName()')) {
        fail('source compatibility overlay did not normalize common Reflection method casing');
    }
}

function testInternalFunctionCompatibilityOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/UsesInternalFunctions.php', <<<'PHP'
<?php

namespace App;

final class UsesInternalFunctions
{
    public function handle(object $model, object $response): array
    {
        $year = date('Y', strtotime($model->created_at));
        $decoded = json_decode($response->getBody(), true);
        $body = json_decode($response->getBody());
        $id = uniqid(mt_rand(), true);
        $exif = exif_read_data($model->file);
        $name = iconv('UTF-8', 'ASCII//TRANSLIT', $model->name);
        $parts = array_map('trim', preg_split('/[;,\s]+/', $model->tags));
        $count = count(preg_split('/[;,\s]+/', $model->tags));
        $filled = array_filter(preg_split('/[;,\s]+/', $model->tags));
        $decodedAgain = json_decode(json_encode($model->payload), true);
        $decodedWrapped = json_decode((json_encode($model->payload)), true);
        $payloadDigest = md5(json_encode($model->payload));
        $message = is_string($model->message) ? $model->message : json_encode($model->message);
        $image = file_get_contents($model->path);
        $encodedFile = base64_encode(file_get_contents($model->path));
        $digest = md5(file_get_contents($model->path));
        $path = strstr($model->path, '/img');
        $suffixFromPath = ltrim($path, '/');
        $suffix = ltrim(strstr($model->path, '/img'), '/');
        $blob = base64_decode($model->encoded);
        $inflated = gzdecode(base64_decode($model->encoded));
        $decrypted = openssl_decrypt(base64_decode($model->secret), 'AES-128-CBC', $model->key);
        $color = imagecolorallocate($model->image, 255, 255, 255);
        $terms = preg_split('/\s+/', $model->search);
        $leaderboard = $model->redis->zrevrange('leaders', 0, -1, ['withscores' => true]);
        $clean = str_replace(['a'], '', $model->name);
        \Illuminate\Support\Facades\Storage::disk('local')->put($model->path, json_encode($model->payload));
        $storedImage = \Illuminate\Support\Facades\Storage::disk('r2')->get(strstr($model->path, '/img'));
        $encoding = mb_convert_encoding($model->body, 'UTF-8', mb_detect_encoding($model->body));
        if ($model->deleted_at == null) {
            $message .= ' deleted';
        }
        if ($model->offset != false) {
            $message .= ' offset';
        }
        $next = date(
            'Y-m-d',
            strtotime(
                '+1 day',
                strtotime($model->created_at),
            )
        );

        return [$year, $decoded, $body, $id, $exif, $name, $parts, $count, $filled, $decodedAgain, $decodedWrapped, $payloadDigest, $message, $image, $encodedFile, $digest, $suffixFromPath, $suffix, $blob, $inflated, $decrypted, $color, $terms, $leaderboard, $clean, $storedImage, $encoding, $next];
    }

    public function normalize(string $value): string
    {
        return preg_replace('/[^a-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $value));
    }

    public function clamp(object $model): int
    {
        return min($model->limit, 50);
    }

    public function remove(object $model): bool
    {
        return $model->delete();
    }

    public function enabled(object $model): bool
    {
        return $model->enabled;
    }

    public function logo(): string
    {
        return $this->getLogo();
    }

    private function getLogo(): ?string
    {
        return null;
    }

    public function expressionValue(object $expression, object $grammar): ?string
    {
        return $expression->getValue($grammar);
    }

    public function strip(?string $value): ?string
    {
        return $value !== null ? str_replace(['a'], '', $value) : null;
    }

    public function formatted(bool $useDashForZero): string
    {
        return $useDashForZero ? '-' : $this->formatMoney(0);
    }

    private function formatMoney(int $value): float|string
    {
        return (string) $value;
    }

    public function xml(string $value): \SimpleXMLElement
    {
        $xml = simplexml_load_string($value);

        if ($errors = libxml_get_errors()) {
            throw new \RuntimeException((string) $errors[0]->message);
        }

        return $xml;
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);
    $foundOverlay = false;

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/UsesInternalFunctions.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, 'date(\'Y\', strtotime((string) $model->created_at))')
            && str_contains($overlay, 'json_decode((string) $response->getBody(), true)')
            && str_contains($overlay, 'json_decode((string) $response->getBody())')
            && str_contains($overlay, 'uniqid((string) mt_rand(), true)')
            && str_contains($overlay, 'exif_read_data((string) $model->file)')
            && str_contains($overlay, '$name = (string) iconv(\'UTF-8\', \'ASCII//TRANSLIT\', $model->name)')
            && str_contains($overlay, "array_map('trim', (preg_split('/[;,\\s]+/', \$model->tags) ?: []))")
            && str_contains($overlay, "count((preg_split('/[;,\\s]+/', \$model->tags) ?: []))")
            && str_contains($overlay, "array_filter((preg_split('/[;,\\s]+/', \$model->tags) ?: []))")
            && str_contains($overlay, 'json_decode((string) json_encode($model->payload), true)')
            && str_contains($overlay, 'json_decode(((string) json_encode($model->payload)), true)')
            && str_contains($overlay, 'md5((string) json_encode($model->payload))')
            && str_contains($overlay, 'is_string($model->message) ? $model->message : (string) json_encode($model->message)')
            && str_contains($overlay, '$image = (string) file_get_contents($model->path)')
            && str_contains($overlay, 'base64_encode((string) file_get_contents($model->path))')
            && str_contains($overlay, 'md5((string) file_get_contents($model->path))')
            && str_contains($overlay, '$path = (string) strstr($model->path, \'/img\')')
            && str_contains($overlay, "ltrim((string) strstr(\$model->path, '/img'), '/')")
            && str_contains($overlay, '$blob = (string) base64_decode($model->encoded)')
            && str_contains($overlay, 'gzdecode((string) base64_decode($model->encoded))')
            && str_contains($overlay, "openssl_decrypt((string) base64_decode(\$model->secret), 'AES-128-CBC', \$model->key)")
            && str_contains($overlay, '$color = (int) imagecolorallocate($model->image, 255, 255, 255)')
            && str_contains($overlay, "\$terms = (preg_split('/\\s+/', \$model->search) ?: [])")
            && str_contains($overlay, "\$leaderboard = \$model->redis->zrevrange('leaders', 0, -1, ['withscores' => true]) ?: []")
            && str_contains($overlay, '$clean = (string) str_replace([\'a\'], \'\', $model->name)')
            && str_contains($overlay, 'return (int) min($model->limit, 50)')
            && str_contains($overlay, 'return (bool) $model->delete();')
            && str_contains($overlay, 'return (bool) $model->enabled;')
            && str_contains($overlay, 'return (string) $this->getLogo();')
            && str_contains($overlay, 'return (string) $expression->getValue($grammar);')
            && str_contains($overlay, "return \$value !== null ? (string) str_replace(['a'], '', \$value) : null;")
            && str_contains($overlay, "return \$useDashForZero ? '-' : (string) \$this->formatMoney(0);")
            && str_contains($overlay, '// @mago-ignore analysis:null-operand analysis:false-operand' . PHP_EOL . '        if ($model->deleted_at === null) {')
            && str_contains($overlay, '// @mago-ignore analysis:null-operand analysis:false-operand' . PHP_EOL . '        if ($model->offset !== false) {')
            && str_contains($overlay, 'if (! $xml instanceof \\SimpleXMLElement) {' . PHP_EOL . '            throw new \\RuntimeException(\'Invalid XML document.\');' . PHP_EOL . '        }' . PHP_EOL . PHP_EOL . '        return $xml;')
            && str_contains($overlay, "\\Illuminate\\Support\\Facades\\Storage::disk('local')->put(\$model->path, (string) json_encode(\$model->payload))")
            && str_contains($overlay, "\\Illuminate\\Support\\Facades\\Storage::disk('r2')->get((string) strstr(\$model->path, '/img'))")
            && str_contains($overlay, "\$encoding = (string) mb_convert_encoding(\$model->body, 'UTF-8', (string) mb_detect_encoding(\$model->body))")
            && str_contains($overlay, 'strtotime((string) $model->created_at)')
            && str_contains($overlay, "return (string) preg_replace('/[^a-z]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', \$value))")
            && substr_count($overlay, '@mago-ignore analysis:possibly-false-argument analysis:invalid-argument analysis:nullable-return-statement analysis:invalid-return-statement analysis:falsable-return-statement') === 6) {
            $foundOverlay = true;
        }
    }

    if (! $foundOverlay) {
        fail('source compatibility overlay did not normalize internal function stringable/false-returning pipelines');
    }
}

function testLaravelCommandReturnOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/SyncCatalogCommand.php', <<<'PHP'
<?php

namespace App;

use Illuminate\Console\Command;

final class SyncCatalogCommand extends Command
{
    public function handle(): int
    {
        if (true) {
            return true;
        }

        return false;
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/SyncCatalogCommand.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, 'return self::SUCCESS;')
            && str_contains($overlay, 'return self::FAILURE;')) {
            return;
        }
    }

    fail('source compatibility overlay did not normalize Laravel command bool return codes');
}

function testLaravelHttpClientWrapperReturnTypeOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    mkdir($project . '/app/Traits', 0777, true);

    file_put_contents($project . '/app/Traits/UsesHttpClientTrait.php', <<<'PHP'
<?php

namespace App\Traits;

use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

trait UsesHttpClientTrait
{
    public function traitRequest(): JsonResponse|Response
    {
        if (false) {
            return response()->json(['error' => true]);
        }

        return Http::get('/trait')->throw();
    }
}
PHP);

    file_put_contents($project . '/app/UsesHttpClientWrapper.php', <<<'PHP'
<?php

namespace App;

use App\Traits\UsesHttpClientTrait;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Promises\LazyPromise;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

final class UsesHttpClientWrapper
{
    use UsesHttpClientTrait;

    private function sendRequest(string $ncm): LazyPromise|PromiseInterface|Response
    {
        return Http::retry(2, 100)->get('/ibpt/' . $ncm)->throw();
    }

    public function maybeJson(): PromiseInterface|JsonResponse|Response
    {
        if (false) {
            return response()->json(['error' => true]);
        }

        return Http::post('/search')->throw();
    }

    public function queued(): PromiseInterface|Response
    {
        return Http::async()->get('/later');
    }

    public function useWrapper(): array
    {
        $response = $this->maybeJson();

        if (! $response->successful()) {
            return [];
        }

        return $response->json();
    }

    public function useTraitWrapper(): array
    {
        $response = $this->traitRequest();

        if (! $response->successful()) {
            return [];
        }

        return $response->json();
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/UsesHttpClientWrapper.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, 'private function sendRequest(string $ncm): Response')
            && str_contains($overlay, 'public function maybeJson(): JsonResponse|Response')
            && str_contains($overlay, 'public function queued(): PromiseInterface|Response')
            && str_contains($overlay, '/** @var \Illuminate\Http\Client\Response $response */' . PHP_EOL . '        $response = $this->maybeJson();')
            && str_contains($overlay, '/** @var \Illuminate\Http\Client\Response $response */' . PHP_EOL . '        $response = $this->traitRequest();')) {
            return;
        }
    }

    fail('Laravel HTTP client wrapper overlay did not narrow synchronous promise return types safely');
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
            && str_contains($overlay, '/** @var \Illuminate\Support\Collection $laramagoCollectionMacroThis */')
            && str_contains($overlay, '$laramagoCollectionMacroThis = new \Illuminate\Support\Collection();')
            && str_contains($overlay, '$laramagoCollectionMacroThis->forPage(1, $perPage)->values()')) {
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

function testLaravelCollectionArrowCallbackOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/UsesCollectionArrowCallbacks.php', <<<'PHP'
<?php

namespace App;

use App\Models\Order;

final class UsesCollectionArrowCallbacks
{
    public function rows(): array
    {
        return Order::query()
            ->get()
            ->map(fn (Order $order): array => ['id' => $order->id])
            ->filter(fn (array $row): bool => $row !== [])
            ->map(fn (int $id): int => $id)
            ->map(fn (mixed $value, string $key): string => $key . ':' . $value)
            ->map(function (Order $order, string $key): array {
                return ['key' => $key, 'id' => $order->id];
            })
            ->all();
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/UsesCollectionArrowCallbacks.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '->map(fn ($order): array =>')
            && str_contains($overlay, '->filter(fn (array $row): bool =>')
            && str_contains($overlay, '->map(fn ($id): int =>')
            && str_contains($overlay, '->map(fn (mixed $value, $key): string =>')
            && str_contains($overlay, '->map(function ($order, $key): array {')
            && ! str_contains($overlay, 'fn (Order $order)')
            && ! str_contains($overlay, 'fn (mixed $value, string $key)')
            && ! str_contains($overlay, 'function (Order $order, string $key)')) {
            return;
        }
    }

    fail('Laravel collection arrow callback overlay did not loosen object parameter types');
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
        $name = $row->profile->name ?? '-';
        $config = $row->model->first();

        return $subtotal - $discount;
    }

    public function reduced(object $rows): int|float
    {
        $totals = $rows->reduce(function ($carry, $row) {
            $carry->amount += $row->amount;
            $carry->discount += $row->discount;

            return $carry;
        }, (object)['amount' => 0, 'discount' => 0.0]);

        return $totals->amount - $totals->discount;
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
            && str_contains($overlay, '/** @var int|float $discount */')
            && str_contains($overlay, '/** @var object{amount: int|float, discount: int|float} $totals */')
            && str_contains($overlay, '// @mago-ignore analysis:invalid-property-access' . PHP_EOL . '        $name = $row->profile->name ?? \'-\';')
            && str_contains($overlay, '// @mago-ignore analysis:dynamic-static-method-call' . PHP_EOL . '        $config = $row->model->first();')) {
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

function testLaravelThrowHelperInstanceGuardOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/UsesThrowHelpers.php', <<<'PHP'
<?php

namespace App;

use App\Tags\Tag;
use RuntimeException;

final class UsesThrowHelpers
{
    public function tag(): Tag
    {
        $record = $this->record;

        throw_unless($record instanceof Tag, RuntimeException::class, 'Tag page record missing.');

        return $record;
    }

    public function strictTag(): \App\Tags\Tag
    {
        $record = $this->record;

        throw_if(! ($record instanceof \App\Tags\Tag), RuntimeException::class);

        return $record;
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/UsesThrowHelpers.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var Tag $record */')
            && str_contains($overlay, '/** @var \\App\\Tags\\Tag $record */')) {
            return;
        }
    }

    fail('Laravel throw helper overlay did not annotate guarded instance types');
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
