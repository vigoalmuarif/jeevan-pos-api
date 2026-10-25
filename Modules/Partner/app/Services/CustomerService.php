<?php

namespace Modules\Partner\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Support\DataTableConfig;
use Modules\Core\Support\ServerSideTable;
use Modules\Partner\Models\Customer;
use Spatie\QueryBuilder\AllowedFilter;

class CustomerService
{
    public function __construct(protected Customer $model) {}

    public function getPaginated(Request $request): LengthAwarePaginator
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');

        $config = new DataTableConfig(
            allowedFields: [
                'id', 'code', 'name', 'type', 'contact_person', 'phone', 'email',
                'customer_group_id', 'region_id', 'credit_limit', 'payment_term_days',
                'is_active', 'created_at',
            ],
            allowedFilters: [
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('customer_group_id'),
                AllowedFilter::exact('region_id'),
            ],
            allowedSorts: ['name', 'code', 'created_at'],
            searchableColumns: ['name', 'code', 'phone', 'email', 'contact_person'],
            defaultSort: '-created_at',
        );

        return ServerSideTable::paginate(
            $this->model->newQuery()->where('merchant_id', MerchantContext::id()),
            $config,
            $request
        );
    }

    public function create(array $data): Customer
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');

        $merchantId = (int) MerchantContext::id();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = $this->generateUniqueCode($merchantId);

            try {
                return $this->model->create([
                    'merchant_id' => $merchantId,
                    'code' => $code,
                    'name' => $data['name'],
                    'type' => $data['type'] ?? 'individual',
                    'contact_person' => $data['contact_person'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'email' => $data['email'] ?? null,
                    'tax_id' => $data['tax_id'] ?? null,
                    'address' => $data['address'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'credit_limit' => $data['credit_limit'] ?? 0,
                    'payment_term_days' => $data['payment_term_days'] ?? 0,
                    'notes' => $data['notes'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                    'created_by' => auth()->guard('merchant')->id(),
                ]);
            } catch (QueryException $exception) {
                if (! str_contains($exception->getMessage(), 'customers_merchant_code_unique') || $attempt === 4) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unable to generate a unique customer code.');
    }

    private function generateUniqueCode(int $merchantId): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while ($this->model->newQuery()
            ->where('merchant_id', $merchantId)
            ->where('code', $code)
            ->exists());

        return $code;
    }

    public function findForCurrentMerchant(int $id): Customer
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');

        return $this->model->newQuery()
            ->where('merchant_id', MerchantContext::id())
            ->findOrFail($id);
    }

    public function update(int $id, array $data): Customer
    {
        $customer = $this->findForCurrentMerchant($id);

        $customer->fill(collect($data)->only([
            'name',
            'type',
            'contact_person',
            'phone',
            'email',
            'tax_id',
            'address',
            'postal_code',
            'credit_limit',
            'payment_term_days',
            'notes',
            'is_active',
        ])->all());
        $customer->updated_by = auth()->guard('merchant')->id();
        $customer->save();

        return $customer;
    }

    public function delete(int $id): void
    {
        $this->findForCurrentMerchant($id)->delete();
    }
}
