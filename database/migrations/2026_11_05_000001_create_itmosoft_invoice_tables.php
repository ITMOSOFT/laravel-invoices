<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $prefix = config('itmosoft-invoices.table_prefix', 'itmosoft_');

        Schema::create($prefix.'invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('customer_type')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('number')->unique();
            $table->char('currency', 3);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('shipping_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->decimal('paid_total', 14, 2)->default(0);
            $table->string('status', 32)->index();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create($prefix.'invoice_items', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('invoice_id')->constrained($prefix.'invoices')->cascadeOnDelete();
            $table->unsignedBigInteger('order_item_id')->nullable()->index();
            $table->string('description')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->json('snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create($prefix.'invoice_payments', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('invoice_id')->constrained($prefix.'invoices')->cascadeOnDelete();
            $table->string('reference')->nullable()->index();
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable()->index();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->timestamp('paid_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create($prefix.'invoice_status_history', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('invoice_id')->constrained($prefix.'invoices')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['invoice_id', 'created_at']);
        });
    }

    public function down(): void
    {
        $prefix = config('itmosoft-invoices.table_prefix', 'itmosoft_');
        Schema::dropIfExists($prefix.'invoice_status_history');
        Schema::dropIfExists($prefix.'invoice_payments');
        Schema::dropIfExists($prefix.'invoice_items');
        Schema::dropIfExists($prefix.'invoices');
    }
};