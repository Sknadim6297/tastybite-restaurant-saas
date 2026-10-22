<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VendorGoodsReceipt;
use App\Models\VendorPurchaseOrder;
use App\Models\VendorGoodsReceiptItem;
use App\Services\RestaurantAccessContext;
use App\Services\InventoryStockService;
use App\Services\VendorProcurementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VendorGoodsReceiptController extends Controller
{
    public function __construct(
        private readonly VendorProcurementService $procurement,
        private readonly InventoryStockService $inventory,
    ) {}

    public function index(Request $request): View
    {
        $owner = $this->owner();
        $filters = $request->validate([
            'vendor_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $receipts = VendorGoodsReceipt::query()->with(['vendor', 'purchaseOrder', 'receiver'])
            ->where('restaurant_id', $owner->restaurant_id)
            ->when($filters['vendor_id'] ?? null, fn ($query, int $id) => $query->where('vendor_id', $id))
            ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('received_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('received_date', '<=', $date))
            ->latest('received_date')->paginate(15)->withQueryString();

        return view('restaurant.vendors.goods-received.index', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'receipts' => $receipts,
            'vendors' => $owner->restaurant->vendors()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function create(int $purchaseOrder): View
    {
        $owner = $this->owner();
        $order = $owner->restaurant->vendorPurchaseOrders()->with(['vendor', 'items'])->findOrFail($purchaseOrder);
        abort_unless(in_array($order->status, ['confirmed', 'partially_received'], true), 404);
        $received = VendorGoodsReceipt::query()->with('items')
            ->where('restaurant_id', $owner->restaurant_id)
            ->where('purchase_order_id', $order->id)->get()
            ->flatMap->items->groupBy('purchase_order_item_id')
            ->map(fn ($items) => $items->sum(fn ($item): int => $this->quantityMilli((string) $item->received_quantity)));

        return view('restaurant.vendors.goods-received.form', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'order' => $order,
            'received' => $received,
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, int $purchaseOrder): RedirectResponse
    {
        $owner = $this->owner();
        $order = $owner->restaurant->vendorPurchaseOrders()->findOrFail($purchaseOrder);
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:64'],
            'received_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.purchase_order_item_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('vendor_purchase_order_items', 'id')->where('restaurant_id', $owner->restaurant_id)->where('purchase_order_id', $order->id),
            ],
            'items.*.received_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3', 'max:999999999.999'],
            'items.*.rejected_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,3', 'max:999999999.999'],
            'items.*.batch_number' => ['nullable', 'string', 'max:80'],
            'items.*.manufactured_at' => ['nullable', 'date_format:Y-m-d'],
            'items.*.expires_at' => ['nullable', 'date_format:Y-m-d'],
        ]);
        foreach ($validated['items'] as $index => $item) {
            if (! empty($item['manufactured_at']) && ! empty($item['expires_at'])
                && $item['expires_at'] < $item['manufactured_at']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.{$index}.expires_at" => 'The expiry date must not be before the manufacturing date.',
                ]);
            }
        }
        [$receipt, $inventoryResult] = DB::transaction(function () use ($owner, $order, $validated): array {
            $receipt = $this->procurement->receive($owner, $order, $validated);
            foreach ($validated['items'] as $submitted) {
                $receiptItem = $receipt->items->firstWhere('purchase_order_item_id', (int) $submitted['purchase_order_item_id']);
                if (! $receiptItem) {
                    continue;
                }
                DB::table('inventory_grn_item_details')->insertOrIgnore([
                    'restaurant_id' => $owner->restaurant_id,
                    'receipt_item_id' => $receiptItem->id,
                    'batch_number' => $submitted['batch_number'] ?? null,
                    'manufactured_at' => $submitted['manufactured_at'] ?? null,
                    'expires_at' => $submitted['expires_at'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return [$receipt, $this->inventory->postGoodsReceipt($owner, $receipt)];
        });

        $message = $inventoryResult['complete']
            ? 'Goods receipt recorded and mapped items posted to inventory.'
            : 'Goods receipt recorded. Inventory posting is pending: '.implode(', ', $inventoryResult['pending']).'.';

        return redirect()->route('restaurant.vendors.goods-received.show', $receipt)->with('status', $message);
    }

    public function show(int $receipt): View
    {
        $owner = $this->owner();
        $receipt = $owner->restaurant->vendorGoodsReceipts()->with(['vendor', 'purchaseOrder', 'receiver', 'items'])->findOrFail($receipt);
        $acceptedLines = $receipt->items->filter(fn ($item): bool => \Brick\Math\BigDecimal::of((string) $item->accepted_quantity)->isGreaterThan(0));
        $postedIds = DB::table('inventory_movements')->where('restaurant_id', $owner->restaurant_id)
            ->where('source_type', 'vendor_grn')->where('source_id', $receipt->id)->pluck('source_line_id')
            ->map(fn ($id): int => (int) $id)->all();
        $mappingIds = DB::table('inventory_vendor_item_mappings')->where('restaurant_id', $owner->restaurant_id)
            ->whereIn('purchase_order_item_id', $acceptedLines->pluck('purchase_order_item_id'))
            ->pluck('purchase_order_item_id')->map(fn ($id): int => (int) $id)->all();
        $pendingInventory = $acceptedLines->filter(fn ($item): bool => ! in_array($item->id, $postedIds, true)
            && in_array($item->purchase_order_item_id, $mappingIds));
        $unmappedInventory = $acceptedLines->filter(fn ($item): bool => ! in_array($item->purchase_order_item_id, $mappingIds));

        return view('restaurant.vendors.goods-received.show', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'receipt' => $receipt,
            'pendingInventory' => $pendingInventory,
            'unmappedInventory' => $unmappedInventory,
        ]);
    }

    public function postInventory(int $receipt): RedirectResponse
    {
        $owner = $this->owner();
        $model = $owner->restaurant->vendorGoodsReceipts()->with('items')->findOrFail($receipt);
        $result = $this->inventory->postGoodsReceipt($owner, $model);
        $message = $result['complete']
            ? 'Mapped goods receipt quantities posted to inventory.'
            : 'Inventory posting is pending: '.implode(', ', $result['pending']).'.';

        return redirect()->route('restaurant.vendors.goods-received.show', $model)->with('status', $message);
    }

    private function quantityMilli(string $quantity): int
    {
        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '0');

        return ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');
    }

    private function owner(): User
    {
        return app(RestaurantAccessContext::class)->owner();
    }
}