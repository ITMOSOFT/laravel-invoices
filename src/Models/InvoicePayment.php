<?php

namespace ItmoSoft\Invoices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePayment extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'metadata' => 'array'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function getTable(): string
    {
        return config('itmosoft-invoices.table_prefix', 'itmosoft_').'invoice_payments';
    }
}