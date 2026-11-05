<?php

namespace ItmoSoft\Invoices\Events;

use ItmoSoft\Invoices\Models\Invoice;

class InvoiceCreated
{
    public function __construct(public readonly Invoice $invoice) {}
}