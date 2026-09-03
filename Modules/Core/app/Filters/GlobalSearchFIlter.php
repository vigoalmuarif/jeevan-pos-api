<?php

namespace Modules\Core\Filters;

use Spatie\QueryBuilder\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class GlobalSearchFilter implements Filter
{
    public function __construct(private readonly array $columns) {}

    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $keyword = '%' . trim((string) $value) . '%';

        $query->where(function (Builder $q) use ($keyword) {
            foreach ($this->columns as $column) {
                $q->orWhere($column, 'ILIKE', $keyword);
            }
        });
    }
}