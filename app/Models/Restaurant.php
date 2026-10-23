<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Restaurant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'address',
        'status',
        'approval_status',
        'logo_path',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function owners(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'owner');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(RestaurantAuditLog::class, 'target_id')
            ->where('target_type', 'restaurant')
            ->orderByDesc('created_at');
    }

    public function approvalHistory(): HasMany
    {
        return $this->hasMany(RestaurantApprovalHistory::class)->orderByDesc('created_at');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(RestaurantSubscription::class);
    }

    public function menuCategories(): HasMany
    {
        return $this->hasMany(RestaurantMenuCategory::class)->orderBy('sort_order')->orderBy('name');
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(RestaurantMenuItem::class)->orderBy('sort_order')->orderBy('name');
    }

    public function floors(): HasMany
    {
        return $this->hasMany(RestaurantFloor::class)->orderBy('display_order')->orderBy('name');
    }

    public function diningSections(): HasMany
    {
        return $this->hasMany(RestaurantSection::class);
    }

    public function diningTables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class);
    }

    public function tableReservations(): HasMany
    {
        return $this->hasMany(RestaurantTableReservation::class);
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }

    public function vendorPurchaseOrders(): HasMany
    {
        return $this->hasMany(VendorPurchaseOrder::class);
    }

    public function vendorGoodsReceipts(): HasMany
    {
        return $this->hasMany(VendorGoodsReceipt::class);
    }

    public function vendorBills(): HasMany
    {
        return $this->hasMany(VendorBill::class);
    }

    public function vendorPayments(): HasMany
    {
        return $this->hasMany(VendorPayment::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function inventoryCategories(): HasMany
    {
        return $this->hasMany(InventoryCategory::class);
    }

    public function inventoryLocations(): HasMany
    {
        return $this->hasMany(InventoryLocation::class);
    }

    public function currentSubscription(): HasOne
    {
        return $this->hasOne(RestaurantSubscription::class)->latestOfMany();
    }

    public function currentSubscriptionAt(CarbonImmutable $at): ?RestaurantSubscription
    {
        $instant = $at->utc();

        return $this->subscriptions()
            ->whereIn('status', ['active', 'trial'])
            ->where('starts_at', '<=', $instant)
            ->where('expires_at', '>=', $instant)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first() ?? $this->currentSubscription()->first();
    }

    public function subscriptionEvents(): HasMany
    {
        return $this->hasMany(RestaurantSubscriptionEvent::class)->orderByDesc('created_at');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getLogoUrlAttribute(): ?string
    {
        $path = $this->logo_path;
        $ownedPathPattern = '/\Arestaurant-branding\/'.preg_quote((string) $this->getKey(), '/').'\/[A-Za-z0-9._-]+\z/';

        if (
            ! \is_string($path)
            || ! preg_match($ownedPathPattern, $path)
            || \in_array(basename($path), ['.', '..'], true)
            || ! Storage::disk('public')->exists($path)
        ) {
            return null;
        }

        return route('public.uploads.show', [
            'directory' => 'restaurant-branding',
            'restaurantId' => $this->getKey(),
            'filename' => basename($path),
        ]);
    }
}
