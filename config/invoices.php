<?php

return [
    'table_prefix' => env('ITMOSOFT_INVOICES_TABLE_PREFIX', 'itmosoft_'),
    'number_prefix' => env('ITMOSOFT_INVOICES_NUMBER_PREFIX', 'INV-'),
    'number_padding' => (int) env('ITMOSOFT_INVOICES_NUMBER_PADDING', 6),
    'currency' => env('ITMOSOFT_INVOICES_DEFAULT_CURRENCY', 'USD'),
    'due_days' => (int) env('ITMOSOFT_INVOICES_DUE_DAYS', 14),
    'statuses' => ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'void'],
];