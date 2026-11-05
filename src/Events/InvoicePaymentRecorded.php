<?php

namespace ItmoSoft\Invoices\Events;

use ItmoSoft\Invoices\Models\Invoice;
use ItmoSoft\Invoices\Models\InvoicePayment;

class InvoicePaymentRecorded
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly InvoicePayment $payment,
    ) {}
}