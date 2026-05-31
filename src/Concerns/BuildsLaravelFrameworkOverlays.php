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
        $supportCollectionPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Collections/Collection.php';
        $supportCarbonPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Support/Carbon.php';
        $supportNumberPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Support/Number.php';
        $baseCarbonPath = $projectRoot . '/vendor/nesbot/carbon/src/Carbon/Carbon.php';
        $baseCarbonImmutablePath = $projectRoot . '/vendor/nesbot/carbon/src/Carbon/CarbonImmutable.php';
        $foundationHelpersPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Foundation/helpers.php';
        $eloquentBuilderPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php';
        $eloquentModelPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php';
        $eloquentRelationPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Relations/Relation.php';
        $hasAttributesPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php';
        $queryBuilderPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php';
        $controllerMiddlewareOptionsPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Routing/ControllerMiddlewareOptions.php';
        $requestPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Http/Request.php';
        $formRequestPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Foundation/Http/FormRequest.php';
        $interactsWithInputPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Http/Concerns/InteractsWithInput.php';
        $resourceCollectionPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Http/Resources/Json/ResourceCollection.php';
        $anonymousResourceCollectionPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Http/Resources/Json/AnonymousResourceCollection.php';
        $abstractPaginatorPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Pagination/AbstractPaginator.php';
        $paginatorContractPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Contracts/Pagination/Paginator.php';
        $notificationPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Notifications/Notification.php';
        $shouldBroadcastPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Contracts/Broadcasting/ShouldBroadcast.php';
        $validationExceptionPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Validation/ValidationException.php';
        $hasFactoryPath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/HasFactory.php';
        $scopePath = $projectRoot . '/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Scope.php';
        $fromCollectionPath = $projectRoot . '/vendor/maatwebsite/excel/src/Concerns/FromCollection.php';
        $socialiteProviderPath = $projectRoot . '/vendor/laravel/socialite/src/Contracts/Provider.php';
        $socialiteTwoProviderPath = $projectRoot . '/vendor/laravel/socialite/src/Two/ProviderInterface.php';
        $socialiteUserPath = $projectRoot . '/vendor/laravel/socialite/src/Two/User.php';

        $authModel = $this->detectAuthUserModel($projectRoot);

        if ($authModel !== null) {
            $authModel = '\\' . ltrim($authModel, '\\');
            $authIdentifierType = $this->authIdentifierType($projectRoot, $authModel);

            if (is_file($guardPath)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Guard.php', $guardPath, $this->renderAuthGuardOverlay($authModel, $authIdentifierType));
            }

            if (is_file($authManagerPath)) {
                $authManagerSource = file_get_contents($authManagerPath);

                if (is_string($authManagerSource)) {
                    $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'AuthManager.php', $authManagerPath, $this->renderAuthManagerOverlay($authManagerSource, $authModel, $authIdentifierType));
                }
            }

            if (is_file($authFacadePath)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Auth.php', $authFacadePath, $this->renderAuthFacadeOverlay($authModel, $authIdentifierType));
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

        if (is_file($supportCollectionPath)) {
            $supportCollectionSource = file_get_contents($supportCollectionPath);

            if (is_string($supportCollectionSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'SupportCollection.php', $supportCollectionPath, $this->renderSupportCollectionOverlay($supportCollectionSource, $projectRoot, $arguments));
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

        if (is_file($supportNumberPath)) {
            $supportNumberSource = file_get_contents($supportNumberPath);

            if (is_string($supportNumberSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'SupportNumber.php', $supportNumberPath, $this->renderSupportNumberOverlay($supportNumberSource));
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
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Builder.php', $eloquentBuilderPath, $this->renderEloquentBuilderOverlay($eloquentBuilderSource, $projectRoot, $arguments));
            }
        }

        if (is_file($eloquentModelPath)) {
            $eloquentModelSource = file_get_contents($eloquentModelPath);

            if (is_string($eloquentModelSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'EloquentModel.php', $eloquentModelPath, $this->renderEloquentModelFrameworkOverlay($eloquentModelSource));
            }
        }

        if (is_file($eloquentRelationPath)) {
            $eloquentRelationSource = file_get_contents($eloquentRelationPath);

            if (is_string($eloquentRelationSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'Relation.php', $eloquentRelationPath, $this->renderEloquentRelationOverlay($eloquentRelationSource, $projectRoot, $arguments));
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

        if (is_file($formRequestPath)) {
            $formRequestSource = file_get_contents($formRequestPath);

            if (is_string($formRequestSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'FormRequest.php', $formRequestPath, $this->renderFormRequestOverlay($formRequestSource));
            }
        }

        if (is_file($interactsWithInputPath)) {
            $interactsWithInputSource = file_get_contents($interactsWithInputPath);

            if (is_string($interactsWithInputSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'InteractsWithInput.php', $interactsWithInputPath, $this->renderInteractsWithInputOverlay($interactsWithInputSource));
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

        if (is_file($validationExceptionPath)) {
            $validationExceptionSource = file_get_contents($validationExceptionPath);

            if (is_string($validationExceptionSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'ValidationException.php', $validationExceptionPath, $this->renderValidationExceptionOverlay($validationExceptionSource));
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

        if (is_file($socialiteTwoProviderPath)) {
            $socialiteTwoProviderSource = file_get_contents($socialiteTwoProviderPath);

            if (is_string($socialiteTwoProviderSource)) {
                $overlays[] = $this->writeFrameworkOverlay($projectRoot, 'SocialiteTwoProvider.php', $socialiteTwoProviderPath, $this->renderSocialiteTwoProviderOverlay($socialiteTwoProviderSource));
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

    private function renderEloquentBuilderOverlay(string $source, string $projectRoot, array $arguments): string
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

        $methodLines = [
            ' * @method $this join(mixed $table, mixed $first, ?string $operator = null, mixed $second = null, string $type = "inner", bool $where = false)',
            ' * @method $this leftJoin(mixed $table, mixed $first, ?string $operator = null, mixed $second = null)',
            ' * @method $this rightJoin(mixed $table, mixed $first, ?string $operator = null, mixed $second = null)',
            ' * @method $this crossJoin(mixed $table, mixed $first = null, ?string $operator = null, mixed $second = null)',
            ' * @method $this groupBy(mixed ...$groups)',
            ' * @method $this having(string $column, ?string $operator = null, mixed $value = null, string $boolean = "and")',
            ' * @method $this orHaving(string $column, ?string $operator = null, mixed $value = null)',
            ' * @method $this where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = "and")',
            ' * @method $this orWhere(mixed $column, mixed $operator = null, mixed $value = null)',
            ' * @method $this whereIn(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method $this whereNotIn(string $column, mixed $values)',
            ' * @method $this whereBetween(string $column, mixed $values, string $boolean = "and", bool $not = false)',
            ' * @method $this whereNull(string|array $columns, string $boolean = "and", bool $not = false)',
            ' * @method $this whereNotNull(string|array $columns)',
            ' * @method $this whereDate(string $column, mixed $operator, mixed $value = null, string $boolean = "and")',
            ' * @method $this select(mixed ...$columns)',
            ' * @method $this addSelect(array|string ...$columns)',
            ' * @method $this reorder(mixed $column = null, mixed $direction = "asc")',
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
        ];

        $source = $this->insertClassDocblockLines($source, 'Builder', array_merge(
            $methodLines,
            $this->projectEloquentScopeMethodLines($projectRoot, $arguments),
        ));

        if (! str_contains($source, 'function first(')) {
            $source = $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Execute the query and get the first result.
     *
     * @param  array|string  $columns
     * @return TModel|null
     */
    public function first($columns = ['*'])
    {
    }
PHP);
        }

        foreach ($this->eloquentBuilderForwardedMethods() as $method => $code) {
            if (! str_contains($source, 'function ' . $method . '(')) {
                $source = $this->insertBeforeFinalClassBrace($source, $code);
            }
        }

        return $source;
    }

    private function eloquentBuilderForwardedMethods(): array
    {
        return [
            'orderBy' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder ordering.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function orderBy(mixed $column, mixed $direction = 'asc'): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'orderByDesc' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder ordering.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function orderByDesc(mixed $column): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'where' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder conditions.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'orWhere' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder conditions.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function orWhere(mixed $column, mixed $operator = null, mixed $value = null): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'whereIn' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder conditions.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function whereIn(string $column, mixed $values, string $boolean = 'and', bool $not = false): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'whereNotIn' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder conditions.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function whereNotIn(string $column, mixed $values): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'whereBetween' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder conditions.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function whereBetween(string $column, mixed $values, string $boolean = 'and', bool $not = false): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'whereNull' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder null conditions.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function whereNull(string|array $columns, string $boolean = 'and', bool $not = false): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'whereNotNull' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder null conditions.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function whereNotNull(string|array $columns): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'whereDate' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder date conditions.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function whereDate(string $column, mixed $operator, mixed $value = null, string $boolean = 'and'): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'reorder' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder ordering.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function reorder(mixed $column = null, mixed $direction = 'asc'): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'selectRaw' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder raw selects.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function selectRaw(mixed $expression, array $bindings = []): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'groupBy' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder grouping.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function groupBy(mixed ...$groups): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'skip' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder offsets.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function skip(mixed $value): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'take' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder limits.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function take(mixed $value): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'offset' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder offsets.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function offset(mixed $value): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
            'limit' => <<<'PHP'

    /**
     * Laramago overlay for forwarded query builder limits.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    public function limit(mixed $value): \Illuminate\Database\Eloquent\Builder
    {
        return $this;
    }
PHP,
        ];
    }

    private function renderAuthManagerOverlay(string $source, string $authModel, string $authIdentifierType): string
    {
        return $this->insertClassDocblockLines($source, 'AuthManager', [
            ' * @method ' . $authModel . '|null user()',
            ' * @method ' . $authIdentifierType . ' id()',
            ' * @method bool check()',
            ' * @method bool guest()',
        ]);
    }

    private function renderFoundationHelpersOverlay(string $source): string
    {
        return str_replace(
            [
                '@return ($guard is null ? \Illuminate\Contracts\Auth\Factory : \Illuminate\Contracts\Auth\Guard)',
                '@return ($key is null ? \Illuminate\Contracts\Translation\Translator : array|string)',
                'function auth($guard = null): AuthFactory|Guard',
                'function now($tz = null): CarbonInterface',
                'function today($tz = null): CarbonInterface',
                'function trans($key = null, $replace = [], $locale = null): Translator|array|string',
                " * @param  string|null  \$locale\n     */\n    function __",
                'function __($key = null, $replace = [], $locale = null): string|array|null',
            ],
            [
                '@return ($guard is null ? \Illuminate\Auth\AuthManager : \Illuminate\Contracts\Auth\Guard)',
                '@return ($key is null ? \Illuminate\Contracts\Translation\Translator : string)',
                'function auth($guard = null): \Illuminate\Auth\AuthManager|Guard',
                'function now($tz = null): \Illuminate\Support\Carbon',
                'function today($tz = null): \Illuminate\Support\Carbon',
                'function trans($key = null, $replace = [], $locale = null): Translator|string',
                " * @param  string|null  \$locale\n     * @return (\$key is null ? null : string)\n     */\n    function __",
                'function __($key = null, $replace = [], $locale = null): ?string',
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
                '@param  \Psr\Http\Message\StreamInterface|string  $content',
                '@param  string|resource  $contents',
            ],
            [
                '@return \Illuminate\Http\Client\Response',
                '@return \Illuminate\Http\Client\Response',
                '@return \Illuminate\Http\Client\Response',
                '@return \Illuminate\Http\Client\Response',
                '@param  \Psr\Http\Message\StreamInterface|\Stringable|false|string  $content',
                '@param  \Illuminate\Http\UploadedFile|\SplFileInfo|\Stringable|resource|string  $contents',
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

    private function renderSupportCollectionOverlay(string $source, string $projectRoot, array $arguments): string
    {
        $source = str_replace(
            [
                '@return static<array-key, mixed>',
                '@param  TKey|null  $key',
            ],
            [
                '@return \Illuminate\Support\Collection<array-key, mixed>',
                '@param  TKey|false|null  $key',
            ],
            $source,
        );

        $lines = $this->projectCollectionMacroMethodLines($projectRoot, $arguments);

        if ($lines === []) {
            return $source;
        }

        return $this->insertClassDocblockLines($source, 'Collection', $lines);
    }

    private function projectCollectionMacroMethodLines(string $projectRoot, array $arguments): array
    {
        $macros = [];
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

                if (! is_string($source) || ! str_contains($source, 'macro(')) {
                    continue;
                }

                if (preg_match_all('/(?:\\\\?Illuminate\\\\Support\\\\)?Collection::macro\s*\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/', $source, $matches) === false) {
                    continue;
                }

                foreach ($matches[1] ?? [] as $macro) {
                    $macros[$macro] = true;
                }
            }
        }

        $lines = [];

        foreach (array_keys($macros) as $macro) {
            $lines[] = $macro === 'paginate'
                ? ' * @method \Illuminate\Pagination\LengthAwarePaginator paginate(mixed $perPage = null, mixed $total = null, mixed $page = null, string $pageName = "page")'
                : ' * @method mixed ' . $macro . '(mixed ...$parameters)';
        }

        sort($lines);

        return $lines;
    }

    private function renderApplicationContractOverlay(string $source): string
    {
        if (! str_contains($source, 'ArrayAccess')) {
            $source = preg_replace_callback(
                '/interface\s+Application(\s+extends\s+[^{]+)?\s*\{/',
                static function (array $matches): string {
                    $extends = trim((string) ($matches[1] ?? ''));

                    if ($extends === '') {
                        return 'interface Application extends \ArrayAccess {';
                    }

                    return 'interface Application ' . $extends . ', \ArrayAccess {';
                },
                $source,
                1,
            ) ?? $source;
        }

        $declarations = [];

        if (! str_contains($source, 'function isProduction(')) {
            $declarations[] = <<<'PHP'

    /**
     * Determine if the application environment is production.
     */
    public function isProduction(): bool;
PHP;
        }

        if (! str_contains($source, 'function offsetGet(')) {
            $declarations[] = <<<'PHP'

    /**
     * Laramago overlay for Laravel container array access.
     */
    public function offsetGet(mixed $key): mixed;
PHP;
        }

        if ($declarations === []) {
            return $source;
        }

        return $this->insertBeforeFinalClassBrace($source, implode(PHP_EOL, $declarations));
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

    private function renderSupportNumberOverlay(string $source): string
    {
        return str_replace('@return string|false', '@return string', $source);
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
            ' * @method static ' . $staticReturnType . ' createfromdate(mixed $year = null, mixed $month = null, mixed $day = null, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' createfromtime(mixed $hour = null, mixed $minute = null, mixed $second = null, mixed $microsecond = null, mixed $timezone = null)',
            ' * @method static ' . $staticReturnType . ' create(mixed $year = 0, mixed $month = 1, mixed $day = 1, mixed $hour = 0, mixed $minute = 0, mixed $second = 0, mixed $timezone = null)',
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
            ' * @method $this locale(string $locale, string ...$fallbackLocales)',
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
                '@param  array<string, mixed>  $attributes',
                '@param  array<string, mixed>  $options',
            ],
            [
                'public function load($relations, ...$additionalRelations)',
                'public function loadMissing($relations, ...$additionalRelations)',
                'public function loadCount($relations, ...$additionalRelations)',
                'public function increment($column, $amount = 1, array $extra = [])',
                'public function decrement($column, $amount = 1, array $extra = [])',
                '@param  array<array-key, mixed>  $attributes',
                '@param  array<array-key, mixed>  $options',
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

    private function renderEloquentRelationOverlay(string $source, string $projectRoot, array $arguments): string
    {
        $source = $this->insertClassDocblockLines($source, 'Relation', $this->projectEloquentScopeMethodLines($projectRoot, $arguments, 'static'));

        if (str_contains($source, 'function withoutGlobalScopes(')) {
            return $source;
        }

        return $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Laramago overlay for Laravel's decorated relation builder delegation.
     */
    public function withoutGlobalScope(mixed $scope): static
    {
        return $this;
    }

    /**
     * Laramago overlay for Laravel's decorated relation builder delegation.
     */
    public function withoutGlobalScopes(?array $scopes = null): static
    {
        return $this;
    }

    /**
     * Laramago overlay for Laravel's decorated relation builder delegation.
     */
    public function withoutGlobalScopesExcept(array $scopes = []): static
    {
        return $this;
    }
PHP);
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

        $source = preg_replace(
            '/(\*\s+Retrieve the sum of the values of a given column\.[\s\S]*?@return\s+)mixed(\s+\*\/\s+public function sum\()/',
            '$1int|float$2',
            $source,
        ) ?? $source;

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

        if (! str_contains($source, 'function when(')) {
            $source = $this->insertBeforeFinalClassBrace($source, <<<'PHP'

    /**
     * Laramago overlay for Conditionable fluent query chains.
     *
     * @return $this
     */
    public function when(mixed $value = null, ?callable $callback = null, ?callable $default = null): static
    {
        return $this;
    }

    /**
     * Laramago overlay for Conditionable fluent query chains.
     *
     * @return $this
     */
    public function unless(mixed $value = null, ?callable $callback = null, ?callable $default = null): static
    {
        return $this;
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

    private function projectEloquentScopeMethodLines(string $projectRoot, array $arguments, string $returnType = '\\Illuminate\\Database\\Eloquent\\Builder<TModel>'): array
    {
        $scopes = [];
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

                if (! is_string($source) || (! str_contains($source, 'scope') && ! str_contains($source, 'Scope'))) {
                    continue;
                }

                if (preg_match_all('/\bfunction\s+scope([A-Z][A-Za-z0-9_]*)\s*\(/', $source, $matches) !== false) {
                    foreach ($matches[1] ?? [] as $scope) {
                        $scopes[lcfirst($scope)] = true;
                    }
                }

                if (preg_match_all('/#\[\s*(?:\\\\?Illuminate\\\\Database\\\\Eloquent\\\\Attributes\\\\)?Scope\s*\]\s*(?:(?:public|protected|private)\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches) !== false) {
                    foreach ($matches[1] ?? [] as $scope) {
                        $scopes[$scope] = true;
                    }
                }
            }
        }

        foreach (array_keys($scopes) as $scope) {
            $lowercaseScope = strtolower($scope);

            if ($lowercaseScope !== $scope) {
                $scopes[$lowercaseScope] = true;
            }
        }

        $lines = [];

        foreach (array_keys($scopes) as $scope) {
            $lines[] = ' * @method ' . $returnType . ' ' . $scope . '(mixed ...$parameters)';
        }

        sort($lines);

        return $lines;
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
        $declarations = [];

        if (! str_contains($source, 'function safe(')) {
            $declarations[] = <<<'PHP'

    /**
     * Laramago overlay for Laravel's controller validation safe input helper.
     *
     * @return \Illuminate\Support\ValidatedInput
     */
    public function safe(?array $keys = null): \Illuminate\Support\ValidatedInput
    {
        throw new \LogicException('Laramago analysis overlay.');
    }
PHP;
        }

        if (! str_contains($source, 'function __set(')) {
            $declarations[] = <<<'PHP'

    /**
     * Laramago overlay for Laravel applications that assign request-backed dynamic state.
     */
    public function __set(string $key, mixed $value): void
    {
    }
PHP;
        }

        if ($declarations === []) {
            return $source;
        }

        return $this->insertBeforeFinalClassBrace($source, implode(PHP_EOL, $declarations));
    }

    private function renderFormRequestOverlay(string $source): string
    {
        if (! str_contains($source, 'function safe(')) {
            return $source;
        }

        $source = preg_replace(
            '/(^[ \t]*\*\s*)@return\s+\([^\r\n]*ValidatedInput[^\r\n]*\)$/m',
            '$1@return \\Illuminate\\Support\\ValidatedInput',
            $source,
        ) ?? $source;

        $source = preg_replace(
            '/public function safe\(\?array \$keys = null\)(?:\s*:\s*[^{\r\n]+)?/',
            'public function safe(?array $keys = null): \\Illuminate\\Support\\ValidatedInput',
            $source,
            1,
        ) ?? $source;

        return $this->replaceFunctionBodies($source, static function (string $header, string $body): string {
            if (! str_contains($header, 'function safe(')) {
                return $body;
            }

            return <<<'PHP'
{
        throw new \LogicException('Laramago analysis overlay.');
    }
PHP;
        });
    }

    private function renderInteractsWithInputOverlay(string $source): string
    {
        $source = preg_replace(
            '/(^[ \t]*\*\s*)@param\s+string\|array\|null\s+\$default$/m',
            '$1@param mixed $default',
            $source,
        ) ?? $source;

        $source = preg_replace(
            '/(^[ \t]*\*\s*)@return\s+string\|array\|null$/m',
            '$1@return mixed',
            $source,
            2,
        ) ?? $source;

        $source = preg_replace(
            '/^([ \t]*\*\s*)@return[^\r\n]*(?:UploadedFile[^\r\n]*)$/m',
            '$1@return ($key is null ? array<string, mixed> : \Illuminate\Http\UploadedFile|null)',
            $source,
        ) ?? $source;

        return preg_replace(
            '/public function file\(\$key = null, \$default = null\)(?!\s*:)/',
            'public function file($key = null, $default = null): array|\Illuminate\Http\UploadedFile|null',
            $source,
        ) ?? $source;
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

    private function renderValidationExceptionOverlay(string $source): string
    {
        $source = preg_replace(
            '/\/\*\*\s*\n\s+\* Get all of the validation error messages\.\s*\n\s+\*\s*\n\s+\* @return array\s*\n\s+\*\/\s*\n\s+public function errors\(\)/m',
            <<<'PHP'
    /**
     * Get all of the validation error messages.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
PHP,
            $source,
        );

        return is_string($source) ? $source : '';
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

    private function renderSocialiteTwoProviderOverlay(string $source): string
    {
        $source = preg_replace(
            '/@return\s+\\\\?Laravel\\\\Socialite\\\\Two\\\\User\b/',
            '@return \Laravel\Socialite\Contracts\User|null',
            $source,
        ) ?? $source;

        $source = preg_replace(
            '/public function user\(\)(?:\s*:\s*[^;\r\n]+)?;/',
            'public function user();',
            $source,
            1,
        ) ?? $source;

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

    private function renderAuthGuardOverlay(string $authModel, string $authIdentifierType): string
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
     * @return {$authIdentifierType}
     */
    public function id();

    public function validate(array \$credentials = []);

    public function hasUser();

    public function setUser(Authenticatable \$user);
}
PHP;
    }

    private function renderAuthFacadeOverlay(string $authModel, string $authIdentifierType): string
    {
        return <<<PHP
<?php

namespace Illuminate\Support\Facades;

/**
 * @method static \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard guard(\UnitEnum|string|null \$name = null)
 * @method static bool check()
 * @method static bool guest()
 * @method static {$authModel}|null user()
 * @method static {$authIdentifierType} id()
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

    private function authIdentifierType(string $projectRoot, string $authModel): string
    {
        $source = $this->projectClassSource($projectRoot, $authModel);

        if ($source === null) {
            return 'int|string|null';
        }

        if (preg_match('/\$keyType\s*=\s*[\'"]string[\'"]/', $source) === 1
            || preg_match('/function\s+getKeyType\s*\([^)]*\)\s*(?::\s*string)?\s*\{[^}]*return\s+[\'"]string[\'"]\s*;/s', $source) === 1
            || str_contains($source, 'HasUuids')
            || str_contains($source, 'HasUlids')) {
            return 'string|null';
        }

        if (preg_match('/\$keyType\s*=\s*[\'"]int(?:eger)?[\'"]/', $source) === 1
            || preg_match('/function\s+getKeyType\s*\([^)]*\)\s*(?::\s*string)?\s*\{[^}]*return\s+[\'"]int(?:eger)?[\'"]\s*;/s', $source) === 1) {
            return 'int|null';
        }

        if (preg_match('/\$incrementing\s*=\s*false\b/', $source) === 1) {
            return 'int|string|null';
        }

        return 'int|null';
    }

    private function projectClassSource(string $projectRoot, string $class): ?string
    {
        $class = ltrim($class, '\\');
        $paths = [];

        if (str_starts_with($class, 'App\\')) {
            $paths[] = $projectRoot . '/app/' . str_replace('\\', '/', substr($class, strlen('App\\'))) . '.php';
        }

        $composerPath = $projectRoot . '/composer.json';
        $composer = is_file($composerPath) ? json_decode((string) file_get_contents($composerPath), true) : null;

        if (is_array($composer)) {
            foreach (['autoload', 'autoload-dev'] as $section) {
                $psr4 = $composer[$section]['psr-4'] ?? null;

                if (! is_array($psr4)) {
                    continue;
                }

                foreach ($psr4 as $prefix => $directories) {
                    if (! is_string($prefix) || ! str_starts_with($class, $prefix)) {
                        continue;
                    }

                    foreach ((array) $directories as $directory) {
                        if (! is_string($directory)) {
                            continue;
                        }

                        $paths[] = $projectRoot . '/' . trim($directory, '/') . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    }
                }
            }
        }

        foreach (array_values(array_unique($paths)) as $path) {
            if (! is_file($path)) {
                continue;
            }

            $source = file_get_contents($path);

            return is_string($source) ? $source : null;
        }

        return null;
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
