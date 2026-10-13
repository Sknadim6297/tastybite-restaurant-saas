<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    private const MODULES = [
        'restaurant' => 'Restaurants',
        'restaurant_user' => 'Restaurant Owners',
        'subscription_plan' => 'Subscription Plans',
        'restaurant_subscription' => 'Subscriptions',
        'subscription_payment' => 'Payments',
        'platform_settings' => 'Settings',
        'super_admin' => 'Authentication',
        'announcement' => 'Announcements',
        'support_access_request' => 'Support Access',
    ];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $logs = $this->query($filters)->with(['actor', 'restaurant'])->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return view('super-admin.activity-logs.index', [
            'logs' => $logs,
            'filters' => $filters,
            'admins' => SuperAdmin::query()->orderBy('name')->get(['id', 'name']),
            'restaurants' => Restaurant::query()->orderBy('name')->get(['id', 'name']),
            'modules' => self::MODULES,
        ]);
    }

    public function show(RestaurantAuditLog $activityLog): View
    {
        $activityLog->load(['actor', 'restaurant']);

        return view('super-admin.activity-logs.show', [
            'log' => $activityLog,
            'details' => $this->redact((array) $activityLog->details),
            'module' => self::MODULES[$activityLog->target_type] ?? 'Platform',
            'relatedUrl' => $this->relatedUrl($activityLog),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->query($filters)->with(['actor', 'restaurant'])->orderByDesc('created_at')->orderByDesc('id');

        return response()->streamDownload(static function () use ($query): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Log ID', 'Date & Time', 'Actor', 'Actor Role', 'Module', 'Action', 'Affected Restaurant', 'Affected Record', 'IP Address']);

            foreach ($query->lazy(500) as $log) {
                $row = [
                    $log->id,
                    $log->created_at?->toIso8601String(),
                    $log->actor?->name ?? 'System / unavailable',
                    $log->actor_type,
                    self::MODULES[$log->target_type] ?? 'Platform',
                    $log->action,
                    $log->restaurant?->name ?? '',
                    trim($log->target_type.' #'.($log->target_id ?? '')),
                    $log->ip_address,
                ];
                fputcsv($output, array_map(static function ($value): string {
                    $value = (string) ($value ?? '');

                    return preg_match('/^[\s]*[=+\-@]/', $value) ? "'".$value : $value;
                }, $row));
            }
            fclose($output);
        }, 'tastybite-activity-logs.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'actor_id' => ['nullable', 'integer', 'exists:super_admins,id'],
            'action' => ['nullable', 'string', 'max:100'],
            'module' => ['nullable', Rule::in(array_keys(self::MODULES))],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'search' => ['nullable', 'string', 'max:150'],
        ]);
    }

    private function query(array $filters): Builder
    {
        return RestaurantAuditLog::query()
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['actor_id'] ?? null, fn (Builder $query, int $id) => $query->where('actor_id', $id))
            ->when($filters['action'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            ->when($filters['module'] ?? null, fn (Builder $query, string $module) => $query->where('target_type', $module))
            ->when($filters['restaurant_id'] ?? null, fn (Builder $query, int $id) => $query->where(fn (Builder $nested) => $nested
                ->where('restaurant_id', $id)->orWhere('details->restaurant_id', $id)))
            ->when($filters['search'] ?? null, function (Builder $query, string $term): void {
                $query->where(function (Builder $nested) use ($term): void {
                    $nested->where('action', 'like', "%{$term}%")
                        ->orWhere('target_type', 'like', "%{$term}%")
                        ->orWhere('target_id', 'like', "%{$term}%")
                        ->orWhere('ip_address', 'like', "%{$term}%")
                        ->orWhere('details', 'like', "%{$term}%")
                        ->orWhereHas('actor', fn (Builder $actor) => $actor->where('name', 'like', "%{$term}%"))
                        ->orWhereHas('restaurant', fn (Builder $restaurant) => $restaurant->where('name', 'like', "%{$term}%"));
                });
            });
    }

    private function redact(array $details): array
    {
        foreach ($details as $key => $value) {
            if (preg_match('/password|token|secret|credential|api.?key|proof.?path|remember|account.?fingerprint/i', (string) $key)) {
                $details[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $details[$key] = $this->redact($value);
            }
        }

        return $details;
    }

    private function relatedUrl(RestaurantAuditLog $log): ?string
    {
        return match ($log->target_type) {
            'restaurant' => $log->target_id ? route('super-admin.restaurants.show', $log->target_id) : null,
            'restaurant_user' => $log->target_id ? route('super-admin.restaurant-owners.show', $log->target_id) : null,
            'subscription_plan' => $log->target_id ? route('super-admin.subscription-plans.show', $log->target_id) : null,
            'restaurant_subscription' => $log->target_id ? route('super-admin.subscriptions.show', $log->target_id) : null,
            'subscription_payment' => $log->target_id ? route('super-admin.payments.show', $log->target_id) : null,
            'announcement' => $log->target_id ? route('super-admin.announcements.show', $log->target_id) : null,
            'support_access_request' => $log->target_id ? route('super-admin.support-access.show', $log->target_id) : null,
            default => null,
        };
    }
}
