<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Spatie\QueryBuilder\Exceptions\InvalidFilterQuery;
use Spatie\QueryBuilder\Exceptions\InvalidSortQuery;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->priority([
            \Modules\Merchant\Http\Middleware\ResolveMerchant::class,
            \Modules\Operational\Http\Middleware\ResolveBranch::class,
            SubstituteBindings::class,
        ]);
        
        $middleware->alias([
            'resolve.merchant' => \Modules\Merchant\Http\Middleware\ResolveMerchant::class,
            'merchant.active' => \Modules\Merchant\Http\Middleware\CheckMerchantActive::class,
            'resolve.branch' => \Modules\Operational\Http\Middleware\ResolveBranch::class,
            'module' => \Modules\Merchant\Http\Middleware\CheckModuleAccess::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*'),
        );
        
        $exceptions->render(function (InvalidFilterQuery|InvalidSortQuery $e, $request) {
            return response()->json([
                'message' => 'Parameter tidak valid.',
                'errors' => ['query' => [$e->getMessage()]],
            ], 422);
        });
    })->create();
