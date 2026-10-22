<?php

namespace App\Support;

use Illuminate\Support\Str;

class StatusPresenter
{
    public static function label(?string $status): string
    {
        return filled($status) ? Str::headline($status) : '—';
    }

    public static function badgeClass(?string $status): string
    {
        if (! filled($status)) {
            return 'disabled';
        }

        $normalized = str_replace([' ', '-'], '_', strtolower(trim($status)));

        return match (true) {
            str_contains($normalized, 'pending'), in_array($normalized, ['unpaid', 'partially_paid', 'waiting_for_restaurant', 'draft'], true) => 'pending',
            in_array($normalized, ['active', 'approved', 'paid', 'fully_paid', 'trial', 'published', 'resolved', 'open', 'in_progress'], true) => 'active',
            in_array($normalized, ['rejected', 'denied', 'cancelled', 'canceled', 'refunded'], true) => 'rejected',
            in_array($normalized, ['expired', 'inactive', 'archived', 'disabled', 'closed'], true) => 'disabled',
            $normalized === 'suspended' => 'suspended',
            default => str_replace('_', '-', $normalized),
        };
    }
}
