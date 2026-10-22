<?php

namespace App\Services;

use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VendorManagementService
{
    public function create(User $actor, array $data): Vendor
    {
        $openingBalance = (string) ($data['opening_balance'] ?? '0.00');
        unset($data['opening_balance']);

        return DB::transaction(function () use ($actor, $data, $openingBalance): Vendor {
            $vendor = $actor->restaurant->vendors()->create([
                ...$data,
                'vendor_code' => null,
                'created_by' => $actor->id,
            ]);
            $vendor->forceFill(['vendor_code' => sprintf('VND%06d', $vendor->id)])->save();

            if (VendorAccountingService::toCents($openingBalance) > 0) {
                $vendor->adjustments()->create([
                    'restaurant_id' => $vendor->restaurant_id,
                    'adjustment_reference' => 'VAD'.strtoupper(Str::random(12)),
                    'entry_type' => 'opening_payable',
                    'amount' => $openingBalance,
                    'entry_date' => today(),
                    'description' => 'Opening payable balance',
                    'created_by' => $actor->id,
                ]);
            }

            $this->audit($actor, $vendor, 'vendor_created', ['vendor_code' => $vendor->vendor_code]);

            return $vendor;
        });
    }

    public function update(User $actor, Vendor $vendor, array $data): Vendor
    {
        if (blank($data['account_number'] ?? null)) {
            unset($data['account_number']);
        }

        return DB::transaction(function () use ($actor, $vendor, $data): Vendor {
            $before = $vendor->only(['name', 'mobile', 'email', 'category', 'status']);
            $vendor->fill($data)->save();
            $this->audit($actor, $vendor, 'vendor_updated', [
                'before' => $before,
                'after' => $vendor->only(array_keys($before)),
            ]);

            return $vendor;
        });
    }

    public function setStatus(User $actor, Vendor $vendor, string $status): Vendor
    {
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw ValidationException::withMessages(['status' => 'Select a valid vendor status.']);
        }

        $previous = $vendor->status;
        return DB::transaction(function () use ($actor, $vendor, $status, $previous): Vendor {
            $vendor->update(['status' => $status]);
            if ($previous !== $status) {
                $this->audit($actor, $vendor, 'vendor_status_changed', ['from' => $previous, 'to' => $status]);
            }

            return $vendor;
        });
    }

    public function archive(User $actor, Vendor $vendor): void
    {
        $hasHistory = $vendor->purchaseOrders()->exists()
            || $vendor->goodsReceipts()->exists()
            || $vendor->bills()->exists()
            || $vendor->payments()->exists()
            || $vendor->adjustments()->exists();

        if ($hasHistory) {
            throw ValidationException::withMessages([
                'vendor' => 'This vendor has purchasing or financial history and cannot be archived.',
            ]);
        }

        $vendor->delete();
        $this->audit($actor, $vendor, 'vendor_archived', ['vendor_code' => $vendor->vendor_code]);
    }

    private function audit(User $owner, Vendor $vendor, string $action, array $details): void
    {
        $admin = request()->attributes->get('restaurant_admin_access_admin');
        $superAdmin = $admin instanceof SuperAdmin ? $admin : null;
        $actorDetails = $superAdmin ? ['admin_id' => $superAdmin->id] : ['owner_id' => $owner->id];

        RestaurantAuditLog::create([
            'actor_id' => $superAdmin?->id,
            'actor_type' => $superAdmin ? 'super_admin' : 'restaurant_owner',
            'restaurant_id' => $vendor->restaurant_id,
            'action' => $action,
            'target_type' => 'vendor',
            'target_id' => $vendor->id,
            'details' => $actorDetails + $details,
        ]);
    }
}