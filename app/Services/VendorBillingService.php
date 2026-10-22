<?php

namespace App\Services;

use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorGoodsReceipt;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Models\VendorPurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VendorBillingService
{
    public function __construct(
        private readonly VendorProcurementService $procurement,
        private readonly VendorAccountingService $accounting,
    ) {}

    public function createDraft(User $actor, array $data, ?string $attachmentPath = null): VendorBill
    {
        return DB::transaction(function () use ($actor, $data, $attachmentPath): VendorBill {
            $vendor = Vendor::query()->where('restaurant_id', $actor->restaurant_id)
                ->whereKey($data['vendor_id'])->lockForUpdate()->firstOrFail();
            $this->assertLinksMatchVendor($actor, $vendor, $data);
            [$items, $totals] = $this->procurement->calculateItems($data['items']);
            $taxableCents = VendorAccountingService::toCents($totals['subtotal'])
                - VendorAccountingService::toCents($totals['discount_total']);
            $bill = VendorBill::create([
                'restaurant_id' => $actor->restaurant_id,
                'vendor_id' => $vendor->id,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'vendor_invoice_number' => $data['vendor_invoice_number'] ?? null,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'taxable_amount' => VendorAccountingService::formatCents($taxableCents),
                'tax_amount' => $totals['tax_total'],
                'total_amount' => $totals['grand_total'],
                'status' => 'draft',
                'attachment_path' => $attachmentPath,
                'created_by' => $actor->id,
                'notes' => $data['notes'] ?? null,
            ]);
            $bill->forceFill(['bill_number' => sprintf('VBILL%06d', $bill->id)])->save();
            $bill->items()->createMany(array_map(
                fn (array $item): array => ['restaurant_id' => $actor->restaurant_id, ...$item],
                $items,
            ));
            $this->audit($actor, $bill->restaurant_id, 'vendor_bill_drafted', 'vendor_bill', $bill->id, [
                'bill_number' => $bill->bill_number,
                'vendor_id' => $vendor->id,
            ]);

            return $bill->load('items');
        });
    }

    public function updateDraft(User $actor, VendorBill $bill, array $data, ?string $attachmentPath = null): VendorBill
    {
        return DB::transaction(function () use ($actor, $bill, $data, $attachmentPath): VendorBill {
            $bill = VendorBill::query()->where('restaurant_id', $actor->restaurant_id)
                ->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($bill->status !== 'draft' || $bill->posted_at !== null) {
                throw ValidationException::withMessages(['bill' => 'Only a draft vendor bill can be edited.']);
            }
            $vendor = Vendor::query()->where('restaurant_id', $actor->restaurant_id)
                ->whereKey($data['vendor_id'])->lockForUpdate()->firstOrFail();
            $this->assertLinksMatchVendor($actor, $vendor, $data);
            [$items, $totals] = $this->procurement->calculateItems($data['items']);
            $taxableCents = VendorAccountingService::toCents($totals['subtotal'])
                - VendorAccountingService::toCents($totals['discount_total']);
            $bill->update([
                'vendor_id' => $vendor->id,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'vendor_invoice_number' => $data['vendor_invoice_number'] ?? null,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'taxable_amount' => VendorAccountingService::formatCents($taxableCents),
                'tax_amount' => $totals['tax_total'],
                'total_amount' => $totals['grand_total'],
                'attachment_path' => $attachmentPath ?? $bill->attachment_path,
                'notes' => $data['notes'] ?? null,
            ]);
            $bill->items()->delete();
            $bill->items()->createMany(array_map(
                fn (array $item): array => ['restaurant_id' => $actor->restaurant_id, ...$item],
                $items,
            ));
            $this->audit($actor, $bill->restaurant_id, 'vendor_bill_draft_updated', 'vendor_bill', $bill->id, [
                'bill_number' => $bill->bill_number,
            ]);

            return $bill->load('items');
        });
    }

    public function post(User $actor, VendorBill $bill): VendorBill
    {
        return DB::transaction(function () use ($actor, $bill): VendorBill {
            $bill = VendorBill::query()->where('restaurant_id', $actor->restaurant_id)
                ->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($bill->status !== 'draft' || $bill->items()->count() === 0) {
                throw ValidationException::withMessages(['bill' => 'Only a complete draft bill can be posted.']);
            }
            if (VendorAccountingService::toCents((string) $bill->total_amount) <= 0) {
                throw ValidationException::withMessages(['bill' => 'A vendor bill total must be greater than zero.']);
            }

            $bill->update([
                'status' => 'unpaid',
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ]);
            $this->audit($actor, $bill->restaurant_id, 'vendor_bill_posted', 'vendor_bill', $bill->id, [
                'bill_number' => $bill->bill_number,
                'total_amount' => $bill->total_amount,
            ]);

            return $bill;
        });
    }

    public function cancelDraft(User $actor, VendorBill $bill): VendorBill
    {
        return DB::transaction(function () use ($actor, $bill): VendorBill {
            $bill = VendorBill::query()->where('restaurant_id', $actor->restaurant_id)
                ->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($bill->status !== 'draft' || $bill->posted_at !== null || $bill->allocations()->exists()) {
                throw ValidationException::withMessages(['bill' => 'Posted financial records cannot be cancelled.']);
            }
            $bill->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->audit($actor, $bill->restaurant_id, 'vendor_bill_cancelled', 'vendor_bill', $bill->id, [
                'bill_number' => $bill->bill_number,
            ]);

            return $bill;
        });
    }

    public function recordPayment(User $actor, VendorBill $bill, array $data, ?string $proofPath = null): VendorPayment
    {
        return DB::transaction(function () use ($actor, $bill, $data, $proofPath): VendorPayment {
            $existing = VendorPayment::query()->where('restaurant_id', $actor->restaurant_id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }
            $bill = VendorBill::query()->where('restaurant_id', $actor->restaurant_id)
                ->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if ($bill->vendor_id !== (int) $data['vendor_id'] || $bill->posted_at === null || $bill->status === 'cancelled') {
                throw ValidationException::withMessages(['vendor_bill_id' => 'Select a posted bill for this vendor.']);
            }
            $amountCents = VendorAccountingService::toCents((string) $data['amount']);
            $outstandingCents = $this->accounting->billOutstandingCents($bill);
            if ($amountCents <= 0 || $amountCents > $outstandingCents) {
                throw ValidationException::withMessages(['amount' => 'Payment cannot exceed this bill’s outstanding amount.']);
            }

            $payment = VendorPayment::create([
                'restaurant_id' => $actor->restaurant_id,
                'vendor_id' => $bill->vendor_id,
                'payment_type' => 'payment',
                'payment_date' => $data['payment_date'],
                'amount' => VendorAccountingService::formatCents($amountCents),
                'payment_method' => $data['payment_method'],
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'proof_path' => $proofPath,
                'idempotency_key' => $data['idempotency_key'],
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor->id,
            ]);
            $payment->forceFill(['payment_reference' => sprintf('VPAY%06d', $payment->id)])->save();
            $payment->allocations()->create([
                'restaurant_id' => $actor->restaurant_id,
                'vendor_bill_id' => $bill->id,
                'amount' => VendorAccountingService::formatCents($amountCents),
            ]);
            $bill->update(['status' => $this->accounting->billStatus($bill)]);
            $this->audit($actor, $payment->restaurant_id, 'vendor_payment_recorded', 'vendor_payment', $payment->id, [
                'payment_reference' => $payment->payment_reference,
                'bill_id' => $bill->id,
                'amount' => $payment->amount,
            ]);

            return $payment;
        });
    }

    public function reversePayment(User $actor, VendorPayment $payment, string $reason): VendorPayment
    {
        return DB::transaction(function () use ($actor, $payment, $reason): VendorPayment {
            $original = VendorPayment::query()->where('restaurant_id', $actor->restaurant_id)
                ->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($original->payment_type !== 'payment' || $original->reversal()->exists()) {
                throw ValidationException::withMessages(['payment' => 'Only an unreversed payment can be reversed.']);
            }
            $reversal = VendorPayment::create([
                'restaurant_id' => $original->restaurant_id,
                'vendor_id' => $original->vendor_id,
                'payment_type' => 'reversal',
                'reverses_payment_id' => $original->id,
                'payment_date' => today(),
                'amount' => $original->amount,
                'payment_method' => $original->payment_method,
                'transaction_reference' => $original->transaction_reference,
                'notes' => $reason,
                'recorded_by' => $actor->id,
            ]);
            $reversal->forceFill(['payment_reference' => sprintf('VREV%06d', $reversal->id)])->save();
            foreach ($original->allocations as $allocation) {
                $bill = VendorBill::query()->where('restaurant_id', $original->restaurant_id)
                    ->whereKey($allocation->vendor_bill_id)->lockForUpdate()->firstOrFail();
                $bill->update(['status' => $this->accounting->billStatus($bill)]);
            }
            $this->audit($actor, $original->restaurant_id, 'vendor_payment_reversed', 'vendor_payment', $original->id, [
                'reversal_id' => $reversal->id,
                'reason' => $reason,
            ]);

            return $reversal;
        });
    }

    private function assertLinksMatchVendor(User $actor, Vendor $vendor, array $data): void
    {
        if (! empty($data['purchase_order_id'])) {
            $orderMatches = VendorPurchaseOrder::query()->where('restaurant_id', $actor->restaurant_id)
                ->where('vendor_id', $vendor->id)->whereKey($data['purchase_order_id'])->exists();
            if (! $orderMatches) {
                throw ValidationException::withMessages(['purchase_order_id' => 'Select a purchase order for this vendor.']);
            }
        }
        if (! empty($data['goods_receipt_id'])) {
            $receiptMatches = VendorGoodsReceipt::query()->where('restaurant_id', $actor->restaurant_id)
                ->where('vendor_id', $vendor->id)->whereKey($data['goods_receipt_id'])->exists();
            if (! $receiptMatches) {
                throw ValidationException::withMessages(['goods_receipt_id' => 'Select a goods receipt for this vendor.']);
            }
        }
    }

    private function audit(User $owner, int $restaurantId, string $action, string $targetType, int $targetId, array $details): void
    {
        $admin = request()->attributes->get('restaurant_admin_access_admin');
        $superAdmin = $admin instanceof SuperAdmin ? $admin : null;
        RestaurantAuditLog::create([
            'actor_id' => $superAdmin?->id,
            'actor_type' => $superAdmin ? 'super_admin' : 'restaurant_owner',
            'restaurant_id' => $restaurantId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $superAdmin ? $details + ['admin_id' => $superAdmin->id] : $details + ['owner_id' => $owner->id],
        ]);
    }
}