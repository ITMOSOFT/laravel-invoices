# ITMOSOFT Laravel Invoices

Invoice records, line snapshots, numbering and payment tracking for Laravel 12 and 13.

## Features

- Invoice records linked to orders.
- Configurable invoice number prefix and padding.
- Invoice line items with quantity, price, discount, tax and snapshots.
- Snapshot totals for subtotal, discount, tax, shipping and total.
- Issue and due dates.
- Multiple payment records, including external provider references.
- Partial and full payment tracking.
- Draft, issued, partially paid, paid, overdue and void statuses.
- Status history and events.
- Configurable database table prefix.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

```bash
composer require itmosoft/laravel-invoices
php artisan vendor:publish --tag=itmosoft-invoices-config
php artisan migrate
```

## Usage

```php
use ItmoSoft\Invoices\Facades\Invoices;

$invoice = Invoices::create(
    ['order_id' => 123, 'currency' => 'USD', 'shipping_total' => 5],
    [
        [
            'order_item_id' => 456,
            'description' => 'Product name',
            'sku' => 'SKU-001',
            'quantity' => 2,
            'unit_price' => 20,
            'tax_total' => 4,
            'snapshot' => ['name' => 'Product name'],
        ],
    ],
);

$invoice = Invoices::issue($invoice);
$invoice = Invoices::recordPayment($invoice, [
    'amount' => 10,
    'provider' => 'stripe',
    'provider_reference' => 'external-ref-123',
]);
```

The facade resolves `InvoiceManager`; the same service can be injected directly.

## Totals

The package stores monetary values as decimal columns and calculates item subtotal from quantity multiplied by unit price. Item and invoice discounts, tax and shipping totals are snapshots supplied by the caller. This package does not calculate tax or discount eligibility.

## Statuses

- `draft` -> `issued` or `void`
- `issued` -> `partially_paid`, `paid`, `overdue` or `void`
- `partially_paid` -> `paid`, `overdue` or `void`
- `overdue` -> `partially_paid`, `paid` or `void`
- `paid` and `void` are terminal through the manager API

`markOverdue()` is an explicit operation; schedule it from your application.

## Tests

```bash
composer install
composer test
```

## Boundaries

No PDF rendering, email delivery, payment execution, tax calculation, or accounting ledger integration is included. Payment records are bookkeeping entries; integrate a payment adapter before recording actual external payments.

## License

MIT.
