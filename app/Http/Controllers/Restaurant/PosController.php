<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\MenuRecipe;
use App\Models\RestaurantAuditLog;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantPosOrder;
use App\Models\RestaurantPosOrderItem;
use App\Models\RestaurantPosOrderItemIngredient;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Services\RestaurantAccessContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PosController extends Controller
{
    public function __construct(private readonly InventoryStockService $stock) {}

    public function index(Request $request): View
    {
        $user = app(RestaurantAccessContext::class)->owner();
        $restaurant = $user->restaurant;

        $categories = $restaurant->menuCategories()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $products = $restaurant->menuItems()
            ->where('is_active', true)
            ->where('is_available', true)
            ->with('category')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('pos', [
            'restaurant' => $restaurant,
            'categories' => $categories,
            'products' => $products,
            'currentUser' => $user,
            'posIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function complete(Request $request): RedirectResponse
    {
        $owner = app(RestaurantAccessContext::class)->owner();
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
            'items_json' => ['required', 'json', 'max:30000'],
            'discount_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $submitted = json_decode($data['items_json'], true, 512, JSON_THROW_ON_ERROR);
        validator(['items' => $submitted], [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.menu_item_id' => ['required', 'integer', 'distinct', Rule::exists('restaurant_menu_items', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'items.*.quantity' => ['required', 'integer', 'between:1,999'],
        ])->validate();
        $restaurantId = (int) $owner->restaurant_id;
        $existing = RestaurantPosOrder::query()->where('restaurant_id', $restaurantId)
            ->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return redirect()->route('pos.orders.index')->with('status', "Order {$existing->order_number} was already completed.");
        }

        $order = DB::transaction(function () use ($owner, $restaurantId, $submitted, $data): RestaurantPosOrder {
            abort_unless(DB::table('restaurants')->where('id', $restaurantId)->lockForUpdate()->first(), 404);
            $existing = RestaurantPosOrder::query()->where('restaurant_id', $restaurantId)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $existing;
            }
            $settings = DB::table('inventory_settings')->where('restaurant_id', $restaurantId)->first();
            $order = RestaurantPosOrder::create([
                'restaurant_id' => $restaurantId,
                'created_by' => $owner->id,
                'order_number' => 'TMP-'.Str::random(20),
                'status' => 'draft',
                'idempotency_key' => $data['idempotency_key'],
                'subtotal' => '0.00', 'discount' => '0.00', 'tax' => '0.00', 'total' => '0.00',
            ]);
            $subtotal = BigDecimal::zero();
            $recipes = [];
            foreach ($submitted as $line) {
                $product = RestaurantMenuItem::query()->where('restaurant_id', $restaurantId)
                    ->where('is_active', true)->where('is_available', true)->lockForUpdate()
                    ->findOrFail((int) $line['menu_item_id']);
                $quantity = (int) $line['quantity'];
                $lineTotal = BigDecimal::of((string) $product->price)->multipliedBy($quantity)->toScale(2, RoundingMode::HALF_UP);
                $subtotal = $subtotal->plus($lineTotal);
                $recipe = MenuRecipe::query()->where('restaurant_id', $restaurantId)
                    ->where('menu_item_id', $product->id)->where('is_active', true)
                    ->with('ingredients')->lockForUpdate()->first();
                $orderItem = RestaurantPosOrderItem::create([
                    'restaurant_id' => $restaurantId,
                    'pos_order_id' => $order->id,
                    'menu_item_id' => $product->id,
                    'menu_recipe_id' => $recipe?->id,
                    'recipe_version' => $recipe?->version,
                    'item_name' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                ]);
                if ($recipe && ($settings?->consume_recipes ?? true)) {
                    $recipes[] = [$orderItem, $recipe];
                    foreach ($recipe->ingredients as $ingredient) {
                        $quantityPerServing = BigDecimal::of((string) $ingredient->base_quantity)
                            ->dividedBy((string) $recipe->yield_quantity, 9, RoundingMode::HALF_UP)
                            ->toScale(6, RoundingMode::HALF_UP);
                        RestaurantPosOrderItemIngredient::create([
                            'restaurant_id' => $restaurantId,
                            'order_item_id' => $orderItem->id,
                            'inventory_item_id' => $ingredient->inventory_item_id,
                            'unit_id' => $ingredient->unit_id,
                            'quantity_per_serving' => $quantityPerServing->__toString(),
                        ]);
                    }
                }
            }
            $discount = $subtotal->multipliedBy((string) $data['discount_percent'])
                ->dividedBy('100', 2, RoundingMode::HALF_UP);
            $tax = $subtotal->minus($discount)->multipliedBy('0.05')->toScale(2, RoundingMode::HALF_UP);
            $total = $subtotal->minus($discount)->plus($tax)->toScale(2, RoundingMode::HALF_UP);
            $order->update([
                'order_number' => sprintf('POS%06d', $order->id),
                'subtotal' => $subtotal->toScale(2, RoundingMode::HALF_UP)->__toString(),
                'discount' => $discount->__toString(), 'tax' => $tax->__toString(),
                'total' => $total->__toString(), 'status' => 'completed',
                'completed_at' => now(),
            ]);
            $this->audit($owner, 'pos_order_completed', 'pos_order', $order->id, [
                'order_number' => $order->order_number, 'total' => $order->total,
            ]);
            if ($settings?->consume_recipes ?? true) {
                $defaultLocationId = $settings?->default_location_id;
                foreach ($recipes as [$orderItem, $recipe]) {
                    foreach ($recipe->ingredients as $ingredient) {
                        $item = InventoryItem::query()->where('restaurant_id', $restaurantId)->findOrFail($ingredient->inventory_item_id);
                        $locationId = $item->default_location_id ?: $defaultLocationId;
                        abort_unless($locationId, 422, "Set a default stock location for {$item->name} before selling recipe-based products.");
                        $location = InventoryLocation::query()->where('restaurant_id', $restaurantId)->findOrFail($locationId);
                        $quantityPerServing = BigDecimal::of((string) $ingredient->quantity)
                            ->dividedBy((string) $recipe->yield_quantity, 9, RoundingMode::HALF_UP)
                            ->multipliedBy((int) $orderItem->quantity)->toScale(6, RoundingMode::HALF_UP);
                        $this->stock->issue($owner, $item, $location, $quantityPerServing->__toString(), 'consumption',
                            'pos-order:'.$order->id.':line:'.$orderItem->id.':ingredient:'.$ingredient->id, [
                                'unit_id' => $ingredient->unit_id,
                                'source_type' => 'pos_sale',
                                'source_id' => $order->id,
                                'source_line_id' => $orderItem->id,
                                'reference' => $order->order_number,
                                'reason' => 'pos_sale',
                            ]);
                    }
                }
            }

            return $order;
        });

        return redirect()->route('pos.orders.index')->with('status', "Order {$order->order_number} completed.");
    }

    public function orders(): View
    {
        $owner = app(RestaurantAccessContext::class)->owner();

        return view('pos.orders', [
            'currentUser' => $owner,
            'restaurant' => $owner->restaurant,
            'orders' => RestaurantPosOrder::query()->where('restaurant_id', $owner->restaurant_id)
                ->with('items')->latest()->paginate(30),
        ]);
    }

    public function cancel(int $order): RedirectResponse
    {
        $owner = app(RestaurantAccessContext::class)->owner();
        DB::transaction(function () use ($owner, $order): void {
            $sale = RestaurantPosOrder::query()->where('restaurant_id', $owner->restaurant_id)
                ->lockForUpdate()->findOrFail($order);
            if ($sale->status === 'cancelled') {
                return;
            }
            abort_unless($sale->status === 'completed', 409, 'Only completed orders can be cancelled.');
            $movements = InventoryMovement::query()->where('restaurant_id', $owner->restaurant_id)
                ->where('source_type', 'pos_sale')->where('source_id', $sale->id)->get();
            foreach ($movements as $movement) {
                $this->stock->reverseIssue($owner, $movement, 'pos-reversal:'.$sale->id.':movement:'.$movement->id);
            }
            $sale->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->audit($owner, 'pos_order_cancelled', 'pos_order', $sale->id, [
                'order_number' => $sale->order_number,
            ]);
        });

        return back()->with('status', 'Order cancelled; stock deductions were reversed.');
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
}
