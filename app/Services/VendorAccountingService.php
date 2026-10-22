<?php

namespace App\Services;

use App\Models\Vendor;
use App\Models\VendorBill;
use Illuminate\Support\Facades\DB;

class VendorAccountingService
{
    public function balancesForRestaurant(int $restaurantId): array
    {
        $vendorIds = Vendor::query()->where('restaurant_id', $restaurantId)->pluck('id')->all();
        $balances = array_fill_keys($vendorIds, 0);
        if ($vendorIds === []) {
            return $balances;
        }

        $billTotals = VendorBill::query()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('vendor_id', $vendorIds)
            ->whereNotNull('posted_at')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->selectRaw('vendor_id, SUM(total_amount) as total')
            ->groupBy('vendor_id')
            ->pluck('total', 'vendor_id');

        foreach ($billTotals as $vendorId => $total) {
            $balances[(int) $vendorId] += self::toCents((string) $total);
        }

        $adjustments = DB::table('vendor_financial_adjustments')
            ->where('restaurant_id', $restaurantId)
            ->whereIn('vendor_id', $vendorIds)
            ->selectRaw('vendor_id, entry_type, SUM(amount) as total')
            ->groupBy('vendor_id', 'entry_type')
            ->get();

        foreach ($adjustments as $adjustment) {
            $sign = $adjustment->entry_type === 'opening_payable' ? 1 : -1;
            $balances[(int) $adjustment->vendor_id] += $sign * self::toCents((string) $adjustment->total);
        }

        $allocations = DB::table('vendor_payment_allocations as allocation')
            ->join('vendor_payments as payment', 'payment.id', '=', 'allocation.vendor_payment_id')
            ->where('allocation.restaurant_id', $restaurantId)
            ->where('payment.payment_type', 'payment')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('vendor_payments as reversal')
                    ->whereColumn('reversal.reverses_payment_id', 'payment.id');
            })
            ->selectRaw('payment.vendor_id, SUM(allocation.amount) as total')
            ->groupBy('payment.vendor_id')
            ->get();

        foreach ($allocations as $allocation) {
            $balances[(int) $allocation->vendor_id] -= self::toCents((string) $allocation->total);
        }

        return $balances;
    }

    public function billPaidCents(VendorBill $bill): int
    {
        $total = DB::table('vendor_payment_allocations as allocation')
            ->join('vendor_payments as payment', 'payment.id', '=', 'allocation.vendor_payment_id')
            ->where('allocation.restaurant_id', $bill->restaurant_id)
            ->where('allocation.vendor_bill_id', $bill->id)
            ->where('payment.payment_type', 'payment')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('vendor_payments as reversal')
                    ->whereColumn('reversal.reverses_payment_id', 'payment.id');
            })
            ->sum('allocation.amount');

        return self::toCents((string) $total);
    }

    public function paidCentsForVendor(Vendor $vendor): int
    {
        $total = DB::table('vendor_payment_allocations as allocation')
            ->join('vendor_payments as payment', 'payment.id', '=', 'allocation.vendor_payment_id')
            ->where('allocation.restaurant_id', $vendor->restaurant_id)
            ->where('payment.vendor_id', $vendor->id)
            ->where('payment.payment_type', 'payment')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('vendor_payments as reversal')
                    ->whereColumn('reversal.reverses_payment_id', 'payment.id');
            })
            ->sum('allocation.amount');

        return self::toCents((string) $total);
    }

    public function billOutstandingCents(VendorBill $bill): int
    {
        return max(0, self::toCents((string) $bill->total_amount) - $this->billPaidCents($bill));
    }

    public function billStatus(VendorBill $bill): string
    {
        if ($bill->status === 'draft' || $bill->status === 'cancelled') {
            return $bill->status;
        }

        $outstanding = $this->billOutstandingCents($bill);
        if ($outstanding === 0) {
            return 'paid';
        }
        if ($bill->due_date?->isBefore(today()) ?? false) {
            return 'overdue';
        }

        return $outstanding < self::toCents((string) $bill->total_amount)
            ? 'partially_paid'
            : 'unpaid';
    }

    public function billPaymentsThisMonthCents(int $restaurantId): int
    {
        $start = now(config('app.timezone'))->startOfMonth()->toDateString();
        $end = now(config('app.timezone'))->endOfMonth()->toDateString();
        $amount = DB::table('vendor_payments as payment')
            ->where('payment.restaurant_id', $restaurantId)
            ->where('payment.payment_type', 'payment')
            ->whereBetween('payment.payment_date', [$start, $end])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('vendor_payments as reversal')
                    ->whereColumn('reversal.reverses_payment_id', 'payment.id');
            })
            ->sum('payment.amount');

        return self::toCents((string) $amount);
    }

    public static function toCents(string $amount): int
    {
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount)) {
            throw new \InvalidArgumentException('Invalid vendor amount.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}