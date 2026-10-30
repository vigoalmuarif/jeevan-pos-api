<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Request (PR) = permintaan pembelian INTERNAL; tidak menggerakkan stok maupun hutang.
 * Fitur ini opsional dan diaktifkan per merchant lewat setting purchase.request_enabled.
 *
 * Status: draft -> submitted -> approved | rejected
 *         approved -> partially_ordered -> ordered (turunan dari qty yang sudah dijadikan PO)
 *         draft/submitted/approved -> cancelled ; approved/partially_ordered -> closed (ditutup manual)
 *
 * source: manual (diisi user) | low_stock (dibuat dari rekomendasi stok minimum).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();

            $table->string('document_number', 50);
            $table->date('request_date');
            $table->date('required_date')->nullable();
            $table->string('source', 20)->default('manual');
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reject_reason', 255)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['merchant_id', 'document_number'], 'purchase_requests_merchant_number_unique');
            $table->index(['merchant_id', 'status', 'request_date'], 'purchase_requests_status_date_index');
            $table->index('warehouse_id', 'purchase_requests_warehouse_index');
        });

        DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT purchase_requests_status_check CHECK (status IN ('draft','submitted','approved','rejected','partially_ordered','ordered','closed','cancelled'))");
        DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT purchase_requests_source_check CHECK (source IN ('manual','low_stock'))");

        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            $table->decimal('unit_quantity', 18, 4);
            $table->decimal('conversion_rate', 18, 6)->default(1);
            $table->decimal('base_quantity', 18, 4);
            $table->decimal('ordered_base_quantity', 18, 4)->default(0);
            $table->decimal('on_hand_base_quantity', 18, 4)->default(0);
            $table->foreignId('suggested_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->decimal('estimated_unit_price', 18, 4)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['merchant_id', 'purchase_request_id'], 'pr_items_merchant_pr_index');
            $table->index(['merchant_id', 'product_variant_id'], 'pr_items_merchant_variant_index');
        });

        DB::statement('ALTER TABLE purchase_request_items ADD CONSTRAINT pr_items_qty_check CHECK (unit_quantity > 0 AND conversion_rate > 0 AND base_quantity > 0 AND ordered_base_quantity >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
    }
};
