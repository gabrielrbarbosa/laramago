<?php

declare(strict_types=1);

function testCaseInsensitiveOverlaySkipsSingleAliasFiles(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/OneAlias.php', <<<'PHP'
<?php

namespace App;

final class OneAlias
{
    public function getUser(): mixed
    {
        return null;
    }

    public function run(): void
    {
        $this->getUser();
    }
}
PHP);

    file_put_contents($project . '/app/TwoAliases.php', <<<'PHP'
<?php

namespace App;

final class TwoAliases
{
    public function getUser(): mixed
    {
        return null;
    }

    public function getToken(): mixed
    {
        return null;
    }

    public function run(): void
    {
        $this->getUser();
        $this->getToken();
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);
    $originals = array_column(is_array($map) ? $map : [], 'original');

    if (in_array('app/OneAlias.php', $originals, true)) {
        fail('case-insensitive overlay should skip source files with only one alias');
    }

    if (! in_array('app/TwoAliases.php', $originals, true)) {
        fail('case-insensitive overlay should include source files with multiple aliases');
    }
}

function testLaravelRequestClassInstantiationOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/RequestFactories.php', <<<'PHP'
<?php

namespace App;

use Illuminate\Http\Request;
use Knuckles\Scribe\Scribe;

final class RequestFactories
{
    public function boot(): void
    {
        Scribe::instantiateFormRequestUsing(function (string $className) {
            $formRequest = new $className();

            return $formRequest;
        });
    }

    public function fromReflection(?\ReflectionParameter $requestParameter): Request
    {
        $requestClass = $requestParameter?->getType()->getName() ?? Request::class;

        return new $requestClass();
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/RequestFactories.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay)
            && str_contains($overlay, '/** @var class-string<\\Illuminate\\Foundation\\Http\\FormRequest> $className */')
            && str_contains($overlay, '/** @var class-string<\\Illuminate\\Http\\Request> $requestClass */')) {
            return;
        }
    }

    fail('Laravel request class instantiation overlay did not annotate request class strings');
}

function testCaseInsensitiveOverlayRespectsExcludes(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    $configPath = $project . '/mago.toml';
    $originalConfig = file_get_contents($configPath);

    if (! is_string($originalConfig)) {
        fail('unable to read test project config');
    }

    file_put_contents($configPath, <<<'TOML'
version = "1"
php-version = "8.5.0"

[source]
workspace = "."
paths = ["app"]
includes = []
excludes = ["app/Excluded/**"]

[source.glob]
literal-separator = true
TOML);

    if (! is_dir($project . '/app/Excluded')) {
        mkdir($project . '/app/Excluded');
    }
    file_put_contents($project . '/app/Excluded/TwoAliases.php', <<<'PHP'
<?php

namespace App\Excluded;

final class TwoAliases
{
    public function getUser(): mixed
    {
        return null;
    }

    public function getToken(): mixed
    {
        return null;
    }

    public function run(): void
    {
        $this->getUser();
        $this->getToken();
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    file_put_contents($configPath, $originalConfig);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);
    $originals = array_column(is_array($map) ? $map : [], 'original');

    if (in_array('app/Excluded/TwoAliases.php', $originals, true)) {
        fail('case-insensitive overlay should not substitute excluded files');
    }
}

function testTraitSelfCallOverlayGeneration(string $project, string $root): void
{
    require_once $root . '/src/Application.php';

    file_put_contents($project . '/app/RequiresHostMethods.php', <<<'PHP'
<?php

namespace App;

trait RequiresHostMethods
{
    public function indexName(): string
    {
        return $this->getTable();
    }
}
PHP);

    $application = new Laramago\Application();
    $method = new ReflectionMethod($application, 'phpStanPragmaSubstitutions');
    $method->invoke($application, $project, [], []);

    $map = json_decode((string) file_get_contents($project . '/.laramago/cache/phpstan-pragma-overlays.json'), true);

    foreach (is_array($map) ? $map : [] as $entry) {
        if (($entry['original'] ?? null) !== 'app/RequiresHostMethods.php' || ! is_string($entry['overlay'] ?? null)) {
            continue;
        }

        $overlay = file_get_contents($project . '/' . $entry['overlay']);

        if (is_string($overlay) && str_contains($overlay, '@method mixed gettable(mixed ...$arguments)')) {
            return;
        }
    }

    fail('trait self-call overlay did not declare host-provided methods');
}
