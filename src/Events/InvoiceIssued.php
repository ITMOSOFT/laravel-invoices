<?php

namespace ItmoSoft\Invoices\Events;

use ItmoSoft\Invoices\Models\Invoice;

class InvoiceIssued
{
    public function __construct(public readonly Invoice $invoice) {}
}