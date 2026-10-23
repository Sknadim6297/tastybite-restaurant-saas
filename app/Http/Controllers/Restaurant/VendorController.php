<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorGoodsReceipt;
use App\Models\VendorPayment;
use App\Models\VendorPurchaseOrder;
use App\Services\RestaurantAccessContext;
use App\Services\VendorAccountingService;
use App\Services\VendorManagementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VendorController extends Controller
{
    private const INDIAN_STATES = [
        'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat',
        'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh',
        'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab',
        'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand',
        'West Bengal', 'Andaman and Nicobar Islands', 'Chandigarh',
        'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir', 'Ladakh',
        'Lakshadweep', 'Puducherry',
    ];

    public function __construct(
        private readonly VendorManagementService $vendors,
        private readonly VendorAccountingService $accounting,
    ) {}

    public function overview(): View
    {
        $owner = $this->owner();
        $restaurantId = (int) $owner->restaurant_id;
        $vendorQuery = $owner->restaurant->vendors();
        $balances = $this->accounting->balancesForRestaurant($restaurantId);
        $postedBills = VendorBill::query()->where('restaurant_id', $restaurantId)
            ->whereNotNull('posted_at')->whereNotIn('status', ['draft', 'cancelled']);
        $totalPurchases = $postedBills->sum('total_amount');
        $outstandingCents = array_sum(array_map(static fn (int $balance): int => max(0, $balance), $balances));
        $vendors = $vendorQuery->get();
        $overdueCents = 0;
        $overdueBills = VendorBill::query()->with('vendor')
            ->where('restaurant_id', $restaurantId)->whereNotNull('posted_at')
            ->whereNotNull('due_date')->whereDate('due_date', '<', today())
            ->whereNotIn('status', ['draft', 'cancelled'])->latest('due_date')->get();
        foreach ($overdueBills as $bill) {
            $remaining = $this->accounting->billOutstandingCents($bill);
            if ($remaining > 0) {
                $overdueCents += $remaining;
            }
        }

        $topVendors = $vendors->map(fn (Vendor $vendor): array => [
            'vendor' => $vendor,
            'purchase_total' => VendorBill::query()->where('restaurant_id', $restaurantId)
                ->where('vendor_id', $vendor->id)->whereNotNull('posted_at')
                ->whereNotIn('status', ['draft', 'cancelled'])->sum('total_amount'),
            'outstanding' => $balances[$vendor->id] ?? 0,
        ])->sortByDesc('purchase_total')->take(5)->values();

        return view('restaurant.vendors.overview', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'totalVendors' => $vendorQuery->count(),
            'activeVendors' => $owner->restaurant->vendors()->where('status', 'active')->count(),
            'totalPurchases' => (string) $totalPurchases,
            'outstandingPayables' => VendorAccountingService::formatCents($outstandingCents),
            'paymentsThisMonth' => VendorAccountingService::formatCents($this->accounting->billPaymentsThisMonthCents($restaurantId)),
            'overdueBills' => $overdueBills->filter(fn (VendorBill $bill): bool => $this->accounting->billOutstandingCents($bill) > 0)->take(5),
            'overdueAmount' => VendorAccountingService::formatCents($overdueCents),
            'recentOrders' => VendorPurchaseOrder::query()->with('vendor')->where('restaurant_id', $restaurantId)->latest('order_date')->limit(5)->get(),
            'recentPayments' => VendorPayment::query()->with('vendor')->where('restaurant_id', $restaurantId)->latest('payment_date')->limit(5)->get(),
            'recentReceipts' => VendorGoodsReceipt::query()->with(['vendor', 'purchaseOrder'])->where('restaurant_id', $restaurantId)->latest('received_date')->limit(5)->get(),
            'topVendors' => $topVendors,
            'outstandingVendors' => $vendors->filter(fn (Vendor $vendor): bool => ($balances[$vendor->id] ?? 0) > 0)
                ->map(fn (Vendor $vendor): array => ['vendor' => $vendor, 'balance' => $balances[$vendor->id]])
                ->sortByDesc('balance')->take(5)->values(),
            'money' => VendorAccountingService::class,
        ]);
    }

    public function index(Request $request): View
    {
        $owner = $this->owner();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'category' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', Rule::in(['vendor_code', 'name', 'mobile', 'category', 'status', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $query = $owner->restaurant->vendors();
        if (filled($filters['search'] ?? null)) {
            $search = trim($filters['search']);
            $query->where(fn (Builder $builder) => $builder
                ->where('vendor_code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('contact_person', 'like', "%{$search}%")
                ->orWhere('mobile', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['category'] ?? null)) {
            $query->where('category', $filters['category']);
        }

        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';
        $vendors = $query->orderBy($sort, $direction)->orderBy('id')->paginate(15)->withQueryString();
        $balances = $this->accounting->balancesForRestaurant((int) $owner->restaurant_id);

        return view('restaurant.vendors.index', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'vendors' => $vendors,
            'filters' => $filters,
            'categories' => $owner->restaurant->vendors()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'balances' => $balances,
            'money' => VendorAccountingService::class,
        ]);
    }

    public function create(): View
    {
        $owner = $this->owner();

        return view('restaurant.vendors.form', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'vendor' => new Vendor(),
            'states' => self::INDIAN_STATES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $validated = $this->validateVendor($request, $owner);
        $vendor = $this->vendors->create($owner, $validated);

        return redirect()->route('restaurant.vendors.show', $vendor)
            ->with('status', 'Vendor added successfully.');
    }

    public function show(int $vendor): View
    {
        $owner = $this->owner();
        $vendor = $owner->restaurant->vendors()->findOrFail($vendor);
        $balance = $this->accounting->balancesForRestaurant((int) $owner->restaurant_id)[$vendor->id] ?? 0;
        $vendorBills = VendorBill::query()->where('restaurant_id', $owner->restaurant_id)
            ->where('vendor_id', $vendor->id)->whereNotNull('posted_at')
            ->whereNotIn('status', ['draft', 'cancelled']);
        $postedBills = (clone $vendorBills)->get();
        $overdueAmount = 0;
        foreach ($postedBills as $bill) {
            if ($bill->due_date?->isBefore(today()) ?? false) {
                $overdueAmount += $this->accounting->billOutstandingCents($bill);
            }
        }
        $orders = $vendor->purchaseOrders()->latest('order_date')->paginate(10, ['*'], 'orders_page');
        $receipts = $vendor->goodsReceipts()->with('purchaseOrder')->latest('received_date')->paginate(10, ['*'], 'receipts_page');
        $bills = $vendor->bills()->latest('invoice_date')->paginate(10, ['*'], 'bills_page');
        $payments = $vendor->payments()->with('recorder')->latest('payment_date')->paginate(10, ['*'], 'payments_page');

        return view('restaurant.vendors.show', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'vendor' => $vendor,
            'balance' => $balance,
            'totalPurchases' => (string) (clone $vendorBills)->sum('total_amount'),
            'totalPaid' => VendorAccountingService::formatCents($this->accounting->paidCentsForVendor($vendor)),
            'overdueAmount' => $overdueAmount,
            'lastPurchaseDate' => (clone $vendorBills)->max('invoice_date'),
            'lastPaymentDate' => $vendor->payments()->where('payment_type', 'payment')->latest('payment_date')->value('payment_date'),
            'orders' => $orders,
            'receipts' => $receipts,
            'bills' => $bills,
            'payments' => $payments,
            'accounting' => $this->accounting,
            'money' => VendorAccountingService::class,
            'maskedAccountNumber' => $vendor->account_number ? '****'.substr($vendor->account_number, -4) : 'Not provided',
        ]);
    }

    public function edit(int $vendor): View
    {
        $owner = $this->owner();
        $vendor = $owner->restaurant->vendors()->findOrFail($vendor);

        return view('restaurant.vendors.form', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'vendor' => $vendor,
            'states' => self::INDIAN_STATES,
        ]);
    }

    public function update(Request $request, int $vendor): RedirectResponse
    {
        $owner = $this->owner();
        $vendor = $owner->restaurant->vendors()->findOrFail($vendor);
        $validated = $this->validateVendor($request, $owner, $vendor);
        if (blank($validated['account_number'] ?? null)) {
            unset($validated['account_number']);
        }
        $this->vendors->update($owner, $vendor, $validated);

        return redirect()->route('restaurant.vendors.show', $vendor)
            ->with('status', 'Vendor details updated.');
    }

    public function status(Request $request, int $vendor): RedirectResponse
    {
        $owner = $this->owner();
        $vendor = $owner->restaurant->vendors()->findOrFail($vendor);
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        $this->vendors->setStatus($owner, $vendor, $validated['status']);

        return back()->with('status', 'Vendor status updated.');
    }

    public function archive(int $vendor): RedirectResponse
    {
        $owner = $this->owner();
        $vendor = $owner->restaurant->vendors()->findOrFail($vendor);
        $this->vendors->archive($owner, $vendor);

        return redirect()->route('restaurant.vendors.index')->with('status', 'Vendor archived.');
    }

    private function validateVendor(Request $request, User $owner, ?Vendor $vendor = null): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'mobile' => trim((string) $request->input('mobile')),
            'email' => filled($request->input('email')) ? mb_strtolower(trim((string) $request->input('email'))) : null,
            'upi_id' => filled($request->input('upi_id')) ? trim((string) $request->input('upi_id')) : null,
        ]);

        $restaurantId = (int) $owner->restaurant_id;
        $validation = [
            'name' => ['required', 'string', 'max:180'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'mobile' => [
                'required', 'string', 'max:32', 'regex:/^\+?[0-9][0-9\s().-]{5,28}[0-9]$/',
                Rule::unique('vendors', 'mobile')->where('restaurant_id', $restaurantId)->ignore($vendor?->id),
            ],
            'alternate_mobile' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9\s().-]{5,28}[0-9]$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'category' => ['nullable', 'string', 'max:80'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'pan_number' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]{5,20}$/'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'address_line_1' => ['nullable', 'string', 'max:180'],
            'address_line_2' => ['nullable', 'string', 'max:180'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100', Rule::in([...self::INDIAN_STATES, ...array_filter([$vendor?->state])])],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:100'],
            'account_holder_name' => ['nullable', 'string', 'max:180'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:80'],
            'ifsc_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]{4,20}$/'],
            'bank_branch' => ['nullable', 'string', 'max:120'],
            'upi_id' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
        return $request->validate($validation, [
            'state.in' => 'Select a valid Indian state or union territory.',
        ]);
    }

    private function owner(): User
    {
        return app(RestaurantAccessContext::class)->owner();
    }
}