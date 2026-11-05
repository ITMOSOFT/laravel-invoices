<?php

namespace ItmoSoft\Invoices\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use ItmoSoft\Invoices\Events\InvoiceCreated;
use ItmoSoft\Invoices\Events\InvoiceIssued;
use ItmoSoft\Invoices\Events\InvoicePaymentRecorded;
use ItmoSoft\Invoices\Exceptions\InvalidInvoiceState;
use ItmoSoft\Invoices\Models\Invoice;
use ItmoSoft\Invoices\Models\InvoicePayment;
use ItmoSoft\Invoices\Models\InvoiceStatusHistory;

class InvoiceManager
{
    public function create(array $data, array $items = []): Invoice
    {
        if (empty($data['order_id'])) {
            throw new \InvalidArgumentException('An order_id is required.');
        }

        $totals = $this->calculateTotals($items, $data);
        $invoice = DB::transaction(function () use ($data, $items, $totals) {
            $invoice = Invoice::query()->create([
                'order_id' => $data['order_id'],
                'customer_type' => $data['customer_type'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'number' => $data['number'] ?? $this->nextNumber(),
                'currency' => strtoupper($data['currency'] ?? config('itmosoft-invoices.currency', 'USD')),
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'shipping_total' => $totals['shipping_total'],
                'total' => $totals['total'],
                'paid_total' => 0,
                'status' => 'draft',
                'issued_at' => null,
                'due_at' => null,
                'paid_at' => null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            foreach ($items as $item) {
                $quantity = (float) ($item['quantity'] ?? 1);
                $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);
                $discount = round((float) ($item['discount_total'] ?? 0), 2);
                $tax = round((float) ($item['tax_total'] ?? 0), 2);
                $lineTotal = round(($quantity * $unitPrice) - $discount + $tax, 2);

                $invoice->items()->create([
                    'order_item_id' => $item['order_item_id'] ?? null,
                    'description' => $item['description'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_total' => $discount,
                    'tax_total' => $tax,
                    'line_total' => $lineTotal,
                    'snapshot' => $item['snapshot'] ?? null,
                ]);
            }

            InvoiceStatusHistory::query()->create([
                'invoice_id' => $invoice->id,
                'from_status' => null,
                'to_status' => 'draft',
                'note' => 'Invoice created',
                'metadata' => null,
                'created_at' => now(),
            ]);

            return $invoice->load(['items', 'payments', 'history']);
        });

        Event::dispatch(new InvoiceCreated($invoice));
        return $invoice;
    }

    public function issue(Invoice $invoice, ?\DateTimeInterface $dueAt = null): Invoice
    {
        if ($invoice->status !== 'draft') {
            throw new InvalidInvoiceState('Only draft invoices can be issued.');
        }

        $from = $invoice->status;
        $invoice->status = 'issued';
        $invoice->issued_at = now();
        $invoice->due_at = $dueAt ?? now()->addDays((int) config('itmosoft-invoices.due_days', 14));
        $invoice->save();

        $this->recordHistory($invoice, $from, 'issued', 'Invoice issued');
        $invoice->refresh()->load(['items', 'payments', 'history']);
        Event::dispatch(new InvoiceIssued($invoice));

        return $invoice;
    }

    public function recordPayment(Invoice $invoice, array $data): Invoice
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }
        if (in_array($invoice->status, ['draft', 'void'], true)) {
            throw new InvalidInvoiceState('Payments can only be recorded against issued invoices.');
        }

        $payment = DB::transaction(function () use ($invoice, $data, $amount) {
            $payment = $invoice->payments()->create([
                'reference' => $data['reference'] ?? null,
                'provider' => $data['provider'] ?? null,
                'provider_reference' => $data['provider_reference'] ?? null,
                'amount' => $amount,
                'currency' => strtoupper($data['currency'] ?? $invoice->currency),
                'paid_at' => $data['paid_at'] ?? now(),
                'metadata' => $data['metadata'] ?? null,
            ]);

            $invoice->refresh();
            $newPaid = round((float) $invoice->paid_total + $amount, 2);
            if ($newPaid > (float) $invoice->total) {
                throw new \InvalidArgumentException('Recorded payments cannot exceed the invoice total.');
            }

            $from = $invoice->status;
            $invoice->paid_total = $newPaid;
            if ($newPaid >= (float) $invoice->total) {
                $invoice->status = 'paid';
                $invoice->paid_at = $data['paid_at'] ?? now();
            } else {
                $invoice->status = 'partially_paid';
            }
            $invoice->save();

            $this->recordHistory($invoice, $from, $invoice->status, 'Payment recorded');
            return $payment;
        });

        $invoice->refresh()->load(['items', 'payments', 'history']);
        Event::dispatch(new InvoicePaymentRecorded($invoice, $payment));
        return $invoice;
    }

    public function markOverdue(Invoice $invoice): Invoice
    {
        if (!in_array($invoice->status, ['issued', 'partially_paid'], true)
            || !$invoice->due_at
            || $invoice->due_at->isFuture()) {
            throw new InvalidInvoiceState('Only unpaid invoices past their due date can be marked overdue.');
        }

        $from = $invoice->status;
        $invoice->status = 'overdue';
        $invoice->save();
        $this->recordHistory($invoice, $from, 'overdue', 'Invoice marked overdue');

        return $invoice->refresh()->load(['items', 'payments', 'history']);
    }

    public function void(Invoice $invoice, ?string $note = null): Invoice
    {
        if (in_array($invoice->status, ['paid', 'void'], true)) {
            throw new InvalidInvoiceState('Paid or already void invoices cannot be voided.');
        }

        $from = $invoice->status;
        $invoice->status = 'void';
        $invoice->save();
        $this->recordHistory($invoice, $from, 'void', $note ?? 'Invoice voided');

        return $invoice->refresh()->load(['items', 'payments', 'history']);
    }

    private function calculateTotals(array $items, array $data): array
    {
        $subtotal = 0.0;
        $itemDiscount = 0.0;
        $itemTax = 0.0;

        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $subtotal += $quantity * $unitPrice;
            $itemDiscount += (float) ($item['discount_total'] ?? 0);
            $itemTax += (float) ($item['tax_total'] ?? 0);
        }

        $discount = round((float) ($data['discount_total'] ?? $itemDiscount), 2);
        $tax = round((float) ($data['tax_total'] ?? $itemTax), 2);
        $shipping = round((float) ($data['shipping_total'] ?? 0), 2);
        $subtotal = round($subtotal, 2);
        $total = round($subtotal - $discount + $tax + $shipping, 2);

        if ($discount < 0 || $tax < 0 || $shipping < 0 || $total < 0) {
            throw new \InvalidArgumentException('Invoice totals contain invalid negative values.');
        }

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'tax_total' => $tax,
            'shipping_total' => $shipping,
            'total' => $total,
        ];
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
        InvoiceStatusHistory::query()->create([
            'invoice_id' => $invoice->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'metadata' => null,
            'created_at' => now(),
        ]);
    }
}