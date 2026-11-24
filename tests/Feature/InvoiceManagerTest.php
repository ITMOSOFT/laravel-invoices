<?php

namespace ItmoSoft\Invoices\Tests\Feature;

use ItmoSoft\Invoices\Exceptions\InvalidInvoiceState;
use ItmoSoft\Invoices\Services\InvoiceManager;
use ItmoSoft\Invoices\Tests\TestCase;
use InvalidArgumentException;

class InvoiceManagerTest extends TestCase
{
    public function test_it_creates_invoice_with_items_and_totals(): void
    {
        $invoice = app(InvoiceManager::class)->create(['order_id' => 20, 'shipping_total' => 5, 'tax_total' => 2],
            [['description' => 'Item A', 'quantity' => 2, 'unit_price' => 10, 'snapshot' => ['sku' => 'A']]]);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('27.00', $invoice->total);
        $this->assertCount(1, $invoice->items);
        $this->assertCount(1, $invoice->history);
    }

    public function test_it_issues_invoice_and_records_partial_then_full_payment(): void
    {
        $manager = app(InvoiceManager::class);
        $invoice = $manager->create(['order_id' => 21], [['description' => 'Item B', 'quantity' => 1, 'unit_price' => 30]]);
        $invoice = $manager->issue($invoice);
        $this->assertSame('issued', $invoice->status);
        $invoice = $manager->recordPayment($invoice, ['amount' => 10]);
        $this->assertSame('partially_paid', $invoice->status);
        $invoice = $manager->recordPayment($invoice, ['amount' => 20]);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('30.00', $invoice->paid_total);
    }

    public function test_it_rejects_payment_exceeding_invoice_total(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $manager = app(InvoiceManager::class);
        $invoice = $manager->issue($manager->create(['order_id' => 22], [['description' => 'Item C', 'quantity' => 1, 'unit_price' => 10]]));
        $manager->recordPayment($invoice, ['amount' => 11]);
    }

    public function test_it_rejects_payment_with_a_different_currency(): void
    {
        $manager = app(InvoiceManager::class);
        $invoice = $manager->issue($manager->create(['order_id' => 220, 'currency' => 'USD'], [['unit_price' => 12]]));
        $this->expectException(InvalidArgumentException::class);
        $manager->recordPayment($invoice, ['amount' => 5, 'currency' => 'EUR']);
    }

    public function test_it_rejects_a_second_payment_after_invoice_is_paid_even_from_stale_model(): void
    {
        $manager = app(InvoiceManager::class);
        $invoice = $manager->issue($manager->create(['order_id' => 221], [['unit_price' => 10]]));
        $stale = clone $invoice;
        $manager->recordPayment($invoice, ['amount' => 10]);
        $this->expectException(InvalidInvoiceState::class);
        $manager->recordPayment($stale, ['amount' => 1]);
    }

    public function test_it_does_not_allow_issuing_an_already_issued_invoice(): void
    {
        $this->expectException(InvalidInvoiceState::class);
        $manager = app(InvoiceManager::class);
        $invoice = $manager->issue($manager->create(['order_id' => 23], []));
        $manager->issue($invoice);
    }
}
