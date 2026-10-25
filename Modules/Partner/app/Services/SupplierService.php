<?php

namespace Modules\Partner\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Partner\Models\Supplier;
use Spatie\QueryBuilder\AllowedFilter;

class SupplierService
{
    public function __construct(protected Supplier $model) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        $config = new DataTableConfig(
            allowedFields: [
                'id',
                'code',
                'name',
                'contact_person',
                'phone',
                'payment_term_days',
                'is_active',
                'created_at',
            ],
            allowedFilters: [
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('region_id'),
            ],
            with: [],
            allowedSorts: ['name', 'code', 'created_at'],
            searchableColumns: ['name', 'code', 'phone', 'email', 'contact_person'],
            defaultSort: '-created_at',
        );

        return ServerSideTable::paginate($this->model->newQuery(), $config, $request);
    }



    public function create(array $data): Supplier
    {
        return DB::transaction(function () use ($data) {

            $supplier = Supplier::create([
                'merchant_id' => MerchantContext::id(),
                'code'        => $data['code'],
                'name'        => $data['name'],
                'contact_person' => $data['contact_person'],
                'phone'        => $data['phone'],
                'email'        => $data['email'],
                'region_code'  => $data['region_code'],
                'tax_id'       => $data['tax_id'],
                'address'      => $data['address'],
                'postal_code'  => $data['postal_code'],
                'payment_term_days' => $data['payment_term_days'],
                'bank_name'     => $data['bank_name'],
                'bank_account_name' => $data['bank_account_name'],
                'bank_account_number' => $data['bank_account_number'],
                'notes'        => $data['notes'],
                'is_active'    => $data['is_active'],
                'created_by'   => $data['created_by'],
            ]);

            return $supplier;
        });
    }


    public function update(
        Supplier $supplier,
        array $data
    ): Supplier {
        return DB::transaction(function () use ($supplier, $data) {
            $supplier->update([
                'merchant_id' => MerchantContext::id(),
                'code'        => $data['code'],
                'name'        => $data['name'],
                'contact_person' => $data['contact_person'],
                'phone'        => $data['phone'],
                'email'        => $data['email'],
                'region_code'  => $data['region_code'],
                'tax_id'       => $data['tax_id'],
                'address'      => $data['address'],
                'postal_code'  => $data['postal_code'],
                'payment_term_days' => $data['payment_term_days'],
                'bank_name'    => $data['bank_name'],
                'bank_account_name' => $data['bank_account_name'],
                'bank_account_number' => $data['bank_account_number'],
                'notes'        => $data['notes'],
                'is_active'    => $data['is_active'],
                'created_by'   => $data['created_by'],
            ]);

            return $supplier;
        });
    }


    public function delete(Supplier $supplier): void
    {
        $supplier->delete();
    }
}
