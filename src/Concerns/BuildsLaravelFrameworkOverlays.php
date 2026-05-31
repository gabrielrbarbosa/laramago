<?php

declare(strict_types=1);

namespace Laramago\Concerns;

trait BuildsLaravelFrameworkOverlays
{
    private function laravelFrameworkSubstitutions(string $projectRoot, array $arguments): array
    {
        if (in_array('--no-laravel-framework-overlays', $arguments, true)) {
            return [];
        }

        $overlays = [];

        $guardPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Contracts/Auth/Guard.php';
        $authManagerPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Auth/AuthManager.php';
        $authFacadePath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Support/Facades/Auth.php';
        $applicationContractPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Contracts/Foundation/Application.php';
        $httpFacadePath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Support/Facades/Http.php';
        $pendingRequestPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php';
        $optionalPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Support/Optional.php';
        $supportCarbonPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Support/Carbon.php';
        $baseCarbonPath = $projectRoot . '/vendor/nesbot/carbon/src/Carbon/Carbon.php';
        $baseCarbonImmutablePath = $projectRoot . '/vendor/nesbot/carbon/src/Carbon/CarbonImmutable.php';
        $foundationHelpersPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Foundation/helpers.php';
        $eloquentBuilderPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php';
        $eloquentModelPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php';
        $hasAttributesPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php';
        $queryBuilderPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php';
        $controllerMiddlewareOptionsPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Routing/ControllerMiddlewareOptions.php';
        $requestPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Http/Request.php';
        $resourceCollectionPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Http/Resources/Json/ResourceCollection.php';
        $anonymousResourceCollectionPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Http/Resources/Json/AnonymousResourceCollection.php';
        $abstractPaginatorPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php';
        $paginatorContractPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Contracts/Pagination/Paginator.php';
        $notificationPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Notifications/Notification.php';
        $shouldBroadcastPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Contracts/Broadcasting/ShouldBroadcast.php';
        $hasFactoryPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/HasFactory.php';
        $scopePath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Scope.php';
        $fromCollectionPath = $projectRoot . '/vendor/maatwebsite/excel/src/Concerns/FromCollection.php';
        $socialiteProviderPath = $projectRoot . '/vendor/laravel/socialite/src/Contracts/Provider.php';
        $socialiteUserPath = $projectRoot . '/vendor/laravel/socialite/src/Two/User.php';

        $authModel = $this->detectAuthUserModel($projectRoot);

        if ($authModel !== null) {
            $authModel = '\\' . ltrim($authModel, '\\');

            if (is_file($guardPath)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Guard.php', $guardPath, $this->renderAuthGuardOverlay($authModel));
            }

            if (is_file($authManagerPath)) {
                $authManagerSource = file_get_contents($authManagerPath);

                if (is_string($authManagerSource)) {
                    $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'AuthManager.php', $authManagerPath, $this->renderAuthManagerOverlay($authManagerSource, $authModel));
                }
            }

            if (is_file($authFacadePath)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Auth.php', $authFacadePath, $this->renderAuthFacadeOverlay($authModel));
            }

            if (is_file($foundationHelpersPath)) {
                $foundationHelpersSource = file_get_contents($foundationHelpersPath);

                if (is_string($foundationHelpersSource)) {
                    $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'FoundationHelpers.php', $foundationHelpersPath, $this->renderFoundationHelpersOverlay($foundationHelpersSource));
                }
            }
        }

        if (is_file($httpFacadePath)) {
            $httpFacadeSource = file_get_contents($httpFacadePath);

            if (is_string($httpFacadeSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Http.php', $httpFacadePath, $this->renderHttpFacadeOverlay($httpFacadeSource));
            }
        }

        if (is_file($pendingRequestPath)) {
            $pendingRequestSource = file_get_contents($pendingRequestPath);

            if (is_string($pendingRequestSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'PendingRequest.php', $pendingRequestPath, $this->renderPendingRequestOverlay($pendingRequestSource));
            }
        }

        if (is_file($optionalPath)) {
            $optionalSource = file_get_contents($optionalPath);

            if (is_string($optionalSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Optional.php', $optionalPath, $this->renderOptionalOverlay($optionalSource, $projectRoot, $arguments));
            }
        }

        if (is_file($applicationContractPath)) {
            $applicationContractSource = file_get_contents($applicationContractPath);

            if (is_string($applicationContractSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'ApplicationContract.php', $applicationContractPath, $this->renderApplicationContractOverlay($applicationContractSource));
            }
        }

        if (is_file($supportCarbonPath)) {
            $supportCarbonSource = file_get_contents($supportCarbonPath);

            if (is_string($supportCarbonSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'SupportCarbon.php', $supportCarbonPath, $this->renderSupportCarbonOverlay($supportCarbonSource));
            }
        }

        if (is_file($baseCarbonPath)) {
            $baseCarbonSource = file_get_contents($baseCarbonPath);

            if (is_string($baseCarbonSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'BaseCarbon.php', $baseCarbonPath, $this->renderCarbonDateOverlay($baseCarbonSource, 'Carbon', '\\Carbon\\Carbon'));
            }
        }

        if (is_file($baseCarbonImmutablePath)) {
            $baseCarbonImmutableSource = file_get_contents($baseCarbonImmutablePath);

            if (is_string($baseCarbonImmutableSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'BaseCarbonImmutable.php', $baseCarbonImmutablePath, $this->renderCarbonDateOverlay($baseCarbonImmutableSource, 'CarbonImmutable', '\\Carbon\\CarbonImmutable'));
            }
        }

        if (is_file($eloquentBuilderPath)) {
            $eloquentBuilderSource = file_get_contents($eloquentBuilderPath);

            if (is_string($eloquentBuilderSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Builder.php', $eloquentBuilderPath, $this->renderEloquentBuilderOverlay($eloquentBuilderSource));
            }
        }

        if (is_file($eloquentModelPath)) {
            $eloquentModelSource = file_get_contents($eloquentModelPath);

            if (is_string($eloquentModelSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'EloquentModel.php', $eloquentModelPath, $this->renderEloquentModelFrameworkOverlay($eloquentModelSource));
            }
        }

        if (is_file($hasAttributesPath)) {
            $hasAttributesSource = file_get_contents($hasAttributesPath);

            if (is_string($hasAttributesSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'HasAttributes.php', $hasAttributesPath, $this->renderHasAttributesOverlay($hasAttributesSource));
            }
        }

        if (is_file($queryBuilderPath)) {
            $queryBuilderSource = file_get_contents($queryBuilderPath);

            if (is_string($queryBuilderSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'QueryBuilder.php', $queryBuilderPath, $this->renderQueryBuilderOverlay($queryBuilderSource));
            }
        }

        if (is_file($controllerMiddlewareOptionsPath)) {
            $controllerMiddlewareOptionsSource = file_get_contents($controllerMiddlewareOptionsPath);

            if (is_string($controllerMiddlewareOptionsSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'ControllerMiddlewareOptions.php', $controllerMiddlewareOptionsPath, $this->renderControllerMiddlewareOptionsOverlay($controllerMiddlewareOptionsSource));
            }
        }

        if (is_file($requestPath)) {
            $requestSource = file_get_contents($requestPath);

            if (is_string($requestSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Request.php', $requestPath, $this->renderRequestOverlay($requestSource));
            }
        }

        if (is_file($resourceCollectionPath)) {
            $resourceCollectionSource = file_get_contents($resourceCollectionPath);

            if (is_string($resourceCollectionSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'ResourceCollection.php', $resourceCollectionPath, $this->renderResourceCollectionOverlay($resourceCollectionSource, 'ResourceCollection'));
            }
        }

        if (is_file($anonymousResourceCollectionPath)) {
            $anonymousResourceCollectionSource = file_get_contents($anonymousResourceCollectionPath);

            if (is_string($anonymousResourceCollectionSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'AnonymousResourceCollection.php', $anonymousResourceCollectionPath, $this->renderResourceCollectionOverlay($anonymousResourceCollectionSource, 'AnonymousResourceCollection'));
            }
        }

        if (is_file($abstractPaginatorPath)) {
            $abstractPaginatorSource = file_get_contents($abstractPaginatorPath);

            if (is_string($abstractPaginatorSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'AbstractPaginator.php', $abstractPaginatorPath, $this->renderAbstractPaginatorOverlay($abstractPaginatorSource));
            }
        }

        if (is_file($paginatorContractPath)) {
            $paginatorContractSource = file_get_contents($paginatorContractPath);

            if (is_string($paginatorContractSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'PaginatorContract.php', $paginatorContractPath, $this->renderPaginatorContractOverlay($paginatorContractSource));
            }
        }

        if (is_file($notificationPath)) {
            $notificationSource = file_get_contents($notificationPath);

            if (is_string($notificationSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Notification.php', $notificationPath, $this->renderNotificationOverlay($notificationSource, $projectRoot, $arguments));
            }
        }

        if (is_file($shouldBroadcastPath)) {
            $shouldBroadcastSource = file_get_contents($shouldBroadcastPath);

            if (is_string($shouldBroadcastSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'ShouldBroadcast.php', $shouldBroadcastPath, $this->renderShouldBroadcastOverlay($shouldBroadcastSource));
            }
        }

        if (is_file($hasFactoryPath)) {
            $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'HasFactory.php', $hasFactoryPath, $this->renderHasFactoryOverlay());
        }

        if (is_file($scopePath)) {
            $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Scope.php', $scopePath, $this->renderScopeOverlay());
        }

        if (is_file($fromCollectionPath)) {
            $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'FromCollection.php', $fromCollectionPath, $this->renderFromCollectionOverlay());
        }

        if (is_file($socialiteProviderPath)) {
            $socialiteProviderSource = file_get_contents($socialiteProviderPath);

            if (is_string($socialiteProviderSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'SocialiteProvider.php', $socialiteProviderPath, $this->renderSocialiteProviderOverlay($socialiteProviderSource));
            }
        }

        if (is_file($socialiteUserPath)) {
            $socialiteUserSource = file_get_contents($socialiteUserPath);

            if (is_string($socialiteUserSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'SocialiteUser.php', $socialiteUserPath, $this->renderSocialiteUserOverlay($socialiteUserSource));
            }
        }

        $substitutions = [];

        foreach ($overlays as $overlay) {
            if ($overlay === null) {
                continue;
            }

            $substitutions[] = '--substitute';
            $substitutions[] = $overlay['original'] . '=' . $overlay['overlay'];
        }

        return $substitutions;
    }

    private function renderEloquentBuilderOverlay(string $source): string
    {
        $source = str_replace(
            [
                '@param  int|null|\Closure  $perPage',
                '@param  int|null  $perPage',
                '@param  int|null  $page',
                '@param  \Closure|int|null  $total',
            ],
            [
                '@param  int|string|null|\Closure  $perPage',
                '@param  int|string|null  $perPage',
                '@param  int|string|null  $page',
                '@param  \Closure|int|string|null  $total',
            ],
            $source,
        );

        return $this->insertClassDocblockLines($source, 'Builder', [
            ' * @method $this join(string $table, mixed $first, ?string $operator = null, mixed $second = null, string $type = "inner", bool $where = false)',
            ' * @method $this leftJoin(string $table, mixed $first, ?string $operator = null, mixed $second = null)',
            ' * @method $this rightJoin(string $table, mixed $first, ?string $operator = null, mixed $second = null)',
            ' * @method $this crossJoin(string $table, mixed $first = null, ?string $operator = null, mixed $second = null)',
            ' * @method $this groupBy(array|string ...$groups)',
            ' * @method $this having(string $column, ?string $operator = null, mixed $value = null, string $boolean = "and")',
            ' * @method $this orHaving(string $column, ?string $operator = null, mixed $value = null)',
            ' * @method $this select(mixed ...$columns)',
            ' * @method $this addSelect(array|string ...$columns)',
            ' * @method $this with(array|string ...$relations)',
            ' * @method $this selectRaw(mixed $expression, array $bindings = [])',
            ' * @method $this selectraw(mixed $expression, array $bindings = [])',
            ' * @method $this whereLike(string $column, mixed $value, bool $caseSensitive = false, string $boolean = "and", bool $not = false)',
            ' * @method $this whereIntegerInRaw(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method $this whereIntegerNotInRaw(string $column, mixed $values, string $boolean = "and")',
            ' * @method $this whereintegerinraw(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method $this whereintegernotinraw(string $column, mixed $values, string $boolean = "and")',
            ' * @method $this withoutGlobalScope(mixed $scope)',
            ' * @method $this withoutGlobalScopes(array|null $scopes = null)',
            ' * @method $this withoutglobalscope(mixed $scope)',
            ' * @method $this withoutglobalscopes(array|null $scopes = null)',
            ' * @method $this onlyTrashed()',
            ' * @method $this withTrashed(bool $withTrashed = true)',
            ' * @method $this withoutTrashed()',
            ' * @method $this onlytrashed()',
            ' * @method \Illuminate\Database\Query\Builder toBase()',
            ' * @method \Illuminate\Database\Query\Builder tobase()',
        ]);
    }

    private function renderAuthManagerOverlay(string $source, string $authModel): string
    {
        return $this->insertClassDocblockLines($source, 'AuthManager', [
            ' * @method ' . $authModel . '|null user()',
            ' * @method int|string|null id()',
            ' * @method bool check()',
            ' * @method bool guest()',
        ]);
    }

    private function renderFoundationHelpersOverlay(string $source): string
    {
        return str_replace(
            [
                '@return ($guard is null ? \Illuminate\Contracts\Auth\Factory : \Illuminate\Contracts\Auth\Guard)',
                'function auth($guard = null): AuthFactory|Guard',
                'function now($tz = null): CarbonInterface',
                'function today($tz = null): CarbonInterface',
            ],
            [
                '@return ($guard is null ? \Illuminate\Auth\AuthManager : \Illuminate\Contracts\Auth\Guard)',
                'function auth($guard = null): \Illuminate\Auth\AuthManager|Guard',
                'function now($tz = null): \Illuminate\Support\Carbon',
                'function today($tz = null): \Illuminate\Support\Carbon',
            ],
            $source,
        );
    }

    private function renderHttpFacadeOverlay(string $source): string
    {
        return str_replace(
            [
                '\Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface get(',
                '\Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface head(',
                '\Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface post(',
                '\Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface patch(',
                '\Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface put(',
                '\Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface delete(',
                '\Illuminate\Http\Client\Response|\Illuminate\Http\Client\Promises\LazyPromise send(',
            ],
            [
                '\Illuminate\Http\Client\Response get(',
                '\Illuminate\Http\Client\Response head(',
                '\Illuminate\Http\Client\Response post(',
                '\Illuminate\Http\Client\Response patch(',
                '\Illuminate\Http\Client\Response put(',
                '\Illuminate\Http\Client\Response delete(',
                '\Illuminate\Http\Client\Response send(',
            ],
            $source,
        );
    }

    private function renderPendingRequestOverlay(string $source): string
    {
        return str_replace(
            [
                '@return \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface',
                '@return \Illuminate\Http\Client\Response|\Illuminate\Http\Client\Promises\LazyPromise',
                '@phpstan-return (TAsync is false ?  \Illuminate\Http\Client\Response : \GuzzleHttp\Promise\PromiseInterface)',
                '@phpstan-return (TAsync is false ? \Illuminate\Http\Client\Response : \Illuminate\Http\Client\Promises\LazyPromise)',
            ],
            [
                '@return \Illuminate\Http\Client\Response',
                '@return \Illuminate\Http\Client\Response',
                '@return \Illuminate\Http\Client\Response',
                '@return \Illuminate\Http\Client\Response',
            ],
            $source,
        );
    }

    private function renderOptionalOverlay(string $source, string $projectRoot, array $arguments): string
    {
        $members = $this->optionalDynamicMembers($projectRoot, $arguments);
        $lines = [];

        foreach ($members['properties'] as $property) {
            $lines[] = ' * @property mixed $' . $property;
        }

        foreach ($members['methods'] as $method) {
            $lines[] = ' * @method mixed ' . $method . '(mixed ...$parameters)';
        }

        if ($lines === []) {
            return $source;
        }

        return $this->insertClassDocblockLines($source, 'Optional', $lines);
    }

    private function optionalDynamicMembers(string $projectRoot, array $arguments): array
    {
        $methods = [];
        $properties = [];
        $seenFiles = [];
        $config = $this->projectConfigValues($projectRoot);

        foreach ($this->sourceOverlayPaths($projectRoot, $arguments, $config['paths']) as $path) {
            foreach ($this->sourcePhpFiles($projectRoot, $path) as $file) {
                if (isset($seenFiles[$file])) {
                    continue;
                }

                $seenFiles[$file] = true;
                $relativePath = ltrim(substr($file, strlen($projectRoot)), '/');

                if ($this->isExcludedProjectPath($relativePath, $config['excludes'])) {
                    continue;
                }

                $source = file_get_contents($file);

                if (! is_string($source) || ! str_contains($source, 'optional(')) {
                    continue;
                }

                if (preg_match_all('/\boptional\s*\([^;\r\n]*?\)\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches) !== false) {
                    foreach ($matches[1] ?? [] as $method) {
                        $methods[strtolower($method)] = true;
                    }
                }

                if (preg_match_all('/\boptional\s*\([^;\r\n]*?\)\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*\()/', $source, $matches) !== false) {
                    foreach ($matches[1] ?? [] as $property) {
                        $properties[$property] = true;
                    }
                }
            }
        }

        $methodNames = array_keys($methods);
        $propertyNames = array_keys($properties);
        sort($methodNames);
        sort($propertyNames);

        return [
            'methods' => $methodNames,
            'properties' => $propertyNames,
        ];
    }

    private function renderApplicationContractOverlay(string $source): string
    {
        if (str_contains($source, 'function isProduction(')) {
            return $source;
        }

        return $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Determine if the application environment is production.
     */
    public function isProduction(): bool;
PHP);
    }

    private function renderResourceCollectionOverlay(string $source, string $className): string
    {
        return $this->insertClassDocblockLines($source, $className, [
            ' * @mixin \Illuminate\Support\Collection<array-key, mixed>',
            ' * @method array all()',
            ' * @method mixed first(callable|null $callback = null, mixed $default = null)',
            ' * @method \Illuminate\Support\Collection map(callable $callback)',
        ]);
    }

    private function renderAbstractPaginatorOverlay(string $source): string
    {
        $source = $this->insertClassDocblockLines($source, 'AbstractPaginator', [
            ' * @property mixed $data',
            ' * @method mixed first(callable|null $callback = null, mixed $default = null)',
            ' * @method $this makeHidden(array|string|null $attributes)',
            ' * @method $this makeVisible(array|string|null $attributes)',
            ' * @method $this setHidden(array $hidden)',
            ' * @method $this setVisible(array $visible)',
            ' * @method int total()',
        ]);

        if (str_contains($source, 'function __get(') && str_contains($source, 'function __set(')) {
            return $source;
        }

        return $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    public function __get(string $key): mixed
    {
        return null;
    }

    public function __set(string $key, mixed $value): void
    {
    }
PHP);
    }

    private function renderPaginatorContractOverlay(string $source): string
    {
        return $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Return the first item from the paginator collection.
     */
    public function first(?callable $callback = null, mixed $default = null): mixed;
PHP);
    }

    private function renderSupportCarbonOverlay(string $source): string
    {
        return $this->renderCarbonDateOverlay($source, 'Carbon', '\\Illuminate\\Support\\Carbon');
    }

    private function renderCarbonDateOverlay(string $source, string $className, string $staticReturnType): string
    {
        return $this->insertClassDocblockLines($source, $className, $this->carbonAliasDocblockLines($staticReturnType));
    }

    private function carbonAliasDocblockLines(string $staticReturnType): array
    {
        return [
            ' * @method static ' . $staticReturnType . ' parse(mixed $time = null, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' createfromformat(string $format, mixed $time, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' now(mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' today(mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' tomorrow(mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' yesterday(mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' make(mixed $var, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' instance(\DateTimeInterface $date)',
            ' * @method static ' . $staticReturnType . ' createfrominterface(\DateTimeInterface $date)',
            ' * @method static ' . $staticReturnType . ' createfromtimestamp(mixed $timestamp, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' createfromtimestampms(mixed $timestamp, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' createfromdate(?int $year = null, ?int $month = null, ?int $day = null, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' createfromtime(?int $hour = null, ?int $minute = null, ?int $second = null, ?int $microsecond = null, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' create(int $year = 0, int $month = 1, int $day = 1, int $hour = 0, int $minute = 0, int $second = 0, mixed $timezone = null)',
            ' * @method float diffinseconds(mixed $date = null, bool $absolute = false)',
            ' * @method $this addseconds(int|float $value = 1)',
            ' * @method $this addminutes(int|float $value = 1)',
            ' * @method $this addhours(int|float $value = 1)',
            ' * @method $this adddays(int|float $value = 1)',
            ' * @method $this addday()',
            ' * @method $this addmonths(int|float $value = 1)',
            ' * @method $this addmonth()',
            ' * @method $this addmonthnooverflow(int|float $value = 1)',
            ' * @method $this addyears(int|float $value = 1)',
            ' * @method $this addyear()',
            ' * @method $this subseconds(int|float $value = 1)',
            ' * @method $this subminutes(int|float $value = 1)',
            ' * @method $this subhours(int|float $value = 1)',
            ' * @method $this subdays(int|float $value = 1)',
            ' * @method $this subday()',
            ' * @method $this submonths(int|float $value = 1)',
            ' * @method $this submonth()',
            ' * @method $this submonthnooverflow(int|float $value = 1)',
            ' * @method $this subyears(int|float $value = 1)',
            ' * @method $this subyear()',
            ' * @method $this startofday()',
            ' * @method $this endofday()',
            ' * @method $this startofmonth()',
            ' * @method $this endofmonth()',
            ' * @method $this firstofmonth(mixed $dayOfWeek = null)',
            ' * @method $this lastofmonth(mixed $dayOfWeek = null)',
            ' * @method mixed weekday(?int $value = null)',
            ' * @method string todatetimestring(string $unitPrecision = "second")',
            ' * @method string toiso8601string()',
            ' * @method string torfc2822string()',
            ' * @method string gettranslatedmonthname(?string $context = null, ?string $key = null, mixed $locale = null)',
        ];
    }

    private function renderEloquentModelFrameworkOverlay(string $source): string
    {
        $source = str_replace(
            [
                'public function load($relations)',
                'public function loadMissing($relations)',
                'public function loadCount($relations)',
                'protected function increment($column, $amount = 1, array $extra = [])',
                'protected function decrement($column, $amount = 1, array $extra = [])',
            ],
            [
                'public function load($relations, ...$additionalRelations)',
                'public function loadMissing($relations, ...$additionalRelations)',
                'public function loadCount($relations, ...$additionalRelations)',
                'public function increment($column, $amount = 1, array $extra = [])',
                'public function decrement($column, $amount = 1, array $extra = [])',
            ],
            $source,
        );

        return $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function create(array $attributes = []): static
    {
        return new static;
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function withoutGlobalScope(mixed $scope): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function withoutGlobalScopes(?array $scopes = null): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function orWhere(mixed $column, mixed $operator = null, mixed $value = null): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function select(mixed ...$columns): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function selectRaw(mixed $expression, array $bindings = []): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function orderBy(mixed $column, mixed $direction = 'asc'): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     *
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public static function lockForUpdate(): \Illuminate\Database\Eloquent\Builder
    {
        return static::query();
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     *
     * @return static|\Illuminate\Database\Eloquent\Collection<int, static>|null
     */
    public static function find(mixed $id, array|string $columns = ['*']): mixed
    {
        return null;
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     *
     * @return static|\Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function findOrFail(mixed $id, array|string $columns = ['*']): mixed
    {
        return new static;
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function firstOrCreate(array $attributes = [], array $values = []): static
    {
        return new static;
    }

    /**
     * Laramago overlay for Laravel's dynamic static builder delegation.
     */
    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        return new static;
    }

PHP);
    }

    private function insertBeforeFinalClassBrace(string $source, string $code): string
    {
        $position = strrpos($source, '}');

        if ($position === false) {
            return $source;
        }

        return substr($source, 0, $position) . $code . PHP_EOL . substr($source, $position);
    }

    private function renderHasAttributesOverlay(string $source): string
    {
        return str_replace(
            [
                'public function only($attributes)',
                'public function except($attributes)',
            ],
            [
                'public function only($attributes, ...$additionalAttributes)',
                'public function except($attributes, ...$additionalAttributes)',
            ],
            $source,
        );
    }

    private function renderQueryBuilderOverlay(string $source): string
    {
        $source = str_replace(
            [
                'public function select($columns = [\'*\'])',
                'public function addSelect($column)',
                'public function distinct()',
                '@param  SortDirection|\'asc\'|\'desc\'  $direction',
                '@param  int  $value',
                '@param  int  $page',
                '@param  int  $perPage',
                '@param  int|null  $page',
                '@param  \Closure|int|null  $total',
                '@param  \Illuminate\Contracts\Database\Query\Expression|literal-string  $sql',
                '@param  literal-string  $sql',
                '@return \Illuminate\Pagination\LengthAwarePaginator',
                '@return \Illuminate\Contracts\Pagination\Paginator',
            ],
            [
                'public function select($columns = [\'*\'], ...$additionalColumns)',
                'public function addSelect($column, ...$additionalColumns)',
                'public function distinct(...$columns)',
                '@param  SortDirection|string  $direction',
                '@param  int|string|null  $value',
                '@param  int|string  $page',
                '@param  int|string  $perPage',
                '@param  int|string|null  $page',
                '@param  \Closure|int|string|null  $total',
                '@param  \Illuminate\Contracts\Database\Query\Expression|string  $sql',
                '@param  string  $sql',
                '@return \Illuminate\Pagination\LengthAwarePaginator<array-key, mixed>',
                '@return \Illuminate\Contracts\Pagination\Paginator<array-key, mixed>',
            ],
            $source,
        );

        if (! str_contains($source, 'function first(')) {
            $source = $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * @param array|string $columns
     * @return \stdClass|null
     */
    public function first($columns = ['*']): ?\stdClass
    {
        throw new \LogicException('Laramago analysis overlay.');
    }

    /**
     * @param array|string $columns
     * @param string|null $message
     * @return \stdClass
     */
    public function firstOrFail($columns = ['*'], $message = null): \stdClass
    {
        throw new \LogicException('Laramago analysis overlay.');
    }

    /**
     * @param array|string $columns
     * @return \stdClass
     */
    public function sole($columns = ['*']): \stdClass
    {
        throw new \LogicException('Laramago analysis overlay.');
    }
PHP);
        }

        return $this->insertClassDocblockLines($source, 'Builder', [
            ' * @method $this selectRaw(mixed $expression, array $bindings = [])',
            ' * @method $this selectraw(mixed $expression, array $bindings = [])',
            ' * @method $this whereLike(string $column, mixed $value, bool $caseSensitive = false, string $boolean = "and", bool $not = false)',
            ' * @method $this whereIntegerInRaw(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method $this whereIntegerNotInRaw(string $column, mixed $values, string $boolean = "and")',
            ' * @method $this whereintegerinraw(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method $this whereintegernotinraw(string $column, mixed $values, string $boolean = "and")',
            ' * @method $this withoutGlobalScope(mixed $scope)',
            ' * @method $this withoutGlobalScopes(array|null $scopes = null)',
            ' * @method $this withoutglobalscope(mixed $scope)',
            ' * @method $this withoutglobalscopes(array|null $scopes = null)',
            ' * @method \Illuminate\Database\Query\Builder toBase()',
            ' * @method \Illuminate\Database\Query\Builder tobase()',
        ]);
    }

    private function renderControllerMiddlewareOptionsOverlay(string $source): string
    {
        return str_replace(
            [
                'public function only($methods)',
                'public function except($methods)',
            ],
            [
                'public function only($methods, ...$additionalMethods)',
                'public function except($methods, ...$additionalMethods)',
            ],
            $source,
        );
    }

    private function renderRequestOverlay(string $source): string
    {
        if (str_contains($source, 'function __set(')) {
            return $source;
        }

        return $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Laramago overlay for Laravel applications that assign request-backed dynamic state.
     */
    public function __set(string $key, mixed $value): void
    {
    }
PHP);
    }

    private function renderNotificationOverlay(string $source, string $projectRoot, array $arguments): string
    {
        $members = $this->notificationDynamicMembers($projectRoot, $arguments);
        $declarations = [];

        foreach ($members['methods'] as $method) {
            if (! preg_match('/^\s*public\s+function\s+' . preg_quote($method, '/') . '\s*\(/im', $source)) {
                $declarations[] = '    public function ' . $method . '(mixed $notifiable): mixed {}';
            }
        }

        if ($members['properties'] !== [] && ! str_contains($source, 'function __get(')) {
            $declarations[] = <<<'PHP'
    public function __get(string $key): mixed
    {
        return null;
    }
PHP;
        }

        if ($declarations === []) {
            return $source;
        }

        return $this->insertBeforeFinalClassBrace($source, PHP_EOL . implode(PHP_EOL . PHP_EOL, $declarations) . PHP_EOL);
    }

    private function renderShouldBroadcastOverlay(string $source): string
    {
        return str_replace(
            '@return \Illuminate\Broadcasting\Channel|\Illuminate\Broadcasting\Channel[]|string[]|string',
            '@return mixed',
            $source,
        );
    }

    private function notificationDynamicMembers(string $projectRoot, array $arguments): array
    {
        $methods = [];
        $properties = [];
        $seenFiles = [];
        $config = $this->projectConfigValues($projectRoot);

        foreach ($this->sourceOverlayPaths($projectRoot, $arguments, $config['paths']) as $path) {
            foreach ($this->sourcePhpFiles($projectRoot, $path) as $file) {
                if (isset($seenFiles[$file])) {
                    continue;
                }

                $seenFiles[$file] = true;
                $relativePath = ltrim(substr($file, strlen($projectRoot)), '/');

                if ($this->isExcludedProjectPath($relativePath, $config['excludes'])) {
                    continue;
                }

                $source = file_get_contents($file);

                if (! is_string($source)) {
                    continue;
                }

                if (preg_match_all('/\$notification\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches) !== false) {
                    foreach ($matches[1] ?? [] as $method) {
                        $methods[strtolower($method)] = true;
                    }
                }

                if (preg_match_all('/\$notification\s*->\s*([A-Za-z_][A-Za-z0-9_]*)\b(?!\s*\()/', $source, $matches) !== false) {
                    foreach ($matches[1] ?? [] as $property) {
                        $properties[$property] = true;
                    }
                }
            }
        }

        $methodNames = array_keys($methods);
        $propertyNames = array_keys($properties);
        sort($methodNames);
        sort($propertyNames);

        return [
            'methods' => $methodNames,
            'properties' => $propertyNames,
        ];
    }

    private function renderSocialiteProviderOverlay(string $source): string
    {
        $source = str_replace(
            '@return \Laravel\Socialite\Contracts\User',
            '@return \Laravel\Socialite\Contracts\User|null',
            $source,
        );

        if (! str_contains($source, 'function with(')) {
            $source = $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Set request parameters for the provider.
     */
    public function with(array $parameters): static;
PHP);
        }

        if (! str_contains($source, 'function scopes(')) {
            $source = $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Set scopes for the provider.
     */
    public function scopes(array $scopes): static;
PHP);
        }

        if (! str_contains($source, 'function stateless(')) {
            $source = $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Indicate that the provider should operate statelessly.
     */
    public function stateless(): static;
PHP);
        }

        return $source;
    }

    private function renderSocialiteUserOverlay(string $source): string
    {
        if (str_contains($source, 'function setAccessTokenResponseBody(')) {
            return $source;
        }

        return $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Store the raw access token response body used by SocialiteProviders.
     */
    public function setAccessTokenResponseBody(array $body): static
    {
        return $this;
    }
PHP);
    }

    private function renderScopeOverlay(): string
    {
        return <<<'PHP'
<?php

namespace Illuminate\Database\Eloquent;

interface Scope
{
    public function apply(Builder $builder, Model $model);
}
PHP;
    }

    private function renderFromCollectionOverlay(): string
    {
        return <<<'PHP'
<?php

namespace Maatwebsite\Excel\Concerns;

use Illuminate\Support\Enumerable;

interface FromCollection
{
    public function collection(): Enumerable;
}
PHP;
    }

    private function renderHasFactoryOverlay(): string
    {
        return <<<'PHP'
<?php

namespace Illuminate\Database\Eloquent\Factories;

trait HasFactory
{
    /**
     * @param (callable(array<string, mixed>, static|null): array<string, mixed>)|array<string, mixed>|int|null $count
     * @param (callable(array<string, mixed>, static|null): array<string, mixed>)|array<string, mixed> $state
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>
     */
    public static function factory($count = null, $state = [])
    {
        throw new \LogicException('Laramago analysis overlay.');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>|null
     */
    protected static function newFactory()
    {
        return null;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Factories\Factory<static>|null
     */
    protected static function getUseFactoryAttribute()
    {
        return null;
    }
}
PHP;
    }

    private function renderAuthGuardOverlay(string $authModel): string
    {
        return <<<PHP
<?php

namespace Illuminate\Contracts\Auth;

interface Guard
{
    public function check();

    public function guest();

    /**
     * @return {$authModel}|null
     */
    public function user();

    /**
     * @return int|string|null
     */
    public function id();

    public function validate(array \$credentials = []);

    public function hasUser();

    public function setUser(Authenticatable \$user);
}
PHP;
    }

    private function renderAuthFacadeOverlay(string $authModel): string
    {
        return <<<PHP
<?php

namespace Illuminate\Support\Facades;

/**
 * @method static \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard guard(\UnitEnum|string|null \$name = null)
 * @method static bool check()
 * @method static bool guest()
 * @method static {$authModel}|null user()
 * @method static int|string|null id()
 * @method static bool validate(array \$credentials = [])
 * @method static bool hasUser()
 * @method static \Illuminate\Contracts\Auth\Guard setUser(\Illuminate\Contracts\Auth\Authenticatable \$user)
 * @method static bool attempt(array \$credentials = [], bool \$remember = false)
 * @method static bool once(array \$credentials = [])
 * @method static void login(\Illuminate\Contracts\Auth\Authenticatable \$user, bool \$remember = false)
 * @method static {$authModel}|false loginUsingId(mixed \$id, bool \$remember = false)
 * @method static {$authModel}|false onceUsingId(mixed \$id)
 * @method static bool viaRemember()
 * @method static void logout()
 * @method static {$authModel}|null getUser()
 * @method static {$authModel} authenticate()
 * @method static {$authModel}|null getLastAttempted()
 * @method static {$authModel}|null logoutOtherDevices(string \$password)
 *
 * @see \Illuminate\Auth\AuthManager
 * @see \Illuminate\Auth\SessionGuard
 */
class Auth extends Facade
{
}
PHP;
    }

    private function writeFrameworkOverlay(string $projectRoot, string $fileName, string $originalPath, string $source): ?array
    {
        $overlayPath = $projectRoot . '/' . self::FRAMEWORK_OVERLAY_DIR . '/' . $fileName;
        $this->ensureDirectory(dirname($overlayPath));

        if (file_put_contents($overlayPath, $source) === false) {
            return null;
        }

        return [
            'original' => $originalPath,
            'overlay' => $overlayPath,
        ];
    }

    private function detectAuthUserModel(string $projectRoot): ?string
    {
        $configPath = $projectRoot . '/config/auth.php';

        if (! is_file($configPath)) {
            return is_file($projectRoot . '/app/Models/User.php') ? 'App\\Models\\User' : null;
        }

        $source = file_get_contents($configPath);

        if (! is_string($source)) {
            return null;
        }

        if (preg_match('/[\'"]model[\'"]\s*=>\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class/', $source, $matches) === 1) {
            $class = $matches[1];

            if (str_contains($class, '\\')) {
                return ltrim($class, '\\');
            }

            return $this->importedClassName($source, $class) ?? 'App\\Models\\' . $class;
        }

        if (preg_match('/[\'"]model[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) === 1) {
            return str_replace('\\\\', '\\', $matches[1]);
        }

        return is_file($projectRoot . '/app/Models/User.php') ? 'App\\Models\\User' : null;
    }

    private function importedClassName(string $source, string $shortName): ?string
    {
        if (preg_match_all('/^use\s+([^;]+);/m', $source, $matches) === 0) {
            return null;
        }

        foreach ($matches[1] as $import) {
            $import = trim($import);
            $alias = $import;

            if (preg_match('/^(.+)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $import, $aliasMatches) === 1) {
                $import = trim($aliasMatches[1]);
                $alias = trim($aliasMatches[2]);
            }

            if (substr($alias, strrpos($alias, '\\') === false ? 0 : strrpos($alias, '\\') + 1) === $shortName) {
                return ltrim($import, '\\');
            }
        }

        return null;
    }

    private function classReferenceName(string $source, string $reference): ?string
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        if (str_starts_with($reference, '\\')) {
            return ltrim($reference, '\\');
        }

        if (! str_contains($reference, '\\')) {
            return $this->importedClassName($source, $reference) ?? $this->namespacedClassName($source, $reference);
        }

        [$firstSegment, $remaining] = explode('\\', $reference, 2);
        $import = $this->importedClassName($source, $firstSegment);

        if ($import !== null) {
            return $import . '\\' . $remaining;
        }

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $matches) === 1) {
            return trim($matches[1]) . '\\' . $reference;
        }

        return $reference;
    }
}
