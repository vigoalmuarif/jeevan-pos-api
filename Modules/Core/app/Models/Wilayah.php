<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Modules\Core\Abstracts\BaseModel;

#[Table("wilayah")]
class Wilayah extends BaseModel
{
    public $timestamps = false;
    protected $primaryKey = 'kode';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['kode', 'nama'];

    // Derive level dari panjang kode
    public function getLevelAttribute(): string
    {
        return match (strlen($this->kode)) {
            2       => 'province',
            5       => 'regency',
            8       => 'district',
            default => 'village',
        };
    }

    // Scope: ambil children dari parent_kode
    public function scopeChildrenOf(Builder $query, string $parentKode): Builder
    {
        if ($parentKode === '') {
            // Root = provinsi, kode length = 2
            return $query->whereRaw('LENGTH(kode) = 2');
        }

        $childLength = strlen($parentKode) + 3; // '35' → 5, '35.07' → 8, dst

        return $query
            ->where('kode', 'like', $parentKode . '.%')
            ->whereRaw('LENGTH(kode) = ?', [$childLength]);
    }
}