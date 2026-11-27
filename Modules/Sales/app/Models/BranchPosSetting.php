<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Operational\Models\Branch;
use Modules\User\Models\User;

class BranchPosSetting extends BaseModel
{
    use BelongsToMerchant;

    protected $table = 'branch_pos_settings';

    protected function casts(): array
    {
        return [
            'service_charge_rate' => 'decimal:2',
            'allow_decimal_qty' => 'boolean',
            'enable_item_notes' => 'boolean',
            'barcode_fast_scan' => 'boolean',
            'require_customer' => 'boolean',
            'extra_config' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
