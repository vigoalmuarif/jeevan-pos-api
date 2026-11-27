<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('doc_type', 20);
            $table->string('period', 16);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['merchant_id', 'doc_type', 'period'], 'sales_doc_sequences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_document_sequences');
    }
};
