<?php

namespace ItmoSoft\Invoices\Services;

use Illuminate\Support\Facades\DB;
use ItmoSoft\Invoices\Events\InvoiceCreated;
use ItmoSoft\Invoices\Events\InvoiceIssued;
use ItmoSoft\Invoices\Events\InvoicePaymentRecorded;
use ItmoSoft\Invoices\Exceptions\InvalidInvoiceState;
use ItmoSoft\Invoices\Models\Invoice;
use ItmoSoft\Invoices\Models\InvoiceStatusHistory;
use InvalidArgumentException;

class InvoiceManager
{
    public function create(array $data, array $items = []): Invoice
    {
        if (empty($data['order_id'])) {
            throw new InvalidArgumentException('An order_id is required.');
        }
        $totals = $this->calculateTotals($items, $data);
        $invoice = DB::transaction(function () use ($data, $items, $totals): Invoice {
            $invoice = Invoice::query()->create([
                'order_id' => $data['order_id'], 'customer_type' => $data['customer_type'] ?? null,
                'customer_id' => $data['customer_id'] ?? null, 'number' => $data['number'] ?? $this->nextNumber(),
                'currency' => strtoupper($data['currency'] ?? config('itmosoft-invoices.currency', 'USD')),
                'subtotal' => $totals['subtotal'], 'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'], 'shipping_total' => $totals['shipping_total'],
                'total' => $totals['total'], 'paid_total' => 0, 'status' => 'draft',
                'issued_at' => null, 'due_at' => null, 'paid_at' => null, 'metadata' => $data['metadata'] ?? null,
            ]);
            foreach ($items as $item) {
                $quantity = (float) ($item['quantity'] ?? 1);
                $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);
                $discount = round((float) ($item['discount_total'] ?? 0), 2);
                $tax = round((float) ($item['tax_total'] ?? 0), 2);
                $lineTotal = round(($quantity * $unitPrice) - $discount + $tax, 2);
                $invoice->items()->create([
                    'order_item_id' => $item['order_item_id'] ?? null, 'description' => $item['description'] ?? null,
                    'sku' => $item['sku'] ?? null, 'quantity' => $quantity, 'unit_price' => $unitPrice,
                    'discount_total' => $discount, 'tax_total' => $tax, 'line_total' => $lineTotal,
                    'snapshot' => $item['snapshot'] ?? null,
                ]);
            }
            $this->recordHistory($invoice, null, 'draft', 'Invoice created');
            return $invoice->load(['items', 'payments', 'history']);
        });
        event(new InvoiceCreated($invoice));
        return $invoice;
    }

    public function issue(Invoice $invoice, ?\DateTimeInterface $dueAt = null): Invoice
    {
        $updated = DB::transaction(function () use ($invoice, $dueAt): Invoice {
            $locked = $this->lock($invoice);
            if ($locked->status !== 'draft') {
                throw new InvalidInvoiceState('Only draft invoices can be issued.');
            }
            $from = $locked->status;
            $locked->forceFill(['status' => 'issued', 'issued_at' => now(),
                'due_at' => $dueAt ?? now()->addDays((int) config('itmosoft-invoices.due_days', 14))])->save();
            $this->recordHistory($locked, $from, 'issued', 'Invoice issued');
            return $locked->refresh()->load(['items', 'payments', 'history']);
        });
        event(new InvoiceIssued($updated));
        return $updated;
    }

    public function recordPayment(Invoice $invoice, array $data): Invoice
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        [$updatedInvoice, $payment] = DB::transaction(function () use ($invoice, $data, $amount): array {
            // Lock the invoice row before reading paid_total; every payment writer serializes here.
            $locked = $this->lock($invoice);
            if (! in_array($locked->status, ['issued', 'partially_paid', 'overdue'], true)) {
                throw new InvalidInvoiceState('Payments can only be recorded against issued, overdue, or partially paid invoices.');
            }
            $currency = strtoupper($data['currency'] ?? $locked->currency);
            if ($currency !== strtoupper((string) $locked->currency)) {
                throw new InvalidArgumentException('Payment currency must match the invoice currency.');
            }
            $newPaid = round((float) $locked->paid_total + $amount, 2);
            if ($newPaid > (float) $locked->total) {
                throw new InvalidArgumentException('Recorded payments cannot exceed the invoice total.');
            }

            $payment = $locked->payments()->create([
                'reference' => $data['reference'] ?? null, 'provider' => $data['provider'] ?? null,
                'provider_reference' => $data['provider_reference'] ?? null, 'amount' => $amount,
                'currency' => $currency, 'paid_at' => $data['paid_at'] ?? now(), 'metadata' => $data['metadata'] ?? null,
            ]);
            $from = $locked->status;
            $locked->paid_total = $newPaid;
            if ($newPaid >= (float) $locked->total) {
                $locked->status = 'paid';
                $locked->paid_at = $data['paid_at'] ?? now();
            } else {
                $locked->status = 'partially_paid';
            }
            $locked->save();
            $this->recordHistory($locked, $from, $locked->status, 'Payment recorded');
            return [$locked->refresh()->load(['items', 'payments', 'history']), $payment];
        });

        event(new InvoicePaymentRecorded($updatedInvoice, $payment));
        return $updatedInvoice;
    }

    public function markOverdue(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $locked = $this->lock($invoice);
            if (! in_array($locked->status, ['issued', 'partially_paid'], true) || ! $locked->due_at || $locked->due_at->isFuture()) {
                throw new InvalidInvoiceState('Only unpaid invoices past their due date can be marked overdue.');
            }
            $from = $locked->status;
            $locked->status = 'overdue';
            $locked->save();
            $this->recordHistory($locked, $from, 'overdue', 'Invoice marked overdue');
            return $locked->refresh()->load(['items', 'payments', 'history']);
        });
    }

    public function void(Invoice $invoice, ?string $note = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $note): Invoice {
            $locked = $this->lock($invoice);
            if (in_array($locked->status, ['paid', 'void'], true)) {
                throw new InvalidInvoiceState('Paid or already void invoices cannot be voided.');
            }
            $from = $locked->status;
            $locked->status = 'void';
            $locked->save();
            $this->recordHistory($locked, $from, 'void', $note ?? 'Invoice voided');
            return $locked->refresh()->load(['items', 'payments', 'history']);
        });
    }

    private function lock(Invoice $invoice): Invoice
    {
        return Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
    }

    private function calculateTotals(array $items, array $data): array
    {
        $subtotal = 0.0;
        $itemDiscount = 0.0;
        $itemTax = 0.0;
        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            if ($quantity <= 0 || $unitPrice < 0) {
                throw new InvalidArgumentException('Invoice item quantity must be positive and unit price cannot be negative.');
            }
            $subtotal += $quantity * $unitPrice;
            $itemDiscount += (float) ($item['discount_total'] ?? 0);
            $itemTax += (float) ($item['tax_total'] ?? 0);
        }
        $discount = round((float) ($data['discount_total'] ?? $itemDiscount), 2);
        $tax = round((float) ($data['tax_total'] ?? $itemTax), 2);
        $shipping = round((float) ($data['shipping_total'] ?? 0), 2);
        $subtotal = round($subtotal, 2);
        $total = round($subtotal - $discount + $tax + $shipping, 2);
        if ($discount < 0 || $tax < 0 || $shipping < 0 || $total < 0 || $discount > $subtotal) {
            throw new InvalidArgumentException('Invoice totals contain invalid negative or excessive values.');
        }
        return ['subtotal' => $subtotal, 'discount_total' => $discount, 'tax_total' => $tax, 'shipping_total' => $shipping, 'total' => $total];
    }

    private function nextNumber(): string
    {
        $lastId = (int) (Invoice::query()->max('id') ?? 0) + 1;
        $prefix = (string) config('itmosoft-invoices.number_prefix', 'INV-');
        $padding = (int) config('itmosoft-invoices.number_padding', 6);
        return $prefix.str_pad((string) $lastId, max(1, $padding), '0', STR_PAD_LEFT);
    }

    private function recordHistory(Invoice $invoice, ?string $from, string $to, ?string $note): void
    {
        InvoiceStatusHistory::query()->create(['invoice_id' => $invoice->id, 'from_status' => $from,
            'to_status' => $to, 'note' => $note, 'metadata' => null, 'created_at' => now()]);
    }
}
