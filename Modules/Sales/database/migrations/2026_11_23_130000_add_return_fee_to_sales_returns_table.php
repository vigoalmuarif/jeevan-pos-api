<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->decimal('return_fee', 15, 2)->default(0)->after('exchange_total');
            $table->string('return_fee_bearer', 20)->default('customer')->after('return_fee');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropColumn(['return_fee', 'return_fee_bearer']);
        });
    }
};
