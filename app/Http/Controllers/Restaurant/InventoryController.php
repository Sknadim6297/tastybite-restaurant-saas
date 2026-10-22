<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryUnit;
use App\Models\MenuRecipe;
use App\Models\RestaurantAuditLog;
use App\Models\RestaurantMenuItem;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Services\RestaurantAccessContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InventoryController extends Controller
{
    private const MOVEMENT_TYPES = [
        'opening', 'manual_receipt', 'purchase_receipt', 'consumption', 'manual_issue',
        'wastage', 'transfer_in', 'transfer_out', 'adjustment_in', 'adjustment_out', 'reversal',
    ];

    public function __construct(private readonly InventoryStockService $stock) {}

    public function overview(): View
    {
        $owner = $this->owner();
        $restaurantId = (int) $owner->restaurant_id;
        $settings = DB::table('inventory_settings')->where('restaurant_id', $restaurantId)->first();
        $expiryWarningDays = (int) ($settings->expiry_warning_days ?? 30);
        $lowStockAlertsEnabled = (bool) ($settings->low_stock_alerts_enabled ?? true);
        $expiryAlertsEnabled = (bool) ($settings->expiry_alerts_enabled ?? true);
        $items = InventoryItem::query()->where('restaurant_id', $restaurantId)->where('status', 'active');
        $balances = DB::table('inventory_stock_balances as balance')
            ->join('inventory_items as item', 'item.id', '=', 'balance.inventory_item_id')
            ->where('balance.restaurant_id', $restaurantId)
            ->where('item.status', 'active');
        $lowStockQuery = DB::table('inventory_items as item')
            ->join('inventory_units as unit', 'unit.id', '=', 'item.base_unit_id')
            ->leftJoin('inventory_stock_balances as balance', function ($join) use ($restaurantId): void {
                $join->on('balance.inventory_item_id', '=', 'item.id')
                    ->where('balance.restaurant_id', '=', $restaurantId);
            })
            ->where('item.restaurant_id', $restaurantId)->where('item.status', 'active')
            ->selectRaw('item.id, item.name, item.item_code, item.reorder_level, item.base_unit_id, unit.abbreviation, COALESCE(SUM(balance.quantity), 0) as current_quantity')
            ->groupBy('item.id', 'item.name', 'item.item_code', 'item.reorder_level', 'item.base_unit_id', 'unit.abbreviation')
            ->havingRaw('COALESCE(SUM(balance.quantity), 0) <= item.reorder_level')
            ->orderBy('current_quantity');
        $lowStock = (clone $lowStockQuery)->limit(8)->get();
        $expiring = DB::table('inventory_item_batches as batch')
            ->join('inventory_items as item', 'item.id', '=', 'batch.inventory_item_id')
            ->join('inventory_locations as location', 'location.id', '=', 'batch.location_id')
            ->where('batch.restaurant_id', $restaurantId)->where('item.status', 'active')
            ->where('batch.available_quantity', '>', 0)->whereNotNull('batch.expires_at')
            ->whereDate('batch.expires_at', '>=', today())
            ->whereDate('batch.expires_at', '<=', today()->addDays($expiryWarningDays))
            ->orderBy('batch.expires_at')->limit(8)->get([
                'batch.batch_number', 'batch.expires_at', 'batch.available_quantity',
                'item.name as item_name', 'location.name as location_name',
            ]);
        $startMonth = now()->startOfMonth();
        $monthMovements = InventoryMovement::query()->where('restaurant_id', $restaurantId)
            ->where('occurred_at', '>=', $startMonth);
        $stockValue = (string) $balances->selectRaw('COALESCE(SUM(balance.quantity * balance.average_unit_cost), 0) as stock_value')
            ->value('stock_value');
        $stockOut = (string) (clone $monthMovements)->whereIn('movement_type', ['consumption', 'manual_issue'])->sum('quantity_out');
        $stockIn = (string) (clone $monthMovements)->sum('quantity_in');
        $wastageValue = (string) (clone $monthMovements)->where('movement_type', 'wastage')->sum(DB::raw('quantity_out * unit_cost'));
        $trendStart = now()->startOfMonth()->subMonths(5);
        $runningValue = BigDecimal::of((string) (InventoryMovement::query()->where('restaurant_id', $restaurantId)
            ->where('occurred_at', '<', $trendStart)->sum('value') ?: '0'));
        $monthlyChanges = [];
        foreach (InventoryMovement::query()->where('restaurant_id', $restaurantId)
            ->where('occurred_at', '>=', $trendStart)->orderBy('occurred_at')->get(['occurred_at', 'value']) as $movement) {
            $month = $movement->occurred_at->format('Y-m');
            $monthlyChanges[$month] = ($monthlyChanges[$month] ?? BigDecimal::zero())->plus((string) $movement->value);
        }
        $stockValueTrend = [];
        $month = $trendStart->copy();
        for ($index = 0; $index < 6; $index++) {
            $runningValue = $runningValue->plus($monthlyChanges[$month->format('Y-m')] ?? BigDecimal::zero());
            $stockValueTrend[] = [
                'label' => $month->format('M Y'),
                'value' => $runningValue->toScale(2, RoundingMode::HALF_UP)->__toString(),
            ];
            $month->addMonth();
        }
        $topConsumed = DB::table('inventory_movements as movement')
            ->join('inventory_items as item', 'item.id', '=', 'movement.inventory_item_id')
            ->join('inventory_units as unit', 'unit.id', '=', 'item.base_unit_id')
            ->where('movement.restaurant_id', $restaurantId)
            ->whereIn('movement.movement_type', ['consumption', 'manual_issue'])
            ->where('movement.quantity_out', '>', 0)
            ->selectRaw('item.id, item.name, unit.abbreviation, SUM(movement.quantity_out) as consumed_quantity')
            ->groupBy('item.id', 'item.name', 'unit.abbreviation')
            ->orderByDesc('consumed_quantity')->limit(5)->get();
        $recentMovements = InventoryMovement::query()->with(['item.baseUnit', 'location'])
            ->where('restaurant_id', $restaurantId)->latest('occurred_at')->limit(8)->get();

        return view('restaurant.inventory.overview', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'totalItems' => (clone $items)->count(),
            'stockValue' => $stockValue,
            'expiryWarningDays' => $expiryWarningDays,
            'lowStockAlertsEnabled' => $lowStockAlertsEnabled,
            'expiryAlertsEnabled' => $expiryAlertsEnabled,
            'lowStockCount' => DB::query()->fromSub(clone $lowStockQuery, 'low_stock')->count(),
            'outOfStockCount' => DB::table('inventory_items as item')
                ->leftJoin('inventory_stock_balances as balance', function ($join) use ($restaurantId): void {
                    $join->on('balance.inventory_item_id', '=', 'item.id')->where('balance.restaurant_id', '=', $restaurantId);
                })->where('item.restaurant_id', $restaurantId)->where('item.status', 'active')
                ->groupBy('item.id')->havingRaw('COALESCE(SUM(balance.quantity), 0) <= 0')->get(['item.id'])->count(),
            'expiringCount' => DB::table('inventory_item_batches')->where('restaurant_id', $restaurantId)
                ->where('available_quantity', '>', 0)->whereNotNull('expires_at')
                ->whereDate('expires_at', '>=', today())->whereDate('expires_at', '<=', today()->addDays($expiryWarningDays))->count(),
            'expiredCount' => DB::table('inventory_item_batches')->where('restaurant_id', $restaurantId)->where('available_quantity', '>', 0)->whereDate('expires_at', '<', today())->count(),
            'receivedThisMonth' => $stockIn,
            'consumedThisMonth' => $stockOut,
            'wastageValue' => $wastageValue,
            'pendingAdjustments' => DB::table('inventory_stock_counts')->where('restaurant_id', $restaurantId)->where('status', 'draft')->count(),
            'lowStock' => $lowStock,
            'expiring' => $expiring,
            'recentMovements' => $recentMovements,
            'stockValueTrend' => $stockValueTrend,
            'topConsumed' => $topConsumed,
            'recentReceipts' => DB::table('vendor_goods_receipts as receipt')
                ->join('vendors as vendor', 'vendor.id', '=', 'receipt.vendor_id')
                ->where('receipt.restaurant_id', $restaurantId)->latest('receipt.received_date')->limit(5)
                ->get(['receipt.id', 'receipt.grn_number', 'receipt.received_date', 'vendor.name as vendor_name']),
        ]);
    }

    public function items(Request $request): View
    {
        $owner = $this->owner();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'stock' => ['nullable', Rule::in(['low', 'out', 'available'])],
            'sort' => ['nullable', Rule::in(['item_code', 'name', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $restaurantId = (int) $owner->restaurant_id;
        $query = InventoryItem::query()->with(['category', 'baseUnit', 'balances.location'])
            ->where('restaurant_id', $restaurantId)
            ->when($filters['search'] ?? null, fn (Builder $builder, string $search) => $builder
                ->where(fn (Builder $inner) => $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('item_code', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")))
            ->when($filters['category_id'] ?? null, fn (Builder $builder, int $categoryId) => $builder->where('category_id', $categoryId))
            ->when($filters['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('status', $status));
        if (($filters['stock'] ?? null) === 'out') {
            $query->whereRaw('COALESCE((SELECT SUM(balance.quantity) FROM inventory_stock_balances balance WHERE balance.restaurant_id = inventory_items.restaurant_id AND balance.inventory_item_id = inventory_items.id), 0) <= 0');
        } elseif (($filters['stock'] ?? null) === 'available') {
            $query->whereRaw('COALESCE((SELECT SUM(balance.quantity) FROM inventory_stock_balances balance WHERE balance.restaurant_id = inventory_items.restaurant_id AND balance.inventory_item_id = inventory_items.id), 0) > 0');
        } elseif (($filters['stock'] ?? null) === 'low') {
            $query->whereRaw('COALESCE((SELECT SUM(balance.quantity) FROM inventory_stock_balances balance WHERE balance.restaurant_id = inventory_items.restaurant_id AND balance.inventory_item_id = inventory_items.id), 0) <= inventory_items.reorder_level');
        }
        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';
        $items = $query->orderBy($sort, $direction)->paginate(20)->withQueryString();
        foreach ($items as $item) {
            $quantity = BigDecimal::zero();
            $value = BigDecimal::zero();
            foreach ($item->balances as $balance) {
                $quantity = $quantity->plus((string) $balance->quantity);
                $value = $value->plus(BigDecimal::of((string) $balance->quantity)->multipliedBy((string) $balance->average_unit_cost));
            }
            $item->current_quantity = $quantity->toScale(6)->__toString();
            $item->stock_value = $value->toScale(2, RoundingMode::HALF_UP)->__toString();
            $item->average_unit_cost = $quantity->isGreaterThan(0)
                ? $value->dividedBy($quantity, 6, RoundingMode::HALF_UP)->toScale(6, RoundingMode::HALF_UP)->__toString()
                : '0.000000';
        }

        return view('restaurant.inventory.items.index', [
            'user' => $owner, 'restaurant' => $owner->restaurant, 'items' => $items,
            'categories' => InventoryCategory::query()->where('restaurant_id', $restaurantId)->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function exportItems(Request $request): StreamedResponse
    {
        $owner = $this->owner();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', Rule::exists('inventory_categories', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'stock' => ['nullable', Rule::in(['low', 'out', 'available'])],
            'sort' => ['nullable', Rule::in(['item_code', 'name', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $query = InventoryItem::query()->with(['category', 'baseUnit', 'balances'])
            ->where('restaurant_id', $owner->restaurant_id)
            ->when($filters['search'] ?? null, fn (Builder $builder, string $search) => $builder
                ->where(fn (Builder $inner) => $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('item_code', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->when($filters['category_id'] ?? null, fn (Builder $builder, int $categoryId) => $builder->where('category_id', $categoryId))
            ->when($filters['status'] ?? null, fn (Builder $builder, string $status) => $builder->where('status', $status));
        if (($filters['stock'] ?? null) === 'out') {
            $query->whereRaw('COALESCE((SELECT SUM(balance.quantity) FROM inventory_stock_balances balance WHERE balance.restaurant_id = inventory_items.restaurant_id AND balance.inventory_item_id = inventory_items.id), 0) <= 0');
        } elseif (($filters['stock'] ?? null) === 'available') {
            $query->whereRaw('COALESCE((SELECT SUM(balance.quantity) FROM inventory_stock_balances balance WHERE balance.restaurant_id = inventory_items.restaurant_id AND balance.inventory_item_id = inventory_items.id), 0) > 0');
        } elseif (($filters['stock'] ?? null) === 'low') {
            $query->whereRaw('COALESCE((SELECT SUM(balance.quantity) FROM inventory_stock_balances balance WHERE balance.restaurant_id = inventory_items.restaurant_id AND balance.inventory_item_id = inventory_items.id), 0) <= inventory_items.reorder_level');
        }
        $rows = [];
        foreach ($query->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')->limit(10000)->get() as $item) {
            $quantity = BigDecimal::zero();
            $value = BigDecimal::zero();
            foreach ($item->balances as $balance) {
                $quantity = $quantity->plus((string) $balance->quantity);
                $value = $value->plus(BigDecimal::of((string) $balance->quantity)->multipliedBy((string) $balance->average_unit_cost));
            }
            $averageCost = $quantity->isGreaterThan(0)
                ? $value->dividedBy($quantity, 6, RoundingMode::HALF_UP)
                : BigDecimal::zero();
            $rows[] = [
                $item->item_code, $item->name, $item->category->name, $quantity->toScale(6)->__toString(),
                $item->baseUnit->abbreviation, $averageCost->toScale(6, RoundingMode::HALF_UP)->__toString(),
                $value->toScale(2, RoundingMode::HALF_UP)->__toString(),
                $item->reorder_level, $item->status,
            ];
        }
        $callback = static function () use ($rows): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Item Code', 'Item Name', 'Category', 'Current Stock', 'Unit', 'Average Cost', 'Stock Value', 'Reorder Level', 'Status']);
            foreach ($rows as $row) {
                foreach ([0, 1, 2, 4, 8] as $index) {
                    if (preg_match('/^\s*[=+\-@]/', (string) $row[$index])) {
                        $row[$index] = "'".$row[$index];
                    }
                }
                fputcsv($stream, $row);
            }
            fclose($stream);
        };

        return response()->streamDownload($callback, 'inventory-items.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function createItem(): View
    {
        $owner = $this->owner();

        return $this->itemForm($owner, new InventoryItem(['status' => 'active']));
    }

    public function showItem(int $item): View
    {
        $owner = $this->owner();
        $inventoryItem = InventoryItem::query()->where('restaurant_id', $owner->restaurant_id)
            ->with(['category', 'baseUnit', 'purchaseUnit', 'consumptionUnit', 'balances.location'])
            ->findOrFail($item);
        $quantity = BigDecimal::zero();
        $value = BigDecimal::zero();
        foreach ($inventoryItem->balances as $balance) {
            $balanceQuantity = BigDecimal::of((string) $balance->quantity);
            $quantity = $quantity->plus($balanceQuantity);
            $value = $value->plus($balanceQuantity->multipliedBy((string) $balance->average_unit_cost));
        }
        $inventoryItem->current_quantity = $quantity->toScale(6, RoundingMode::HALF_UP)->__toString();
        $inventoryItem->stock_value = $value->toScale(2, RoundingMode::HALF_UP)->__toString();
        $inventoryItem->average_unit_cost = $quantity->isGreaterThan(0)
            ? $value->dividedBy($quantity, 6, RoundingMode::HALF_UP)->toScale(6, RoundingMode::HALF_UP)->__toString()
            : '0.000000';

        return view('restaurant.inventory.items.show', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'item' => $inventoryItem,
            'movements' => $inventoryItem->movements()->where('restaurant_id', $owner->restaurant_id)
                ->with('location')->latest('occurred_at')->limit(12)->get(),
            'batches' => DB::table('inventory_item_batches as batch')
                ->join('inventory_locations as location', 'location.id', '=', 'batch.location_id')
                ->where('batch.restaurant_id', $owner->restaurant_id)
                ->where('batch.inventory_item_id', $inventoryItem->id)
                ->orderByRaw('batch.expires_at IS NULL')->orderBy('batch.expires_at')
                ->get(['batch.*', 'location.name as location_name']),
            'recipes' => DB::table('menu_recipe_ingredients as ingredient')
                ->join('menu_recipes as recipe', 'recipe.id', '=', 'ingredient.menu_recipe_id')
                ->join('restaurant_menu_items as menu_item', 'menu_item.id', '=', 'recipe.menu_item_id')
                ->where('ingredient.restaurant_id', $owner->restaurant_id)
                ->where('ingredient.inventory_item_id', $inventoryItem->id)
                ->orderBy('menu_item.name')->orderByDesc('recipe.version')
                ->get(['menu_item.name as menu_item_name', 'recipe.version', 'recipe.is_active', 'ingredient.base_quantity']),
        ]);
    }

    public function editItem(int $item): View
    {
        $owner = $this->owner();
        $model = InventoryItem::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($item);

        return $this->itemForm($owner, $model);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $this->validateItem($request, $owner);
        $item = DB::transaction(function () use ($owner, $data): InventoryItem {
            $model = InventoryItem::create($data + [
                'restaurant_id' => $owner->restaurant_id,
                'item_code' => 'TMP'.Str::upper(Str::random(15)),
            ]);
            $model->update(['item_code' => sprintf('INV%06d', $model->id)]);

            return $model;
        });
        $this->audit($owner, 'inventory_item_created', 'inventory_item', $item->id, ['item_code' => $item->item_code]);

        return redirect()->route('restaurant.inventory.items.index')->with('status', "Inventory item {$item->item_code} created.");
    }

    public function updateItem(Request $request, int $item): RedirectResponse
    {
        $owner = $this->owner();
        $model = InventoryItem::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($item);
        $model->update($this->validateItem($request, $owner, $model));
        $this->audit($owner, 'inventory_item_updated', 'inventory_item', $model->id, ['item_code' => $model->item_code]);

        return redirect()->route('restaurant.inventory.items.index')->with('status', 'Inventory item updated.');
    }

    public function archiveItem(int $item): RedirectResponse
    {
        $owner = $this->owner();
        $model = InventoryItem::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($item);
        if ($model->movements()->exists()) {
            $model->update(['status' => 'inactive']);
            $this->audit($owner, 'inventory_item_archived', 'inventory_item', $model->id, ['item_code' => $model->item_code]);
        } else {
            $model->update(['status' => 'inactive']);
        }

        return back()->with('status', 'Inventory item archived; its stock history has been retained.');
    }

    public function categories(): View
    {
        $owner = $this->owner();

        return view('restaurant.inventory.manage', [
            'user' => $owner, 'restaurant' => $owner->restaurant, 'kind' => 'categories',
            'title' => 'Item categories', 'records' => InventoryCategory::query()
                ->where('restaurant_id', $owner->restaurant_id)->withCount('items')->orderBy('name')->paginate(20),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('inventory_categories')->where('restaurant_id', $owner->restaurant_id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        $category = InventoryCategory::create($data + ['restaurant_id' => $owner->restaurant_id]);
        $this->audit($owner, 'inventory_category_created', 'inventory_category', $category->id, ['name' => $data['name']]);

        return back()->with('status', 'Category created.');
    }

    public function updateCategory(Request $request, int $category): RedirectResponse
    {
        $owner = $this->owner();
        $model = InventoryCategory::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($category);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('inventory_categories')->where('restaurant_id', $owner->restaurant_id)->ignore($model->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        $model->update($data);
        $this->audit($owner, 'inventory_category_updated', 'inventory_category', $model->id, ['name' => $model->name]);

        return back()->with('status', 'Category updated.');
    }

    public function deleteCategory(int $category): RedirectResponse
    {
        $owner = $this->owner();
        $model = InventoryCategory::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($category);
        abort_if($model->items()->exists(), 409, 'Categories with inventory items cannot be deleted.');
        $model->delete();
        $this->audit($owner, 'inventory_category_deleted', 'inventory_category', $model->id, ['name' => $model->name]);

        return back()->with('status', 'Category deleted.');
    }

    public function units(): View
    {
        $owner = $this->owner();
        $restaurantId = (int) $owner->restaurant_id;

        return view('restaurant.inventory.manage', [
            'user' => $owner, 'restaurant' => $owner->restaurant, 'kind' => 'units',
            'title' => 'Units and conversions', 'records' => InventoryUnit::query()
                ->where('restaurant_id', $restaurantId)->withCount('items')->orderBy('dimension')->orderBy('name')->paginate(30),
            'conversions' => DB::table('inventory_unit_conversions as conversion')
                ->join('inventory_units as from_unit', 'from_unit.id', '=', 'conversion.from_unit_id')
                ->join('inventory_units as to_unit', 'to_unit.id', '=', 'conversion.to_unit_id')
                ->where('conversion.restaurant_id', $restaurantId)
                ->get(['conversion.id', 'conversion.factor', 'from_unit.name as from_name', 'to_unit.name as to_name']),
        ]);
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'abbreviation' => ['required', 'string', 'max:16', Rule::unique('inventory_units')->where('restaurant_id', $owner->restaurant_id)],
            'dimension' => ['required', 'string', 'max:32', 'regex:/\A[a-z][a-z0-9_]*\z/'],
        ]);
        $unit = InventoryUnit::create($data + ['restaurant_id' => $owner->restaurant_id]);
        $this->audit($owner, 'inventory_unit_created', 'inventory_unit', $unit->id, ['abbreviation' => $unit->abbreviation]);

        return back()->with('status', 'Unit created.');
    }

    public function storeConversion(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'from_unit_id' => ['required', 'integer', Rule::exists('inventory_units', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'to_unit_id' => ['required', 'integer', 'different:from_unit_id', Rule::exists('inventory_units', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'factor' => ['required', 'numeric', 'gt:0', 'decimal:0,9', 'max:999999999'],
        ]);
        $from = InventoryUnit::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($data['from_unit_id']);
        $to = InventoryUnit::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($data['to_unit_id']);
        abort_unless($from->dimension === $to->dimension, 422, 'Unit conversions require matching dimensions.');
        DB::table('inventory_unit_conversions')->updateOrInsert([
            'restaurant_id' => $owner->restaurant_id,
            'from_unit_id' => $from->id,
            'to_unit_id' => $to->id,
        ], ['factor' => $data['factor'], 'updated_at' => now(), 'created_at' => now()]);
        $this->audit($owner, 'inventory_unit_conversion_saved', 'inventory_unit_conversion', (int) $from->id, [
            'from_unit_id' => $from->id, 'to_unit_id' => $to->id, 'factor' => $data['factor'],
        ]);

        return back()->with('status', 'Unit conversion saved.');
    }

    public function deleteUnit(int $unit): RedirectResponse
    {
        $owner = $this->owner();
        $model = InventoryUnit::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($unit);
        abort_if(DB::table('inventory_items')->where(function ($query) use ($unit): void {
            $query->where('base_unit_id', $unit)->orWhere('purchase_unit_id', $unit)->orWhere('consumption_unit_id', $unit);
        })->exists() || DB::table('menu_recipe_ingredients')->where('unit_id', $unit)->exists()
            || DB::table('inventory_unit_conversions')->where('from_unit_id', $unit)->orWhere('to_unit_id', $unit)->exists(),
            409, 'Units used by items, recipes or conversions cannot be deleted.');
        $model->delete();
        $this->audit($owner, 'inventory_unit_deleted', 'inventory_unit', $model->id, ['abbreviation' => $model->abbreviation]);

        return back()->with('status', 'Unit deleted.');
    }

    public function locations(): View
    {
        $owner = $this->owner();

        return view('restaurant.inventory.manage', [
            'user' => $owner, 'restaurant' => $owner->restaurant, 'kind' => 'locations',
            'title' => 'Stock locations', 'records' => InventoryLocation::query()
                ->where('restaurant_id', $owner->restaurant_id)->withCount('balances')->orderBy('name')->paginate(20),
        ]);
    }

    public function storeLocation(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('inventory_locations')->where('restaurant_id', $owner->restaurant_id)],
            'code' => ['required', 'string', 'max:24', 'alpha_dash', Rule::unique('inventory_locations')->where('restaurant_id', $owner->restaurant_id)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $location = InventoryLocation::create($data + ['restaurant_id' => $owner->restaurant_id]);
        $this->audit($owner, 'inventory_location_created', 'inventory_location', $location->id, ['code' => $location->code]);

        return back()->with('status', 'Stock location created.');
    }

    public function updateLocation(Request $request, int $location): RedirectResponse
    {
        $owner = $this->owner();
        $model = InventoryLocation::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($location);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('inventory_locations')->where('restaurant_id', $owner->restaurant_id)->ignore($model->id)],
            'code' => ['required', 'string', 'max:24', 'alpha_dash', Rule::unique('inventory_locations')->where('restaurant_id', $owner->restaurant_id)->ignore($model->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        $model->update($data);
        $this->audit($owner, 'inventory_location_updated', 'inventory_location', $model->id, ['code' => $model->code]);

        return back()->with('status', 'Stock location updated.');
    }

    public function deleteLocation(int $location): RedirectResponse
    {
        $owner = $this->owner();
        $model = InventoryLocation::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($location);
        $inUse = DB::table('inventory_stock_balances')->where('location_id', $model->id)->where('quantity', '!=', 0)->exists()
            || DB::table('inventory_movements')->where('location_id', $model->id)->exists();
        abort_if($inUse, 409, 'Locations with stock or transaction history cannot be deleted.');
        $model->delete();
        $this->audit($owner, 'inventory_location_deleted', 'inventory_location', $model->id, ['code' => $model->code]);

        return back()->with('status', 'Stock location deleted.');
    }

    public function movementForm(Request $request): View
    {
        $owner = $this->owner();
        $kind = $request->validate(['kind' => ['required', Rule::in(['opening', 'in', 'out', 'transfer', 'adjustment', 'wastage'])]])['kind'];

        return view('restaurant.inventory.stock.form', [
            'user' => $owner, 'restaurant' => $owner->restaurant, 'kind' => $kind,
            'items' => InventoryItem::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'active')->with('baseUnit')->orderBy('name')->get(),
            'locations' => InventoryLocation::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'active')->orderBy('name')->get(),
            'units' => InventoryUnit::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'active')->orderBy('name')->get(),
            'batches' => DB::table('inventory_item_batches')->where('restaurant_id', $owner->restaurant_id)->where('available_quantity', '>', 0)->get(),
            'vendors' => $owner->restaurant->vendors()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function openingStock(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $this->validateStockMovement($request, $owner, true);
        $item = $this->itemInRestaurant($owner, (int) $data['inventory_item_id']);
        $location = $this->locationInRestaurant($owner, (int) $data['location_id']);
        abort_if(DB::table('inventory_movements')->where('restaurant_id', $owner->restaurant_id)
            ->where('inventory_item_id', $item->id)->where('location_id', $location->id)
            ->where('movement_type', 'opening')->exists(), 409, 'Opening stock has already been initialized for this item and location.');
        DB::transaction(function () use ($owner, $data, $item, $location): void {
            DB::table('restaurants')->where('id', $owner->restaurant_id)->lockForUpdate()->firstOrFail();
            $key = 'opening:'.$owner->restaurant_id.':'.$item->id.':'.$location->id;
            if (InventoryMovement::query()->where('restaurant_id', $owner->restaurant_id)->where('idempotency_key', $key)->exists()) {
                abort(409, 'Opening stock has already been initialized for this item and location.');
            }
            $batch = $this->createReceiptBatch($owner, $item, $location, $data);
            $this->stock->receive($owner, $item, $location, $data['quantity'], $data['unit_cost'], 'opening', $key, [
                'unit_id' => (int) $data['unit_id'], 'source_type' => 'opening_stock',
                'reference' => $data['reference'] ?? null, 'occurred_at' => $data['occurred_at'], 'notes' => $data['notes'] ?? null,
                ...($batch ? ['batch_id' => $batch->id] : []),
            ]);
        });

        return redirect()->route('restaurant.inventory.items.index')->with('status', 'Opening stock recorded.');
    }

    public function stockIn(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $this->validateStockMovement($request, $owner, true);
        $item = $this->itemInRestaurant($owner, (int) $data['inventory_item_id']);
        $location = $this->locationInRestaurant($owner, (int) $data['location_id']);
        DB::transaction(function () use ($owner, $data, $item, $location): void {
            DB::table('restaurants')->where('id', $owner->restaurant_id)->lockForUpdate()->firstOrFail();
            if (InventoryMovement::query()->where('restaurant_id', $owner->restaurant_id)
                ->where('idempotency_key', $data['idempotency_key'])->exists()) {
                return;
            }
            $batch = $this->createReceiptBatch($owner, $item, $location, $data);
            $this->stock->receive($owner, $item, $location, $data['quantity'], $data['unit_cost'], 'manual_receipt', $data['idempotency_key'], [
                'unit_id' => (int) $data['unit_id'], 'source_type' => 'manual_receipt',
                'reference' => $data['reference'] ?? null, 'occurred_at' => $data['occurred_at'], 'notes' => $data['notes'] ?? null,
                ...($batch ? ['batch_id' => $batch->id] : []),
            ]);
        });

        return back()->with('status', 'Stock receipt recorded.');
    }

    public function stockOut(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $this->validateStockMovement($request, $owner, false);
        $item = $this->itemInRestaurant($owner, (int) $data['inventory_item_id']);
        $location = $this->locationInRestaurant($owner, (int) $data['location_id']);
        $this->stock->issue($owner, $item, $location, $data['quantity'], 'manual_issue', $data['idempotency_key'], [
            'unit_id' => (int) $data['unit_id'], 'batch_id' => $data['batch_id'] ?? null,
            'source_type' => 'manual_issue', 'reference' => $data['reference'] ?? null, 'reason' => $data['reason'],
            'occurred_at' => $data['occurred_at'], 'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('status', 'Stock issue recorded.');
    }

    public function transfer(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'inventory_item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'source_location_id' => ['required', 'integer', Rule::exists('inventory_locations', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'destination_location_id' => ['required', 'integer', 'different:source_location_id', Rule::exists('inventory_locations', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'occurred_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $item = $this->itemInRestaurant($owner, (int) $data['inventory_item_id']);
        $this->stock->transfer(
            $owner,
            $item,
            $this->locationInRestaurant($owner, (int) $data['source_location_id']),
            $this->locationInRestaurant($owner, (int) $data['destination_location_id']),
            $data['quantity'], $data['idempotency_key'], $data['occurred_at'], $data['notes'] ?? null,
        );

        return back()->with('status', 'Stock transfer completed.');
    }

    public function counts(): View
    {
        $owner = $this->owner();

        return view('restaurant.inventory.counts.index', [
            'user' => $owner, 'restaurant' => $owner->restaurant,
            'counts' => DB::table('inventory_stock_counts')->where('restaurant_id', $owner->restaurant_id)->latest('counted_at')->paginate(20),
            'locations' => InventoryLocation::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'active')->orderBy('name')->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function startCount(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'location_id' => ['required', 'integer', Rule::exists('inventory_locations', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'counted_at' => ['required', 'date'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $countId = DB::transaction(function () use ($owner, $data): int {
            DB::table('restaurants')->where('id', $owner->restaurant_id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('inventory_stock_counts')->where('restaurant_id', $owner->restaurant_id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return (int) $existing->id;
            }
            $id = DB::table('inventory_stock_counts')->insertGetId([
                'restaurant_id' => $owner->restaurant_id, 'location_id' => $data['location_id'],
                'created_by' => $owner->id, 'count_number' => 'TMP-'.Str::uuid(),
                'status' => 'draft', 'idempotency_key' => $data['idempotency_key'],
                'counted_at' => $data['counted_at'], 'notes' => $data['notes'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('inventory_stock_counts')->where('id', $id)->update(['count_number' => sprintf('CNT%06d', $id)]);
            $items = InventoryItem::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'active')->get(['id']);
            foreach ($items as $item) {
                $quantity = DB::table('inventory_stock_balances')
                    ->where('restaurant_id', $owner->restaurant_id)
                    ->where('inventory_item_id', $item->id)->where('location_id', $data['location_id'])->value('quantity') ?? '0';
                DB::table('inventory_stock_count_items')->insert([
                    'restaurant_id' => $owner->restaurant_id, 'stock_count_id' => $id,
                    'inventory_item_id' => $item->id, 'expected_quantity' => $quantity,
                    'actual_quantity' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $id;
        });

        return redirect()->route('restaurant.inventory.counts.show', $countId)->with('status', 'Stock count started. Enter the physical quantity for each item.');
    }

    public function showCount(int $count): View
    {
        $owner = $this->owner();
        $model = DB::table('inventory_stock_counts')->where('restaurant_id', $owner->restaurant_id)->find($count);
        abort_unless($model !== null, 404);
        $rows = DB::table('inventory_stock_count_items as count_item')
            ->join('inventory_items as item', 'item.id', '=', 'count_item.inventory_item_id')
            ->join('inventory_units as unit', 'unit.id', '=', 'item.base_unit_id')
            ->where('count_item.restaurant_id', $owner->restaurant_id)->where('count_item.stock_count_id', $count)
            ->orderBy('item.name')->get([
                'count_item.id', 'count_item.expected_quantity', 'count_item.actual_quantity',
                'count_item.batch_number', 'count_item.manufactured_at', 'count_item.expires_at',
                'item.name', 'item.item_code', 'item.track_batches', 'item.track_expiry', 'unit.abbreviation',
            ]);

        return view('restaurant.inventory.counts.show', [
            'user' => $owner, 'restaurant' => $owner->restaurant, 'count' => $model, 'rows' => $rows,
        ]);
    }

    public function updateCount(Request $request, int $count): RedirectResponse
    {
        $owner = $this->owner();
        $model = DB::table('inventory_stock_counts')->where('restaurant_id', $owner->restaurant_id)->find($count);
        abort_unless($model !== null, 404);
        abort_unless($model->status === 'draft', 409, 'Posted stock counts cannot be changed.');
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct', Rule::exists('inventory_stock_count_items', 'id')->where('restaurant_id', $owner->restaurant_id)->where('stock_count_id', $count)],
            'items.*.actual_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,6'],
            'items.*.batch_number' => ['nullable', 'string', 'max:80'],
            'items.*.manufactured_at' => ['nullable', 'date_format:Y-m-d'],
            'items.*.expires_at' => ['nullable', 'date_format:Y-m-d'],
        ]);
        foreach ($data['items'] as $index => $item) {
            if (! empty($item['manufactured_at']) && ! empty($item['expires_at'])
                && $item['expires_at'] < $item['manufactured_at']) {
                throw ValidationException::withMessages([
                    "items.{$index}.expires_at" => 'The expiry date must not be before the manufacturing date.',
                ]);
            }
        }
        DB::transaction(function () use ($owner, $count, $data): void {
            $model = DB::table('inventory_stock_counts')->where('restaurant_id', $owner->restaurant_id)
                ->where('id', $count)->lockForUpdate()->first();
            abort_unless($model !== null, 404);
            abort_unless($model->status === 'draft', 409, 'Posted stock counts cannot be changed.');
            foreach ($data['items'] as $item) {
                DB::table('inventory_stock_count_items')
                    ->where('restaurant_id', $owner->restaurant_id)->where('stock_count_id', $count)->where('id', $item['id'])
                    ->update([
                        'actual_quantity' => $item['actual_quantity'],
                        'batch_number' => $item['batch_number'] ?? null,
                        'manufactured_at' => $item['manufactured_at'] ?? null,
                        'expires_at' => $item['expires_at'] ?? null,
                        'updated_at' => now(),
                    ]);
            }
        });

        return back()->with('status', 'Physical quantities saved for review.');
    }

    public function postCount(int $count): RedirectResponse
    {
        $owner = $this->owner();
        DB::transaction(function () use ($owner, $count): void {
            $model = DB::table('inventory_stock_counts')->where('restaurant_id', $owner->restaurant_id)
                ->where('id', $count)->lockForUpdate()->first();
            abort_unless($model !== null, 404);
            abort_unless($model->status === 'draft', 409, 'This stock count has already been posted.');
            $items = DB::table('inventory_stock_count_items')->where('restaurant_id', $owner->restaurant_id)
                ->where('stock_count_id', $count)->lockForUpdate()->get();
            abort_if($items->isEmpty() || $items->contains(fn ($item) => $item->actual_quantity === null), 422, 'Enter a physical quantity for every item before posting.');
            $location = $this->locationInRestaurant($owner, (int) $model->location_id);
            foreach ($items as $item) {
                $balance = DB::table('inventory_stock_balances')->where('restaurant_id', $owner->restaurant_id)
                    ->where('inventory_item_id', $item->inventory_item_id)->where('location_id', $location->id)->value('quantity') ?? '0';
                if (BigDecimal::of((string) $balance)->compareTo((string) $item->actual_quantity) === 0) {
                    continue;
                }
                $this->stock->adjust(
                    $owner, $this->itemInRestaurant($owner, (int) $item->inventory_item_id), $location,
                    (string) $item->actual_quantity, 'physical_count', 'stock-count:'.$count.':item:'.$item->inventory_item_id,
                    $model->counted_at, $model->notes, 'CNTADJ', [
                        'batch_number' => $item->batch_number,
                        'manufactured_at' => $item->manufactured_at,
                        'expires_at' => $item->expires_at,
                    ],
                );
            }
            DB::table('inventory_stock_counts')->where('id', $count)->update([
                'status' => 'posted', 'posted_by' => $owner->id, 'posted_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($owner, 'inventory_stock_count_posted', 'inventory_stock_count', $count, ['count_number' => $model->count_number]);
        });

        return redirect()->route('restaurant.inventory.counts.index')->with('status', 'Stock count variances posted.');
    }

    public function wastage(): View
    {
        $owner = $this->owner();
        $records = DB::table('inventory_wastage_records as wastage')
            ->join('inventory_items as item', 'item.id', '=', 'wastage.inventory_item_id')
            ->join('inventory_locations as location', 'location.id', '=', 'wastage.location_id')
            ->where('wastage.restaurant_id', $owner->restaurant_id)->latest('wastage.wasted_at')->paginate(20);

        return view('restaurant.inventory.wastage', [
            'user' => $owner, 'restaurant' => $owner->restaurant, 'records' => $records,
            'items' => InventoryItem::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'active')->with('baseUnit')->orderBy('name')->get(),
            'locations' => InventoryLocation::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'active')->orderBy('name')->get(),
            'batches' => DB::table('inventory_item_batches')->where('restaurant_id', $owner->restaurant_id)->where('available_quantity', '>', 0)->get(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function storeWastage(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'inventory_item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'location_id' => ['required', 'integer', Rule::exists('inventory_locations', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
            'batch_id' => ['nullable', 'integer', Rule::exists('inventory_item_batches', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'reason' => ['required', Rule::in(['expired', 'spoiled', 'damaged', 'overcooked', 'preparation_waste', 'other'])],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'wasted_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($owner, $data): void {
            DB::table('restaurants')->where('id', $owner->restaurant_id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('inventory_wastage_records')->where('restaurant_id', $owner->restaurant_id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return;
            }
            $item = $this->itemInRestaurant($owner, (int) $data['inventory_item_id']);
            $location = $this->locationInRestaurant($owner, (int) $data['location_id']);
            if (! empty($data['batch_id']) && ! DB::table('inventory_item_batches')
                ->where('restaurant_id', $owner->restaurant_id)->where('inventory_item_id', $item->id)
                ->where('location_id', $location->id)->where('id', $data['batch_id'])->exists()) {
                throw ValidationException::withMessages([
                    'batch_id' => 'Choose a batch belonging to the selected item and location.',
                ]);
            }
            $recordId = DB::table('inventory_wastage_records')->insertGetId([
                'restaurant_id' => $owner->restaurant_id, 'inventory_item_id' => $item->id,
                'location_id' => $location->id, 'batch_id' => $data['batch_id'] ?? null,
                'recorded_by' => $owner->id, 'wastage_number' => 'TMP-'.Str::uuid(),
                'quantity' => $data['quantity'], 'cost' => 0, 'reason' => $data['reason'],
                'idempotency_key' => $data['idempotency_key'], 'wasted_at' => $data['wasted_at'],
                'notes' => $data['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $number = sprintf('WST%06d', $recordId);
            DB::table('inventory_wastage_records')->where('id', $recordId)->update(['wastage_number' => $number]);
            $movements = $this->stock->issue($owner, $item, $location, $data['quantity'], 'wastage', 'wastage:'.$data['idempotency_key'], [
                'batch_id' => $data['batch_id'] ?? null, 'source_type' => 'wastage',
                'source_id' => $recordId, 'reference' => $number, 'reason' => $data['reason'],
                'occurred_at' => $data['wasted_at'], 'notes' => $data['notes'] ?? null,
            ]);
            $value = BigDecimal::zero();
            foreach ($movements as $movement) {
                $value = $value->plus((string) $movement->value);
            }
            DB::table('inventory_wastage_records')->where('id', $recordId)->update([
                'movement_id' => $movements[0]->id, 'cost' => $value->abs()->toScale(6)->__toString(), 'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'Wastage posted and stock deducted.');
    }

    public function adjustStock(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'inventory_item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'location_id' => ['required', 'integer', Rule::exists('inventory_locations', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'actual_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,6'],
            'reason' => ['required', Rule::in(['physical_count', 'damage', 'data_correction', 'other'])],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'occurred_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'batch_number' => ['nullable', 'string', 'max:80'],
            'manufactured_at' => ['nullable', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $this->stock->adjust(
            $owner, $this->itemInRestaurant($owner, (int) $data['inventory_item_id']),
            $this->locationInRestaurant($owner, (int) $data['location_id']), $data['actual_quantity'],
            $data['reason'], $data['idempotency_key'], $data['occurred_at'], $data['notes'] ?? null,
            'ADJ', [
                'batch_number' => $data['batch_number'] ?? null,
                'manufactured_at' => $data['manufactured_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
            ],
        );

        return back()->with('status', 'Stock adjustment posted.');
    }

    public function ledger(Request $request): View
    {
        $owner = $this->owner();
        $filters = $request->validate([
            'item_id' => ['nullable', 'integer', Rule::exists('inventory_items', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'location_id' => ['nullable', 'integer', Rule::exists('inventory_locations', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'movement_type' => ['nullable', Rule::in(self::MOVEMENT_TYPES)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $movements = InventoryMovement::query()->with(['item.baseUnit', 'location'])
            ->where('restaurant_id', $owner->restaurant_id)
            ->when($filters['item_id'] ?? null, fn (Builder $query, int $id) => $query->where('inventory_item_id', $id))
            ->when($filters['location_id'] ?? null, fn (Builder $query, int $id) => $query->where('location_id', $id))
            ->when($filters['movement_type'] ?? null, fn (Builder $query, string $type) => $query->where('movement_type', $type))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_at', '<=', $date))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $nested) => $nested
                ->where('movement_number', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%")))
            ->latest('occurred_at')->paginate(30)->withQueryString();

        return view('restaurant.inventory.ledger', [
            'user' => $owner, 'restaurant' => $owner->restaurant, 'movements' => $movements,
            'items' => InventoryItem::query()->where('restaurant_id', $owner->restaurant_id)->orderBy('name')->get(),
            'locations' => InventoryLocation::query()->where('restaurant_id', $owner->restaurant_id)->orderBy('name')->get(),
            'movementTypes' => self::MOVEMENT_TYPES,
            'filters' => $filters,
        ]);
    }

    public function batches(): View
    {
        $owner = $this->owner();
        $batches = DB::table('inventory_item_batches as batch')
            ->join('inventory_items as item', 'item.id', '=', 'batch.inventory_item_id')
            ->join('inventory_locations as location', 'location.id', '=', 'batch.location_id')
            ->leftJoin('vendors as vendor', 'vendor.id', '=', 'batch.vendor_id')
            ->where('batch.restaurant_id', $owner->restaurant_id)
            ->orderByRaw('batch.expires_at IS NULL')->orderBy('batch.expires_at')->paginate(30);

        return view('restaurant.inventory.batches', [
            'user' => $owner, 'restaurant' => $owner->restaurant, 'batches' => $batches,
        ]);
    }

    public function vendorMappings(): View
    {
        $owner = $this->owner();
        $lines = DB::table('vendor_purchase_order_items as po_item')
            ->join('vendor_purchase_orders as po', 'po.id', '=', 'po_item.purchase_order_id')
            ->join('vendors as vendor', 'vendor.id', '=', 'po.vendor_id')
            ->leftJoin('inventory_vendor_item_mappings as mapping', function ($join) use ($owner): void {
                $join->on('mapping.purchase_order_item_id', '=', 'po_item.id')
                    ->where('mapping.restaurant_id', '=', $owner->restaurant_id);
            })
            ->leftJoin('inventory_items as inventory_item', 'inventory_item.id', '=', 'mapping.inventory_item_id')
            ->leftJoin('inventory_locations as location', 'location.id', '=', 'mapping.location_id')
            ->leftJoin('inventory_units as unit', 'unit.id', '=', 'mapping.unit_id')
            ->where('po.restaurant_id', $owner->restaurant_id)
            ->orderByDesc('po.order_date')->orderBy('po_item.id')
            ->limit(500)->get([
                'po_item.id as purchase_order_item_id', 'po_item.item_name as vendor_item_name',
                'po_item.unit as vendor_unit', 'po.po_number', 'vendor.name as vendor_name',
                'mapping.inventory_item_id', 'mapping.location_id', 'mapping.unit_id',
                'mapping.base_quantity_per_unit', 'inventory_item.name as inventory_item_name',
                'location.name as location_name', 'unit.abbreviation as inventory_unit',
            ]);

        return view('restaurant.inventory.vendor-mappings', [
            'user' => $owner, 'restaurant' => $owner->restaurant, 'lines' => $lines,
            'items' => InventoryItem::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'active')->orderBy('name')->get(),
            'locations' => InventoryLocation::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'active')->orderBy('name')->get(),
            'units' => InventoryUnit::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function storeVendorMapping(Request $request, int $purchaseOrderItem): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'inventory_item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'location_id' => ['required', 'integer', Rule::exists('inventory_locations', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'unit_id' => ['required', 'integer', Rule::exists('inventory_units', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'base_quantity_per_unit' => ['required', 'numeric', 'gt:0', 'decimal:0,9', 'max:999999999'],
        ]);
        $line = DB::table('vendor_purchase_order_items as po_item')
            ->join('vendor_purchase_orders as po', 'po.id', '=', 'po_item.purchase_order_id')
            ->where('po.restaurant_id', $owner->restaurant_id)->where('po_item.id', $purchaseOrderItem)
            ->first(['po_item.id', 'po_item.item_name']);
        abort_unless($line !== null, 404);
        $item = $this->itemInRestaurant($owner, (int) $data['inventory_item_id']);
        $unit = InventoryUnit::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($data['unit_id']);
        $baseUnit = InventoryUnit::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($item->base_unit_id);
        abort_unless($unit->dimension === $baseUnit->dimension, 422, 'Choose a mapping unit compatible with the inventory item base unit.');

        DB::table('inventory_vendor_item_mappings')->updateOrInsert([
            'restaurant_id' => $owner->restaurant_id,
            'purchase_order_item_id' => $purchaseOrderItem,
        ], [
            'inventory_item_id' => $item->id,
            'location_id' => $data['location_id'],
            'unit_id' => $unit->id,
            'base_quantity_per_unit' => $data['base_quantity_per_unit'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($owner, 'inventory_vendor_item_mapped', 'vendor_purchase_order_item', $purchaseOrderItem, [
            'inventory_item_id' => $item->id, 'base_quantity_per_unit' => $data['base_quantity_per_unit'],
        ]);

        return back()->with('status', 'Vendor purchase line mapped to inventory.');
    }

    public function settings(): View
    {
        $owner = $this->owner();
        $settings = DB::table('inventory_settings')->where('restaurant_id', $owner->restaurant_id)->first();
        $settings ??= (object) [
            'default_location_id' => null, 'expiry_warning_days' => 30, 'deduction_event' => 'sale_completed',
            'allow_negative_stock' => false, 'valuation_method' => 'weighted_average', 'consume_recipes' => true,
            'low_stock_alerts_enabled' => true, 'expiry_alerts_enabled' => true,
        ];

        return view('restaurant.inventory.settings', [
            'user' => $owner, 'restaurant' => $owner->restaurant, 'settings' => $settings,
            'locations' => InventoryLocation::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'default_location_id' => ['nullable', 'integer', Rule::exists('inventory_locations', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'expiry_warning_days' => ['required', 'integer', 'between:1,365'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'consume_recipes' => ['sometimes', 'boolean'],
            'low_stock_alerts_enabled' => ['sometimes', 'boolean'],
            'expiry_alerts_enabled' => ['sometimes', 'boolean'],
        ]);
        $existing = DB::table('inventory_settings')->where('restaurant_id', $owner->restaurant_id)->first();
        if ($existing && $existing->valuation_method !== 'weighted_average') {
            abort(409, 'The configured costing method cannot be changed after inventory movements exist.');
        }
        DB::table('inventory_settings')->updateOrInsert(
            ['restaurant_id' => $owner->restaurant_id],
            [
                'default_location_id' => $data['default_location_id'] ?? null,
                'expiry_warning_days' => $data['expiry_warning_days'],
                'deduction_event' => 'sale_completed',
                'allow_negative_stock' => (bool) ($data['allow_negative_stock'] ?? false),
                'valuation_method' => 'weighted_average',
                'consume_recipes' => (bool) ($data['consume_recipes'] ?? false),
                'low_stock_alerts_enabled' => (bool) ($data['low_stock_alerts_enabled'] ?? false),
                'expiry_alerts_enabled' => (bool) ($data['expiry_alerts_enabled'] ?? false),
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ],
        );
        $this->audit($owner, 'inventory_settings_updated', 'inventory_settings', (int) $owner->restaurant_id, [
            'default_location_id' => $data['default_location_id'] ?? null,
            'allow_negative_stock' => (bool) ($data['allow_negative_stock'] ?? false),
            'low_stock_alerts_enabled' => (bool) ($data['low_stock_alerts_enabled'] ?? false),
            'expiry_alerts_enabled' => (bool) ($data['expiry_alerts_enabled'] ?? false),
        ]);

        return back()->with('status', 'Inventory settings saved.');
    }

    public function recipes(): View
    {
        $owner = $this->owner();
        $recipes = MenuRecipe::query()->where('restaurant_id', $owner->restaurant_id)
            ->with(['menuItem', 'ingredients.item.baseUnit', 'ingredients.item.balances', 'ingredients.unit'])
            ->orderBy('menu_item_id')->orderByDesc('version')->paginate(20);
        $estimatedCosts = [];
        foreach ($recipes as $recipe) {
            $estimatedCosts[$recipe->id] = $this->recipeCost($recipe)
                ->dividedBy((string) $recipe->yield_quantity, 2, RoundingMode::HALF_UP)
                ->toScale(2, RoundingMode::HALF_UP)->__toString();
        }

        return view('restaurant.inventory.recipes.index', [
            'user' => $owner, 'restaurant' => $owner->restaurant, 'recipes' => $recipes,
            'estimatedCosts' => $estimatedCosts,
            'products' => RestaurantMenuItem::query()->where('restaurant_id', $owner->restaurant_id)->orderBy('name')->get(),
            'items' => InventoryItem::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'active')->with('baseUnit')->orderBy('name')->get(),
            'units' => InventoryUnit::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function storeRecipe(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'menu_item_id' => ['required', 'integer', Rule::exists('restaurant_menu_items', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'yield_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6', 'max:999999999'],
            'preparation_notes' => ['nullable', 'string', 'max:3000'],
            'ingredients' => ['required', 'array', 'min:1', 'max:100'],
            'ingredients.*.inventory_item_id' => ['required', 'integer', 'distinct', Rule::exists('inventory_items', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'ingredients.*.unit_id' => ['required', 'integer', Rule::exists('inventory_units', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'ingredients.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6', 'max:999999999'],
        ]);
        $recipe = DB::transaction(function () use ($owner, $data): MenuRecipe {
            RestaurantMenuItem::query()->where('restaurant_id', $owner->restaurant_id)
                ->lockForUpdate()->findOrFail($data['menu_item_id']);
            $version = (int) MenuRecipe::query()->where('restaurant_id', $owner->restaurant_id)
                ->where('menu_item_id', $data['menu_item_id'])->max('version') + 1;
            $recipe = MenuRecipe::create([
                'restaurant_id' => $owner->restaurant_id,
                'menu_item_id' => $data['menu_item_id'],
                'version' => $version,
                'yield_quantity' => $data['yield_quantity'],
                'preparation_notes' => $data['preparation_notes'] ?? null,
                'is_active' => false,
            ]);
            foreach ($data['ingredients'] as $ingredient) {
                $item = $this->itemInRestaurant($owner, (int) $ingredient['inventory_item_id']);
                $baseQuantity = $this->stock->convertQuantity(
                    (int) $owner->restaurant_id,
                    $ingredient['quantity'],
                    (int) $ingredient['unit_id'],
                    (int) $item->base_unit_id,
                );
                DB::table('menu_recipe_ingredients')->insert([
                    'restaurant_id' => $owner->restaurant_id, 'menu_recipe_id' => $recipe->id,
                    'inventory_item_id' => $item->id, 'unit_id' => $ingredient['unit_id'],
                    'quantity' => $ingredient['quantity'], 'base_quantity' => $baseQuantity,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $recipe;
        });
        $this->audit($owner, 'inventory_recipe_version_created', 'menu_recipe', $recipe->id, [
            'menu_item_id' => $recipe->menu_item_id, 'version' => $recipe->version,
        ]);

        return back()->with('status', "Recipe version {$recipe->version} saved. Activate it when ready.");
    }

    public function activateRecipe(int $recipe): RedirectResponse
    {
        $owner = $this->owner();
        $model = MenuRecipe::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($recipe);
        DB::transaction(function () use ($owner, $model): void {
            RestaurantMenuItem::query()->where('restaurant_id', $owner->restaurant_id)
                ->lockForUpdate()->findOrFail($model->menu_item_id);
            MenuRecipe::query()->where('restaurant_id', $owner->restaurant_id)
                ->where('menu_item_id', $model->menu_item_id)->update(['is_active' => false]);
            $model->update(['is_active' => true]);
        });
        $this->audit($owner, 'inventory_recipe_activated', 'menu_recipe', $model->id, [
            'menu_item_id' => $model->menu_item_id, 'version' => $model->version,
        ]);

        return back()->with('status', 'Recipe activated.');
    }

    public function reports(Request $request): View
    {
        $owner = $this->owner();
        $filters = $request->validate([
            'report' => ['nullable', Rule::in(['stock', 'movement', 'valuation', 'low', 'expiry', 'wastage', 'consumption', 'receipts', 'adjustments', 'transfers', 'counts', 'stock_in', 'stock_out', 'ingredient_costs'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'item_id' => ['nullable', 'integer', Rule::exists('inventory_items', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'category_id' => ['nullable', 'integer', Rule::exists('inventory_categories', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'location_id' => ['nullable', 'integer', Rule::exists('inventory_locations', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'movement_type' => ['nullable', Rule::in(self::MOVEMENT_TYPES)],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('restaurant_id', $owner->restaurant_id)],
        ]);
        $report = $filters['report'] ?? 'stock';
        $items = InventoryItem::query()->where('restaurant_id', $owner->restaurant_id)
            ->with(['category', 'baseUnit', 'balances' => fn ($query) => $query
                ->when($filters['location_id'] ?? null, fn ($balanceQuery, int $id) => $balanceQuery->where('location_id', $id))
                ->with('location')])
            ->when($filters['item_id'] ?? null, fn (Builder $query, int $id) => $query->whereKey($id))
            ->when($filters['category_id'] ?? null, fn (Builder $query, int $id) => $query->where('category_id', $id))
            ->orderBy('name')->get();
        $movements = InventoryMovement::query()->where('restaurant_id', $owner->restaurant_id)->with(['item', 'location'])
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_at', '<=', $date))
            ->when($filters['item_id'] ?? null, fn (Builder $query, int $id) => $query->where('inventory_item_id', $id))
            ->when($filters['category_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas('item', fn (Builder $itemQuery) => $itemQuery->where('category_id', $id)))
            ->when($filters['location_id'] ?? null, fn (Builder $query, int $id) => $query->where('location_id', $id))
            ->when($filters['movement_type'] ?? null, fn (Builder $query, string $type) => $query->where('movement_type', $type))
            ->when($filters['vendor_id'] ?? null, fn (Builder $query, int $vendorId) => $query
                ->where('source_type', 'vendor_grn')
                ->whereIn('source_id', DB::table('vendor_goods_receipts')->select('id')
                    ->where('restaurant_id', $owner->restaurant_id)->where('vendor_id', $vendorId)))
            ->latest('occurred_at')->get();
        if ($report === 'low') {
            $items = $items->filter(function ($item): bool {
                $quantity = BigDecimal::zero();
                foreach ($item->balances as $balance) {
                    $quantity = $quantity->plus((string) $balance->quantity);
                }

                return $quantity->isLessThanOrEqualTo((string) $item->reorder_level);
            })->values();
        } elseif ($report === 'expiry') {
            $items = DB::table('inventory_item_batches as batch')->join('inventory_items as item', 'item.id', '=', 'batch.inventory_item_id')
                ->join('inventory_locations as location', 'location.id', '=', 'batch.location_id')
                ->where('batch.restaurant_id', $owner->restaurant_id)->where('batch.available_quantity', '>', 0)
                ->whereNotNull('batch.expires_at')
                ->when($filters['item_id'] ?? null, fn ($query, int $id) => $query->where('batch.inventory_item_id', $id))
                ->when($filters['category_id'] ?? null, fn ($query, int $id) => $query->where('item.category_id', $id))
                ->when($filters['location_id'] ?? null, fn ($query, int $id) => $query->where('batch.location_id', $id))
                ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('batch.expires_at', '>=', $date))
                ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('batch.expires_at', '<=', $date))
                ->orderBy('batch.expires_at')->get([
                    'batch.*', 'item.name', 'item.category_id', 'location.name as location_name',
                ]);
        } elseif ($report === 'wastage') {
            $movements = $movements->where('movement_type', 'wastage')->values();
        } elseif ($report === 'consumption') {
            $movements = $movements->whereIn('movement_type', ['consumption', 'manual_issue'])->values();
        } elseif ($report === 'receipts') {
            $movements = $movements->where('source_type', 'vendor_grn')->values();
        } elseif ($report === 'adjustments') {
            $movements = $movements->whereIn('movement_type', ['adjustment_in', 'adjustment_out'])->values();
        } elseif ($report === 'transfers') {
            $movements = $movements->whereIn('movement_type', ['transfer_in', 'transfer_out'])->values();
        } elseif ($report === 'stock_in') {
            $movements = $movements->filter(fn ($movement): bool => BigDecimal::of((string) $movement->quantity_in)->isGreaterThan(0))->values();
        } elseif ($report === 'stock_out') {
            $movements = $movements->filter(fn ($movement): bool => BigDecimal::of((string) $movement->quantity_out)->isGreaterThan(0))->values();
        }
        $recipeCostRows = collect();
        if ($report === 'ingredient_costs') {
            $recipes = MenuRecipe::query()->where('restaurant_id', $owner->restaurant_id)->where('is_active', true)
                ->with(['menuItem', 'ingredients.item.baseUnit', 'ingredients.item.balances', 'ingredients.unit'])
                ->when($filters['item_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas(
                    'ingredients', fn (Builder $ingredientQuery) => $ingredientQuery->where('inventory_item_id', $id),
                ))
                ->when($filters['category_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas(
                    'ingredients.item', fn (Builder $itemQuery) => $itemQuery->where('category_id', $id),
                ))
                ->when($filters['location_id'] ?? null, fn (Builder $query, int $id) => $query->with([
                    'ingredients.item.balances' => fn (Builder $balanceQuery) => $balanceQuery->where('location_id', $id),
                ]))
                ->orderBy('menu_item_id')->orderByDesc('version')->get();
            $recipeCostRows = $recipes->map(fn (MenuRecipe $recipe): array => [
                'recipe' => $recipe,
                'ingredients' => $recipe->ingredients->map(fn ($ingredient): string => $ingredient->item->name
                    .' ('.$ingredient->base_quantity.' '.$ingredient->item->baseUnit->abbreviation.')')->implode(', '),
                'cost_per_yield' => $this->recipeCost($recipe, $filters['location_id'] ?? null)
                    ->toScale(2, RoundingMode::HALF_UP)->__toString(),
                'cost_per_serving' => $this->recipeCost($recipe, $filters['location_id'] ?? null)
                    ->dividedBy((string) $recipe->yield_quantity, 2, RoundingMode::HALF_UP)
                    ->toScale(2, RoundingMode::HALF_UP)->__toString(),
            ]);
        }
        $countVariances = collect();
        if ($report === 'counts') {
            $countVariances = DB::table('inventory_stock_count_items as count_item')
                ->join('inventory_stock_counts as count', 'count.id', '=', 'count_item.stock_count_id')
                ->join('inventory_items as item', 'item.id', '=', 'count_item.inventory_item_id')
                ->join('inventory_units as unit', 'unit.id', '=', 'item.base_unit_id')
                ->where('count_item.restaurant_id', $owner->restaurant_id)
                ->where('count.status', 'posted')
                ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('count.counted_at', '>=', $date))
                ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('count.counted_at', '<=', $date))
                ->when($filters['item_id'] ?? null, fn ($query, int $id) => $query->where('count_item.inventory_item_id', $id))
                ->when($filters['category_id'] ?? null, fn ($query, int $id) => $query->where('item.category_id', $id))
                ->when($filters['location_id'] ?? null, fn ($query, int $id) => $query->where('count.location_id', $id))
                ->orderByDesc('count.counted_at')->get([
                    'count.count_number', 'count.counted_at', 'count.location_id',
                    'count_item.expected_quantity', 'count_item.actual_quantity',
                    'item.name as item_name', 'unit.abbreviation',
                ]);
            $countVariances = $countVariances->filter(fn ($row): bool => BigDecimal::of((string) $row->actual_quantity)
                ->compareTo((string) $row->expected_quantity) !== 0)->values();
        }

        return view('restaurant.inventory.reports', [
            'user' => $owner, 'restaurant' => $owner->restaurant, 'report' => $report, 'filters' => $filters,
            'items' => $items, 'movements' => $movements, 'countVariances' => $countVariances,
            'recipeCostRows' => $recipeCostRows,
            'filtersItems' => InventoryItem::query()->where('restaurant_id', $owner->restaurant_id)->orderBy('name')->get(),
            'categories' => InventoryCategory::query()->where('restaurant_id', $owner->restaurant_id)->orderBy('name')->get(),
            'locations' => InventoryLocation::query()->where('restaurant_id', $owner->restaurant_id)->orderBy('name')->get(),
            'vendors' => $owner->restaurant->vendors()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $owner = $this->owner();
        $filters = $request->validate([
            'report' => ['nullable', Rule::in(['stock', 'movement', 'valuation', 'low', 'expiry', 'wastage', 'consumption', 'receipts', 'adjustments', 'transfers', 'counts', 'stock_in', 'stock_out', 'ingredient_costs'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'item_id' => ['nullable', 'integer', Rule::exists('inventory_items', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'category_id' => ['nullable', 'integer', Rule::exists('inventory_categories', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'location_id' => ['nullable', 'integer', Rule::exists('inventory_locations', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'movement_type' => ['nullable', Rule::in(self::MOVEMENT_TYPES)],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('restaurant_id', $owner->restaurant_id)],
        ]);
        $report = $filters['report'] ?? 'stock';
        $headers = [];
        $rows = [];
        if (in_array($report, ['stock', 'valuation', 'low'], true)) {
            $items = InventoryItem::query()->where('restaurant_id', $owner->restaurant_id)
                ->with(['category', 'baseUnit', 'balances' => fn ($query) => $query
                    ->when($filters['location_id'] ?? null, fn ($balanceQuery, int $id) => $balanceQuery->where('location_id', $id))
                    ->with('location')])
                ->when($filters['item_id'] ?? null, fn (Builder $query, int $id) => $query->whereKey($id))
                ->when($filters['category_id'] ?? null, fn (Builder $query, int $id) => $query->where('category_id', $id))
                ->orderBy('name')->get();
            $headers = ['Item', 'Category', 'Location', 'Quantity', 'Unit', 'Average cost', 'Stock value', 'Reorder level'];
            foreach ($items as $item) {
                $quantity = BigDecimal::zero();
                foreach ($item->balances as $balance) {
                    $quantity = $quantity->plus((string) $balance->quantity);
                }
                if ($report === 'low' && $quantity->isGreaterThan((string) $item->reorder_level)) {
                    continue;
                }
                foreach ($item->balances as $balance) {
                    $value = BigDecimal::of((string) $balance->quantity)
                        ->multipliedBy((string) $balance->average_unit_cost)->toScale(2, RoundingMode::HALF_UP);
                    $rows[] = [
                        $item->name, $item->category->name, $balance->location->name,
                        $balance->quantity, $item->baseUnit->abbreviation, $balance->average_unit_cost,
                        $value->__toString(), $item->reorder_level,
                    ];
                }
            }
        } elseif ($report === 'ingredient_costs') {
            $recipes = MenuRecipe::query()->where('restaurant_id', $owner->restaurant_id)->where('is_active', true)
                ->with(['menuItem', 'ingredients.item.baseUnit', 'ingredients.item.balances', 'ingredients.unit'])
                ->when($filters['item_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas(
                    'ingredients', fn (Builder $ingredientQuery) => $ingredientQuery->where('inventory_item_id', $id),
                ))
                ->when($filters['category_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas(
                    'ingredients.item', fn (Builder $itemQuery) => $itemQuery->where('category_id', $id),
                ))
                ->when($filters['location_id'] ?? null, fn (Builder $query, int $id) => $query->with([
                    'ingredients.item.balances' => fn (Builder $balanceQuery) => $balanceQuery->where('location_id', $id),
                ]))
                ->orderBy('menu_item_id')->orderByDesc('version')->limit(10000)->get();
            $headers = ['Menu item', 'Recipe version', 'Ingredients', 'Recipe yield', 'Ingredient cost per yield', 'Ingredient cost per serving'];
            foreach ($recipes as $recipe) {
                $ingredients = $recipe->ingredients->map(fn ($ingredient): string => $ingredient->item->name
                    .' ('.$ingredient->base_quantity.' '.$ingredient->item->baseUnit->abbreviation.')')->implode(', ');
                $cost = $this->recipeCost($recipe, $filters['location_id'] ?? null);
                $rows[] = [
                    $recipe->menuItem->name, $recipe->version, $ingredients, $recipe->yield_quantity,
                    $cost->toScale(2, RoundingMode::HALF_UP)->__toString(),
                    $cost->dividedBy((string) $recipe->yield_quantity, 2, RoundingMode::HALF_UP)
                        ->toScale(2, RoundingMode::HALF_UP)->__toString(),
                ];
            }
        } elseif ($report === 'expiry') {
            $headers = ['Item', 'Batch', 'Location', 'Expires', 'Available quantity'];
            $batches = DB::table('inventory_item_batches as batch')->join('inventory_items as item', 'item.id', '=', 'batch.inventory_item_id')
                ->join('inventory_locations as location', 'location.id', '=', 'batch.location_id')
                ->where('batch.restaurant_id', $owner->restaurant_id)->where('batch.available_quantity', '>', 0)
                ->whereNotNull('batch.expires_at')
                ->when($filters['item_id'] ?? null, fn ($query, int $id) => $query->where('batch.inventory_item_id', $id))
                ->when($filters['category_id'] ?? null, fn ($query, int $id) => $query->where('item.category_id', $id))
                ->when($filters['location_id'] ?? null, fn ($query, int $id) => $query->where('batch.location_id', $id))
                ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('batch.expires_at', '>=', $date))
                ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('batch.expires_at', '<=', $date))
                ->orderBy('batch.expires_at')->limit(10000)->get([
                    'item.name', 'batch.batch_number', 'location.name as location_name',
                    'batch.expires_at', 'batch.available_quantity',
                ]);
            foreach ($batches as $batch) {
                $rows[] = [$batch->name, $batch->batch_number, $batch->location_name, $batch->expires_at, $batch->available_quantity];
            }
        } elseif ($report === 'counts') {
            $headers = ['Count', 'Date', 'Item', 'Location ID', 'Expected', 'Counted', 'Variance'];
            $counts = DB::table('inventory_stock_count_items as count_item')
                ->join('inventory_stock_counts as count', 'count.id', '=', 'count_item.stock_count_id')
                ->join('inventory_items as item', 'item.id', '=', 'count_item.inventory_item_id')
                ->where('count_item.restaurant_id', $owner->restaurant_id)->where('count.status', 'posted')
                ->when($filters['date_from'] ?? null, fn ($query, string $date) => $query->whereDate('count.counted_at', '>=', $date))
                ->when($filters['date_to'] ?? null, fn ($query, string $date) => $query->whereDate('count.counted_at', '<=', $date))
                ->when($filters['item_id'] ?? null, fn ($query, int $id) => $query->where('count_item.inventory_item_id', $id))
                ->when($filters['category_id'] ?? null, fn ($query, int $id) => $query->where('item.category_id', $id))
                ->when($filters['location_id'] ?? null, fn ($query, int $id) => $query->where('count.location_id', $id))
                ->orderByDesc('count.counted_at')->limit(10000)->get([
                    'count.count_number', 'count.counted_at', 'count.location_id',
                    'count_item.expected_quantity', 'count_item.actual_quantity', 'item.name',
                ]);
            foreach ($counts as $count) {
                if (BigDecimal::of((string) $count->actual_quantity)->compareTo((string) $count->expected_quantity) !== 0) {
                    $variance = BigDecimal::of((string) $count->actual_quantity)->minus((string) $count->expected_quantity);
                    $rows[] = [$count->count_number, $count->counted_at, $count->name, $count->location_id,
                        $count->expected_quantity, $count->actual_quantity, $variance->__toString()];
                }
            }
        } else {
            $movements = InventoryMovement::query()->with(['item', 'location'])->where('restaurant_id', $owner->restaurant_id)
                ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_at', '>=', $date))
                ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('occurred_at', '<=', $date))
                ->when($filters['item_id'] ?? null, fn (Builder $query, int $id) => $query->where('inventory_item_id', $id))
                ->when($filters['category_id'] ?? null, fn (Builder $query, int $id) => $query->whereHas('item', fn (Builder $itemQuery) => $itemQuery->where('category_id', $id)))
                ->when($filters['location_id'] ?? null, fn (Builder $query, int $id) => $query->where('location_id', $id))
                ->when($filters['movement_type'] ?? null, fn (Builder $query, string $type) => $query->where('movement_type', $type))
                ->when($filters['vendor_id'] ?? null, fn (Builder $query, int $vendorId) => $query
                    ->where('source_type', 'vendor_grn')
                    ->whereIn('source_id', DB::table('vendor_goods_receipts')->select('id')
                        ->where('restaurant_id', $owner->restaurant_id)->where('vendor_id', $vendorId)))
                ->when($report === 'wastage', fn (Builder $query) => $query->where('movement_type', 'wastage'))
                ->when($report === 'consumption', fn (Builder $query) => $query->whereIn('movement_type', ['consumption', 'manual_issue']))
                ->when($report === 'receipts', fn (Builder $query) => $query->where('source_type', 'vendor_grn'))
                ->when($report === 'adjustments', fn (Builder $query) => $query->whereIn('movement_type', ['adjustment_in', 'adjustment_out']))
                ->when($report === 'transfers', fn (Builder $query) => $query->whereIn('movement_type', ['transfer_in', 'transfer_out']))
                ->when($report === 'stock_in', fn (Builder $query) => $query->where('quantity_in', '>', 0))
                ->when($report === 'stock_out', fn (Builder $query) => $query->where('quantity_out', '>', 0))
                ->latest('occurred_at')->limit(10000)->get();
            $headers = ['Date', 'Reference', 'Item', 'Location', 'Movement', 'Quantity In', 'Quantity Out', 'Unit Cost', 'Value'];
            foreach ($movements as $movement) {
                $rows[] = [$movement->occurred_at->toDateTimeString(), $movement->reference ?: $movement->movement_number,
                    $movement->item->name, $movement->location->name, $movement->movement_type,
                    $movement->quantity_in, $movement->quantity_out, $movement->unit_cost, $movement->value];
            }
        }
        $callback = static function () use ($headers, $rows): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $headers);
            foreach ($rows as $row) {
                fputcsv($stream, array_map(static function ($value): string {
                    $value = (string) $value;

                    return ! is_numeric($value) && preg_match('/^\s*[=+\-@]/', $value) ? "'".$value : $value;
                }, $row));
            }
            fclose($stream);
        };

        return response()->streamDownload($callback, 'inventory-'.$report.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function itemForm(User $owner, InventoryItem $item): View
    {
        $restaurantId = (int) $owner->restaurant_id;

        return view('restaurant.inventory.items.form', [
            'user' => $owner, 'restaurant' => $owner->restaurant, 'item' => $item,
            'categories' => InventoryCategory::query()->where('restaurant_id', $restaurantId)->where('status', 'active')->orderBy('name')->get(),
            'units' => InventoryUnit::query()->where('restaurant_id', $restaurantId)->where('status', 'active')->orderBy('name')->get(),
            'locations' => InventoryLocation::query()->where('restaurant_id', $restaurantId)->where('status', 'active')->orderBy('name')->get(),
            'vendors' => $owner->restaurant->vendors()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function recipeCost(MenuRecipe $recipe, ?int $locationId = null): BigDecimal
    {
        $cost = BigDecimal::zero();
        foreach ($recipe->ingredients as $ingredient) {
            $quantity = BigDecimal::zero();
            $value = BigDecimal::zero();
            foreach ($ingredient->item->balances as $balance) {
                if ($locationId !== null && (int) $balance->location_id !== $locationId) {
                    continue;
                }
                $balanceQuantity = BigDecimal::of((string) $balance->quantity);
                $quantity = $quantity->plus($balanceQuantity);
                $value = $value->plus($balanceQuantity->multipliedBy((string) $balance->average_unit_cost));
            }
            $averageCost = $quantity->isGreaterThan(0)
                ? $value->dividedBy($quantity, 6, RoundingMode::HALF_UP)
                : BigDecimal::of((string) $ingredient->item->standard_cost);
            $cost = $cost->plus(BigDecimal::of((string) $ingredient->base_quantity)->multipliedBy($averageCost));
        }

        return $cost;
    }

    private function validateItem(Request $request, User $owner, ?InventoryItem $item = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'category_id' => ['required', 'integer', Rule::exists('inventory_categories', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'base_unit_id' => ['required', 'integer', Rule::exists('inventory_units', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'purchase_unit_id' => ['nullable', 'integer', Rule::exists('inventory_units', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'consumption_unit_id' => ['nullable', 'integer', Rule::exists('inventory_units', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'preferred_vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'default_location_id' => ['nullable', 'integer', Rule::exists('inventory_locations', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('inventory_items')->where('restaurant_id', $owner->restaurant_id)->ignore($item?->id)],
            'description' => ['nullable', 'string', 'max:4000'],
            'reorder_level' => ['required', 'numeric', 'min:0', 'decimal:0,6'],
            'reorder_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,6'],
            'standard_cost' => ['required', 'numeric', 'min:0', 'decimal:0,6'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'track_expiry' => ['sometimes', 'boolean'],
            'track_batches' => ['sometimes', 'boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]) + [
            'track_expiry' => $request->boolean('track_expiry'),
            'track_batches' => $request->boolean('track_batches'),
        ];
        $baseUnit = InventoryUnit::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($data['base_unit_id']);
        if ($item && (int) $item->base_unit_id !== (int) $baseUnit->id
            && ($item->movements()->exists()
                || DB::table('menu_recipe_ingredients')->where('restaurant_id', $owner->restaurant_id)
                    ->where('inventory_item_id', $item->id)->exists())) {
            throw ValidationException::withMessages([
                'base_unit_id' => 'The base unit cannot change after the item has stock history or recipe usage.',
            ]);
        }
        if ($item
            && ((bool) $item->track_batches !== (bool) $data['track_batches']
                || (bool) $item->track_expiry !== (bool) $data['track_expiry'])
            && (DB::table('inventory_stock_balances')->where('restaurant_id', $owner->restaurant_id)
                ->where('inventory_item_id', $item->id)->where('quantity', '!=', 0)->exists()
                || DB::table('inventory_item_batches')->where('restaurant_id', $owner->restaurant_id)
                    ->where('inventory_item_id', $item->id)->where('available_quantity', '>', 0)->exists())) {
            throw ValidationException::withMessages([
                'track_batches' => 'Batch and expiry tracking cannot change while the item has stock on hand.',
            ]);
        }
        foreach (['purchase_unit_id', 'consumption_unit_id'] as $unitField) {
            if (empty($data[$unitField])) {
                continue;
            }
            $unit = InventoryUnit::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($data[$unitField]);
            if ($unit->dimension !== $baseUnit->dimension) {
                throw ValidationException::withMessages([
                    $unitField => 'The selected unit has an incompatible dimension.',
                ]);
            }
            $this->stock->convertQuantity((int) $owner->restaurant_id, '1', $unit->id, $baseUnit->id);
        }

        return $data;
    }

    private function validateStockMovement(Request $request, User $owner, bool $incoming): array
    {
        return $request->validate([
            'inventory_item_id' => ['required', 'integer', Rule::exists('inventory_items', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'location_id' => ['required', 'integer', Rule::exists('inventory_locations', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'unit_id' => ['required', 'integer', Rule::exists('inventory_units', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,6', 'max:999999999'],
            ...($incoming ? ['unit_cost' => ['required', 'numeric', 'min:0', 'decimal:0,6', 'max:999999999']] : []),
            'reason' => [$incoming ? 'nullable' : 'required', Rule::in(['kitchen_consumption', 'internal_use', 'damage', 'return_to_vendor', 'other'])],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'occurred_at' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'batch_number' => ['nullable', 'string', 'max:80'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
            'manufactured_at' => ['nullable', 'date_format:Y-m-d'],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'batch_id' => ['nullable', 'integer', Rule::exists('inventory_item_batches', 'id')->where('restaurant_id', $owner->restaurant_id)],
        ]);
    }

    private function createReceiptBatch(User $owner, InventoryItem $item, InventoryLocation $location, array $data): ?object
    {
        if (! $item->track_batches && ! $item->track_expiry) {
            return null;
        }
        if (blank($data['batch_number'] ?? null)) {
            abort(422, 'A batch number is required for items with batch or expiry tracking enabled.');
        }
        if ($item->track_expiry && blank($data['expires_at'] ?? null)) {
            throw ValidationException::withMessages([
                'expires_at' => 'An expiry date is required for this item.',
            ]);
        }
        if (! empty($data['manufactured_at']) && ! empty($data['expires_at'])
            && $data['expires_at'] < $data['manufactured_at']) {
            throw ValidationException::withMessages([
                'expires_at' => 'The expiry date must not be before the manufacturing date.',
            ]);
        }
        $quantity = $this->stock->convertQuantity((int) $owner->restaurant_id, $data['quantity'], (int) $data['unit_id'], (int) $item->base_unit_id);
        $baseUnitsPerInputUnit = BigDecimal::of($this->stock->convertQuantity(
            (int) $owner->restaurant_id, '1', (int) $data['unit_id'], (int) $item->base_unit_id,
        ));
        $unitCost = BigDecimal::of((string) $data['unit_cost'])
            ->dividedBy($baseUnitsPerInputUnit, 6, RoundingMode::HALF_UP)->toScale(6)->__toString();
        $batch = DB::table('inventory_item_batches')
            ->where('restaurant_id', $owner->restaurant_id)->where('inventory_item_id', $item->id)
            ->where('location_id', $location->id)->where('batch_number', $data['batch_number'])
            ->lockForUpdate()->first();
        if ($batch) {
            abort_unless($batch->expires_at === ($data['expires_at'] ?? null)
                && $batch->manufactured_at === ($data['manufactured_at'] ?? null), 422,
                'An existing batch number cannot be reused with different dates.');
            $batchId = (int) $batch->id;
            $previousQuantity = BigDecimal::of((string) $batch->received_quantity);
            $receivedQuantity = $previousQuantity->plus($quantity)->toScale(6);
            $receivedCost = $receivedQuantity->isGreaterThan(0)
                ? $previousQuantity->multipliedBy((string) $batch->unit_cost)
                    ->plus(BigDecimal::of($quantity)->multipliedBy($unitCost))
                    ->dividedBy($receivedQuantity, 6, RoundingMode::HALF_UP)
                : BigDecimal::of($unitCost);
            DB::table('inventory_item_batches')->where('id', $batchId)->update([
                'received_quantity' => $receivedQuantity->__toString(),
                'unit_cost' => $receivedCost->toScale(6, RoundingMode::HALF_UP)->__toString(),
                'updated_at' => now(),
            ]);
        } else {
            $batchId = DB::table('inventory_item_batches')->insertGetId([
                'restaurant_id' => $owner->restaurant_id, 'inventory_item_id' => $item->id,
                'location_id' => $location->id, 'vendor_id' => $data['vendor_id'] ?? null,
                'batch_number' => $data['batch_number'],
                'manufactured_at' => $data['manufactured_at'] ?? null, 'expires_at' => $data['expires_at'] ?? null,
                'received_quantity' => $quantity, 'available_quantity' => '0.000000',
                'unit_cost' => $unitCost, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return (object) ['id' => $batchId];
    }

    private function itemInRestaurant(User $owner, int $itemId): InventoryItem
    {
        return InventoryItem::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($itemId);
    }

    private function locationInRestaurant(User $owner, int $locationId): InventoryLocation
    {
        return InventoryLocation::query()->where('restaurant_id', $owner->restaurant_id)->findOrFail($locationId);
    }

    private function audit(User $owner, string $action, string $targetType, int $targetId, array $details): void
    {
        $admin = request()->attributes->get('restaurant_admin_access_admin');
        $superAdmin = $admin instanceof SuperAdmin ? $admin : null;

        RestaurantAuditLog::create([
            'actor_id' => $superAdmin?->id,
            'actor_type' => $superAdmin ? 'super_admin' : 'restaurant_owner',
            'restaurant_id' => (int) $owner->restaurant_id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $superAdmin ? $details + ['admin_id' => $superAdmin->id] : $details + ['owner_id' => $owner->id],
        ]);
    }

    private function owner(): User
    {
        return app(RestaurantAccessContext::class)->owner();
    }
}
