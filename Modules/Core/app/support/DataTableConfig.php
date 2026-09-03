<?php

namespace Modules\Core\Support;

class DataTableConfig
{
    public function __construct(
        public readonly array $allowedFields = [],   // string|AllowedFilter[]
        public readonly array $allowedFilters = [],   // string|AllowedFilter[]
        public readonly array $allowedSorts = [],      // string|AllowedSort[]
        public readonly array $searchableColumns = [],
        public readonly string $defaultSort = '-created_at',
        public readonly array $with = [],
        public readonly array $withCount = [],
        public readonly int $defaultPerPage = 15,
        public readonly int $maxPerPage = 100,
    ) {}
}