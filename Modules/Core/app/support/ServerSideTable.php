<?php

namespace Modules\Core\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Core\Filters\GlobalSearchFilter;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ServerSideTable
{
    public static function paginate(Builder $query, DataTableConfig $config, Request $request): LengthAwarePaginator
    {
        $perPage = min(
            (int) $request->integer('per_page', $config->defaultPerPage),
            $config->maxPerPage
        );

        $filters = $config->allowedFilters;
        if (! empty($config->searchableColumns)) {
            $filters[] = AllowedFilter::custom('search', new GlobalSearchFilter($config->searchableColumns));
        }

        return QueryBuilder::for($query)
            ->allowedFields(...$config->allowedFields)
            ->allowedFilters(...$filters)
            ->allowedSorts(...$config->allowedSorts)
            ->defaultSort($config->defaultSort)
            ->with($config->with)
            ->withCount($config->withCount)
            ->paginate($perPage)
            ->appends($request->query());
    }
}