<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\SubscriptionInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->validateFilters($request);
        $invoices = $this->filteredInvoices($filters)
            ->with(['payment.restaurant.owners', 'payment.plan', 'payment.subscription', 'payment.refunds'])
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('super-admin.invoices.index', [
            'invoices' => $invoices,
            'filters' => $filters,
            'restaurants' => Restaurant::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(SubscriptionInvoice $invoice): View
    {
        $this->loadDetails($invoice);

        return view('super-admin.invoices.show', ['invoice' => $invoice]);
    }

    public function print(SubscriptionInvoice $invoice): View
    {
        $this->loadDetails($invoice);

        return view('super-admin.invoices.print', ['invoice' => $invoice]);
    }

    public function pdf(SubscriptionInvoice $invoice): BaseResponse
    {
        $this->loadDetails($invoice);

        return Pdf::loadView('super-admin.invoices.pdf', ['invoice' => $invoice])
            ->setPaper('a4')
            ->download($invoice->invoice_number.'.pdf');
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validateFilters($request);
        $query = $this->filteredInvoices($filters)
            ->with(['payment.restaurant.owners', 'payment.subscription', 'payment.refunds'])
            ->orderBy('id');

        return Response::streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV export stream.');
            }

            fputcsv($output, [
                'Invoice Number',
                'Document Type',
                'Restaurant',
                'Owner',
                'Subscription Plan',
                'Billing Period Start',
                'Billing Period End',
                'Payment Reference',
                'Subtotal',
                'Tax',
                'Total',
                'Refunds Recorded',
                'Net Retained',
                'Payment Status',
                'Issue Date',
                'Demo Data',
            ]);
            foreach ($query->lazyById(500) as $invoice) {
                if (! $invoice instanceof SubscriptionInvoice) {
                    throw new \UnexpectedValueException('The invoice export returned an unexpected record type.');
                }

                $payment = $invoice->payment;
                $period = $payment->subscription;
                fputcsv($output, [
                    $invoice->invoice_number,
                    'Non-tax payment receipt',
                    $payment->restaurant->name,
                    $payment->restaurant->owners->first()?->name ?? '',
                    $payment->plan_name,
                    $period?->starts_at?->toDateString() ?? '',
                    $period?->expires_at?->toDateString() ?? '',
                    $payment->transaction_reference ?? '',
                    $invoice->subtotal,
                    $invoice->tax_amount,
                    $invoice->total,
                    $invoice->recordedRefundAmount(),
                    $invoice->netRetainedAmount(),
                    $payment->status,
                    $invoice->issue_date->timezone(config('app.timezone'))->toDateTimeString(),
                    $payment->isDemo() ? 'YES - NOT ACTUAL MONEY' : 'NO',
                ]);
            }

            fclose($output);
        }, 'tastybite-invoices.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{search?: string, restaurant_id?: string, status?: string, date_from?: string, date_to?: string}
     */
    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'status' => ['nullable', 'in:pending,approved,rejected,refunded'],
            'date_from' => ['nullable', 'date'],
            'date_to' => array_merge(
                ['nullable', 'date'],
                $request->filled('date_from') ? ['after_or_equal:date_from'] : [],
            ),
            'scope' => ['nullable', Rule::in(['all', 'real', 'demo'])],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<SubscriptionInvoice>
     */
    private function filteredInvoices(array $filters): Builder
    {
        $from = isset($filters['date_from'])
            ? CarbonImmutable::parse($filters['date_from'], config('app.timezone'))->startOfDay()->utc()
            : null;
        $to = isset($filters['date_to'])
            ? CarbonImmutable::parse($filters['date_to'], config('app.timezone'))->endOfDay()->utc()
            : null;

        return SubscriptionInvoice::query()
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $search = trim($search);
                $query->where(function (Builder $builder) use ($search): void {
                    $builder->where('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('payment', fn (Builder $payment) => $payment
                            ->where('transaction_reference', 'like', "%{$search}%")
                            ->orWhereHas('restaurant', fn (Builder $restaurant) => $restaurant
                                ->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('restaurant.owners', fn (Builder $owner) => $owner
                                ->where('name', 'like', "%{$search}%")));
                });
            })
            ->when($filters['restaurant_id'] ?? null, fn (Builder $query, string $id) => $query->where('restaurant_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query
                ->whereHas('payment', fn (Builder $payment) => $payment->where('status', $status)))
            ->when(($filters['scope'] ?? 'all') !== 'all', function (Builder $query) use ($filters): void {
                $query->whereHas('payment', function (Builder $payment) use ($filters): void {
                    if (($filters['scope'] ?? 'all') === 'demo') {
                        $payment->where(function (Builder $demo): void {
                            $demo->where('notes', 'like', 'DEMO DATA ONLY%')
                                ->orWhereHas('restaurant', fn (Builder $restaurant) => $restaurant
                                    ->where('slug', 'like', 'demo-tb-2026-%'));
                        });

                        return;
                    }

                    $payment->where(function (Builder $real): void {
                        $real->whereNull('notes')->orWhere('notes', 'not like', 'DEMO DATA ONLY%');
                    })->whereHas('restaurant', fn (Builder $restaurant) => $restaurant
                        ->where('slug', 'not like', 'demo-tb-2026-%'));
                });
            })
            ->when($from, fn (Builder $query, CarbonImmutable $date) => $query->where('issue_date', '>=', $date))
            ->when($to, fn (Builder $query, CarbonImmutable $date) => $query->where('issue_date', '<=', $date));
    }

    private function loadDetails(SubscriptionInvoice $invoice): void
    {
        $invoice->load([
            'restaurant.owners',
            'plan',
            'subscription',
            'issuer',
            'payment.refunds.administrator',
            'payment.approver',
        ]);
    }
}
