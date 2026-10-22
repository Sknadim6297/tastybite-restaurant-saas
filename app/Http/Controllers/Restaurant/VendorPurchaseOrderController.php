<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VendorPurchaseOrder;
use App\Services\RestaurantAccessContext;
use App\Services\VendorProcurementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class VendorPurchaseOrderController extends Controller
{
    public function __construct(private readonly VendorProcurementService $procurement) {}

    public function index(Request $request): View
    {
        $owner = $this->owner();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'vendor_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['draft', 'confirmed', 'partially_received', 'received', 'cancelled'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $query = VendorPurchaseOrder::query()->where('restaurant_id', $owner->restaurant_id)->with('vendor');
        if (filled($filters['search'] ?? null)) {
            $term = trim($filters['search']);
            $query->where(fn ($builder) => $builder->where('po_number', 'like', "%{$term}%")
                ->orWhereHas('vendor', fn ($vendor) => $vendor->where('name', 'like', "%{$term}%")));
        }
        $query->when($filters['vendor_id'] ?? null, fn ($builder, int $vendorId) => $builder->where('vendor_id', $vendorId))
            ->when($filters['status'] ?? null, fn ($builder, string $status) => $builder->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($builder, string $date) => $builder->whereDate('order_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($builder, string $date) => $builder->whereDate('order_date', '<=', $date));

        return view('restaurant.vendors.purchase-orders.index', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'orders' => $query->latest('order_date')->paginate(15)->withQueryString(),
            'vendors' => $owner->restaurant->vendors()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): View
    {
        $owner = $this->owner();
        $validated = $request->validate(['vendor_id' => ['nullable', 'integer']]);

        return view('restaurant.vendors.purchase-orders.form', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'order' => new VendorPurchaseOrder(['order_date' => today()]),
            'items' => collect(),
            'vendors' => $owner->restaurant->vendors()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'selectedVendorId' => $validated['vendor_id'] ?? null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $validated = $this->validateOrder($request, $owner);
        $vendor = $owner->restaurant->vendors()->where('status', 'active')->findOrFail($validated['vendor_id']);
        $order = $this->procurement->createOrder($owner, $vendor, $validated);

        return redirect()->route('restaurant.vendors.purchase-orders.show', $order)
            ->with('status', 'Purchase order saved as a draft.');
    }

    public function show(int $purchaseOrder): View
    {
        $owner = $this->owner();
        $order = $owner->restaurant->vendorPurchaseOrders()->with(['vendor', 'creator', 'items'])->findOrFail($purchaseOrder);

        return view('restaurant.vendors.purchase-orders.show', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'order' => $order,
        ]);
    }

    public function edit(int $purchaseOrder): View
    {
        $owner = $this->owner();
        $order = $owner->restaurant->vendorPurchaseOrders()->with('items')->findOrFail($purchaseOrder);
        abort_unless($order->status === 'draft' && ! $order->goodsReceipts()->exists(), 404);

        return view('restaurant.vendors.purchase-orders.form', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'order' => $order,
            'items' => $order->items,
            'vendors' => $owner->restaurant->vendors()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'selectedVendorId' => $order->vendor_id,
        ]);
    }

    public function update(Request $request, int $purchaseOrder): RedirectResponse
    {
        $owner = $this->owner();
        $order = $owner->restaurant->vendorPurchaseOrders()->findOrFail($purchaseOrder);
        $order = $this->procurement->updateDraft($owner, $order, $this->validateOrder($request, $owner));

        return redirect()->route('restaurant.vendors.purchase-orders.show', $order)->with('status', 'Draft purchase order updated.');
    }

    public function confirm(int $purchaseOrder): RedirectResponse
    {
        $owner = $this->owner();
        $order = $owner->restaurant->vendorPurchaseOrders()->findOrFail($purchaseOrder);
        $this->procurement->confirm($owner, $order);

        return back()->with('status', 'Purchase order confirmed.');
    }

    public function cancel(int $purchaseOrder): RedirectResponse
    {
        $owner = $this->owner();
        $order = $owner->restaurant->vendorPurchaseOrders()->findOrFail($purchaseOrder);
        $this->procurement->cancel($owner, $order);

        return back()->with('status', 'Purchase order cancelled.');
    }

    public function print(int $purchaseOrder): View
    {
        $owner = $this->owner();
        $order = $owner->restaurant->vendorPurchaseOrders()->with(['vendor', 'items'])->findOrFail($purchaseOrder);

        return view('restaurant.vendors.purchase-orders.print', [
            'order' => $order,
            'restaurant' => $owner->restaurant,
        ]);
    }

    public function pdf(int $purchaseOrder): Response
    {
        $owner = $this->owner();
        $order = $owner->restaurant->vendorPurchaseOrders()->with(['vendor', 'items'])->findOrFail($purchaseOrder);

        return Pdf::loadView('restaurant.vendors.purchase-orders.pdf', [
            'order' => $order,
            'restaurant' => $owner->restaurant,
        ])->setPaper('a4')->download(($order->po_number ?? 'purchase-order').'.pdf');
    }

    private function validateOrder(Request $request, User $owner): array
    {
        return $request->validate([
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')->where('restaurant_id', $owner->restaurant_id)->where('status', 'active')],
            'order_date' => ['required', 'date_format:Y-m-d'],
            'expected_delivery_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.item_name' => ['required', 'string', 'max:180'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:999999999.999'],
            'items.*.unit' => ['required', 'string', 'max:32'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999.99'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999.99'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
        ]);
    }

    private function owner(): User
    {
        return app(RestaurantAccessContext::class)->owner();
    }
}