<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\RestaurantAuditLog;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantPosInvoice;
use App\Models\RestaurantPosOrder;
use App\Models\RestaurantPosOrderItem;
use App\Models\RestaurantPosOrderStatusHistory;
use App\Models\RestaurantPosPayment;
use App\Models\RestaurantTable;
use App\Models\RestaurantTableOccupancy;
use App\Models\RestaurantTableStatusHistory;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\InventoryStockService;
use App\Services\PosTicketService;
use App\Services\RestaurantAccessContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PosController extends Controller
{
    public function __construct(
        private readonly InventoryStockService $stock,
        private readonly PosTicketService $tickets,
    ) {}

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
            'heldBillsCount' => RestaurantPosOrder::query()->where('restaurant_id', $restaurant->id)
                ->where('status', 'draft')->where('fulfillment_status', 'held')->count(),
            'hasActiveTables' => RestaurantTable::query()->where('restaurant_id', $restaurant->id)
                ->where('is_active', true)->whereNull('archived_at')->exists(),
            'tableFloors' => \App\Models\RestaurantFloor::query()->where('restaurant_id', $restaurant->id)
                ->where('is_active', true)->whereNull('archived_at')->whereHas('tables', fn($query) => $query->where('is_active', true)->whereNull('archived_at')
                    ->whereHas('floor', fn($floor) => $floor->where('restaurant_id', $restaurant->id)->where('is_active', true)->whereNull('archived_at'))
                    ->where(fn($table) => $table->whereNull('section_id')->orWhereHas('section', fn($section) => $section->where('restaurant_id', $restaurant->id)->where('is_active', true)->whereNull('archived_at')))
                    ->where(fn($available) => $available->where('status', 'available')->orWhereHas('activeOccupancy', fn($occupancy) => $occupancy->whereNull('pos_order_id')->where(fn($session) => $session->whereNull('group_id')->orWhereHas('group', fn($group) => $group->whereNull('ended_at'))))))
                ->with(['sections' => fn($query) => $query->where('is_active', true)->whereNull('archived_at')])
                ->orderBy('display_order')->get(),
            'posTables' => RestaurantTable::query()->where('restaurant_id', $restaurant->id)
                ->where('is_active', true)->whereNull('archived_at')
                ->whereHas('floor', fn($floor) => $floor->where('restaurant_id', $restaurant->id)->where('is_active', true)->whereNull('archived_at'))
                ->where(fn($table) => $table->whereNull('section_id')->orWhereHas('section', fn($section) => $section->where('restaurant_id', $restaurant->id)->where('is_active', true)->whereNull('archived_at')))
                ->where(fn($available) => $available->where('status', 'available')->orWhereHas('activeOccupancy', fn($occupancy) => $occupancy->whereNull('pos_order_id')->where(fn($session) => $session->whereNull('group_id')->orWhereHas('group', fn($group) => $group->whereNull('ended_at')))))
                ->with([
                    'floor',
                    'section',
                    'activeOccupancy',
                    'reservations' => fn($query) => $query->whereIn('status', ['pending', 'confirmed'])
                        ->where('starts_at', '>=', now(config('app.timezone'))->utc()),
                ])->orderBy('floor_id')->orderBy('sort_order')->orderBy('table_number')->get(),
            'posIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function heldBills(): JsonResponse
    {
        $owner = app(RestaurantAccessContext::class)->owner();
        $orders = RestaurantPosOrder::query()->where('restaurant_id', $owner->restaurant_id)
            ->where('status', 'draft')->where('fulfillment_status', 'held')
            ->with('items.menuItem')->latest('updated_at')->get();
        $bills = [];
        foreach ($orders as $heldOrder) {
            $bills[] = $this->heldBillPayload($heldOrder);
        }

        return response()->json([
            'count' => $orders->count(),
            'bills' => $bills,
        ]);
    }

    public function storeHeldBill(Request $request): JsonResponse
    {
        $owner = app(RestaurantAccessContext::class)->owner();
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
            'resumed_order_id' => ['nullable', 'integer'],
            'items_json' => ['required', 'json', 'max:30000'],
            'discount_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'order_type' => ['nullable', Rule::in(['dine_in', 'takeaway', 'delivery'])],
            'table_number' => ['nullable', 'string', 'max:32'],
            'table_id' => ['nullable', 'integer'],
            'floor_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'guest_count' => ['nullable', 'integer', 'between:1,100'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'upi'])],
        ]);
        $data['order_type'] = $data['order_type'] ?? 'dine_in';
        if ($data['order_type'] !== 'dine_in') {
            $data['table_number'] = null;
            $data['table_id'] = null;
            $data['floor_id'] = null;
            $data['section_id'] = null;
        }
        $submitted = json_decode($data['items_json'], true, 512, JSON_THROW_ON_ERROR);
        validator(['items' => $submitted], [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.menu_item_id' => ['required', 'integer', 'distinct', Rule::exists('restaurant_menu_items', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'items.*.quantity' => ['required', 'integer', 'between:1,999'],
            'items.*.instructions' => ['nullable', 'string', 'max:200'],
        ])->validate();

        $restaurantId = (int) $owner->restaurant_id;
        $order = DB::transaction(function () use ($owner, $restaurantId, $submitted, $data): RestaurantPosOrder {
            abort_unless(DB::table('restaurants')->where('id', $restaurantId)->lockForUpdate()->first() !== null, 404);
            $duplicate = RestaurantPosOrder::query()->where('restaurant_id', $restaurantId)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($duplicate) {
                abort_unless($duplicate->status === 'draft' && $duplicate->fulfillment_status === 'held', 409, 'This hold request was already used.');

                return $duplicate;
            }

            $resumedOrder = null;
            $savedItems = collect();
            if (! empty($data['resumed_order_id'])) {
                $resumedOrder = RestaurantPosOrder::query()->where('restaurant_id', $restaurantId)
                    ->where('status', 'draft')->whereIn('fulfillment_status', ['resumed', 'kot'])
                    ->lockForUpdate()->findOrFail((int) $data['resumed_order_id']);
                $savedItems = $resumedOrder->items()->get()->keyBy('menu_item_id');
            }

            $table = null;
            if ($data['order_type'] === 'dine_in' && ! empty($data['table_id'])) {
                $table = RestaurantTable::query()->where('restaurant_id', $restaurantId)
                    ->where('is_active', true)->whereNull('archived_at')->findOrFail((int) $data['table_id']);
                abort_unless($table->floor_id === (int) ($data['floor_id'] ?? $table->floor_id), 422, 'The selected floor does not match this table.');
                abort_unless($table->section_id === (isset($data['section_id']) ? (int) $data['section_id'] : null), 422, 'The selected section does not match this table.');
            }

            $order = $resumedOrder ?? RestaurantPosOrder::create([
                'restaurant_id' => $restaurantId,
                'created_by' => $owner->id,
                'order_number' => 'TMP-' . Str::random(20),
                'idempotency_key' => $data['idempotency_key'],
                'subtotal' => '0.00',
                'discount' => '0.00',
                'tax' => '0.00',
                'total' => '0.00',
            ]);
            $order->items()->delete();
            $subtotal = BigDecimal::zero();
            foreach ($submitted as $line) {
                $menuItemId = (int) $line['menu_item_id'];
                $savedItem = $savedItems->get($menuItemId);
                $productQuery = RestaurantMenuItem::query()->where('restaurant_id', $restaurantId)->lockForUpdate();
                if (! $savedItem) {
                    $productQuery->where('is_active', true)->where('is_available', true);
                }
                $product = $productQuery->findOrFail($menuItemId);
                $quantity = (int) $line['quantity'];
                $unitPrice = $savedItem?->unit_price ?? $product->price;
                $itemName = $savedItem?->item_name ?? $product->name;
                $subtotal = $subtotal->plus(BigDecimal::of((string) $unitPrice)->multipliedBy($quantity)->toScale(2, RoundingMode::HALF_UP));
                RestaurantPosOrderItem::create([
                    'restaurant_id' => $restaurantId,
                    'pos_order_id' => $order->id,
                    'menu_item_id' => $product->id,
                    'item_name' => $itemName,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'special_instructions' => $this->lineInstructions($line),
                ]);
            }
            $discount = $subtotal->multipliedBy((string) $data['discount_percent'])->dividedBy('100', 2, RoundingMode::HALF_UP);
            $tax = $subtotal->minus($discount)->multipliedBy('0.05')->toScale(2, RoundingMode::HALF_UP);
            $total = $subtotal->minus($discount)->plus($tax)->toScale(2, RoundingMode::HALF_UP);
            $order->update([
                'restaurant_id' => $restaurantId,
                'updated_by' => $owner->id,
                'order_number' => $resumedOrder?->order_number ?? sprintf('HB%s%06d', now()->format('Ymd'), $order->id),
                'idempotency_key' => $data['idempotency_key'],
                'status' => 'draft',
                'order_type' => $data['order_type'],
                'table_number' => $table?->table_number ?? ($data['table_number'] ?? null),
                'floor_id' => $table?->floor_id,
                'section_id' => $table?->section_id,
                'table_id' => $table?->id,
                'original_table_id' => $order->original_table_id ?? $table?->id,
                'guest_count' => $data['guest_count'] ?? null,
                'fulfillment_status' => 'held',
                'payment_status' => 'unpaid',
                'payment_method' => $data['payment_method'],
                'subtotal' => $subtotal->toScale(2, RoundingMode::HALF_UP)->__toString(),
                'discount' => $discount->__toString(),
                'tax' => $tax->__toString(),
                'total' => $total->__toString(),
                'completed_at' => null,
                'cancelled_at' => null,
            ]);

            return $order;
        });

        return response()->json([
            'bill' => $this->heldBillPayload($order->load('items.menuItem')),
            'count' => RestaurantPosOrder::query()->where('restaurant_id', $restaurantId)
                ->where('status', 'draft')->where('fulfillment_status', 'held')->count(),
        ], 201);
    }

    public function resumeHeldBill(int $order): JsonResponse
    {
        $owner = app(RestaurantAccessContext::class)->owner();
        $heldBill = DB::transaction(function () use ($owner, $order): RestaurantPosOrder {
            $heldBill = RestaurantPosOrder::query()->where('restaurant_id', $owner->restaurant_id)
                ->where('status', 'draft')->whereIn('fulfillment_status', ['held', 'resumed'])
                ->with('items.menuItem')->lockForUpdate()->findOrFail($order);
            if ($heldBill->fulfillment_status === 'held') {
                $heldBill->update(['fulfillment_status' => 'resumed', 'updated_by' => $owner->id]);
            }

            return $heldBill;
        });

        return response()->json([
            'bill' => $this->heldBillPayload($heldBill),
            'count' => RestaurantPosOrder::query()->where('restaurant_id', $owner->restaurant_id)
                ->where('status', 'draft')->where('fulfillment_status', 'held')->count(),
        ]);
    }

    public function deleteHeldBill(int $order): JsonResponse
    {
        $owner = app(RestaurantAccessContext::class)->owner();
        $count = DB::transaction(function () use ($owner, $order): int {
            $heldBill = RestaurantPosOrder::query()->where('restaurant_id', $owner->restaurant_id)
                ->where('status', 'draft')->where('fulfillment_status', 'held')
                ->lockForUpdate()->findOrFail($order);
            abort_unless($heldBill->completed_at === null && ! $heldBill->payments()->exists(), 409, 'Only unpaid held bills can be deleted.');
            $this->audit($owner, 'pos_held_bill_deleted', 'pos_order', $heldBill->id, [
                'order_number' => $heldBill->order_number,
            ]);
            $heldBill->items()->delete();
            $heldBill->delete();

            return RestaurantPosOrder::query()->where('restaurant_id', $owner->restaurant_id)
                ->where('status', 'draft')->where('fulfillment_status', 'held')->count();
        });

        return response()->json(['count' => $count]);
    }

    public function complete(Request $request): RedirectResponse|JsonResponse
    {
        $owner = app(RestaurantAccessContext::class)->owner();
        $this->rejectSensitiveCardFields($request);
        $this->trimPaymentInput($request);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
            'items_json' => ['required', 'json', 'max:30000'],
            'discount_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'order_type' => ['nullable', Rule::in(['dine_in', 'takeaway', 'delivery'])],
            'table_number' => ['nullable', 'string', 'max:32'],
            'table_id' => ['nullable', 'integer'],
            'floor_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'guest_count' => ['nullable', 'integer', 'between:1,100'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'upi'])],
            'resumed_order_id' => ['nullable', 'integer'],
            'payment_verified' => ['required', 'accepted'],
            'cash_received' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'upi_reference' => ['required_if:payment_method,upi', 'nullable', 'string', 'max:100'],
            'card_last_four' => ['required_if:payment_method,card', 'nullable', 'regex:/^[0-9]{4}$/'],
            'card_reference' => ['required_if:payment_method,card', 'nullable', 'string', 'max:100'],
        ]);
        $data['order_type'] = $data['order_type'] ?? 'dine_in';
        if ($data['order_type'] !== 'dine_in') {
            $data['table_number'] = null;
            $data['table_id'] = null;
            $data['floor_id'] = null;
            $data['section_id'] = null;
        }
        if ($data['order_type'] === 'dine_in' && RestaurantTable::where('restaurant_id', $owner->restaurant_id)->whereNull('archived_at')->where('is_active', true)->exists()) {
            validator($data, ['table_id' => ['required', 'integer']])->validate();
        }
        $submitted = json_decode($data['items_json'], true, 512, JSON_THROW_ON_ERROR);
        validator(['items' => $submitted], [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.menu_item_id' => ['required', 'integer', 'distinct', Rule::exists('restaurant_menu_items', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'items.*.quantity' => ['required', 'integer', 'between:1,999'],
            'items.*.instructions' => ['nullable', 'string', 'max:200'],
        ])->validate();
        $restaurantId = (int) $owner->restaurant_id;
        $existing = RestaurantPosOrder::query()->where('restaurant_id', $restaurantId)
            ->where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing && $existing->status !== 'draft') {
            return $this->checkoutResponse($request, $existing, true);
        }

        $result = DB::transaction(function () use ($owner, $restaurantId, $submitted, $data): array {
            abort_unless(DB::table('restaurants')->where('id', $restaurantId)->lockForUpdate()->first() !== null, 404);
            $existing = RestaurantPosOrder::query()->where('restaurant_id', $restaurantId)
                ->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing && $existing->status !== 'draft') {
                return ['order' => $existing, 'created' => false];
            }
            $resumedOrder = null;
            $savedItems = collect();
            if (! empty($data['resumed_order_id'])) {
                $resumedOrder = RestaurantPosOrder::query()->where('restaurant_id', $restaurantId)
                    ->where('status', 'draft')->whereIn('fulfillment_status', ['resumed', 'kot'])
                    ->lockForUpdate()->findOrFail((int) $data['resumed_order_id']);
                abort_if($existing && $existing->id !== $resumedOrder->id, 409, 'This checkout is already linked to a different open order.');
                abort_if($resumedOrder->payments()->exists(), 409, 'A resumed held bill already has a payment record.');
                $savedItems = $resumedOrder->items()->get()->keyBy('menu_item_id');
            } elseif ($existing && in_array($existing->fulfillment_status, ['kot', 'resumed'], true)) {
                $resumedOrder = $existing;
                abort_if($resumedOrder->payments()->exists(), 409, 'A resumed held bill already has a payment record.');
                $savedItems = $resumedOrder->items()->get()->keyBy('menu_item_id');
            } elseif ($existing) {
                abort(409, 'This checkout key belongs to a held bill. Resume that bill before generating an invoice.');
            }
            $table = null;
            $tableGroup = null;
            $tableOccupancy = null;
            $groupOccupancies = collect();
            if ($data['order_type'] === 'dine_in' && ! empty($data['table_id'])) {
                $table = RestaurantTable::query()->where('restaurant_id', $restaurantId)
                    ->where('is_active', true)->whereNull('archived_at')->lockForUpdate()->findOrFail((int) $data['table_id']);
                abort_unless($table->floor()->where('restaurant_id', $restaurantId)->where('is_active', true)->whereNull('archived_at')->exists(), 409, 'The selected floor is inactive. Choose an active floor.');
                if ($table->section_id) {
                    abort_unless($table->section()->where('restaurant_id', $restaurantId)->where('floor_id', $table->floor_id)->where('is_active', true)->whereNull('archived_at')->exists(), 409, 'The selected section is inactive. Choose an active table location.');
                }
                abort_unless($table->floor_id === (int) ($data['floor_id'] ?? $table->floor_id), 422, 'The selected floor does not match this table.');
                abort_unless($table->section_id === (isset($data['section_id']) ? (int) $data['section_id'] : null), 422, 'The selected section does not match this table.');
                if ($table->status === 'available') {
                    abort_unless((int) ($data['guest_count'] ?? 1) <= $table->capacity, 422, 'Guest count exceeds the selected table capacity.');
                } else {
                    $tableOccupancy = $table->occupancies()->whereNull('ended_at')->lockForUpdate()->first();
                    abort_unless($table->status === 'occupied' && $tableOccupancy && ! $tableOccupancy->pos_order_id, 409, 'This table is no longer available. Refresh the POS table list.');
                    if ($tableOccupancy->group_id) {
                        $tableGroup = \App\Models\RestaurantTableGroup::where('restaurant_id', $restaurantId)->whereNull('ended_at')->lockForUpdate()->findOrFail($tableOccupancy->group_id);
                        $memberIds = \App\Models\RestaurantTableGroupMember::where('group_id', $tableGroup->id)->orderBy('table_id')->pluck('table_id');
                        $groupOccupancies = \App\Models\RestaurantTableOccupancy::where('restaurant_id', $restaurantId)->where('group_id', $tableGroup->id)->whereNull('ended_at')->orderBy('table_id')->lockForUpdate()->get();
                        abort_unless($groupOccupancies->count() === $memberIds->count() && $groupOccupancies->every(fn($session) => $session->pos_order_id === null), 409, 'This combined party already has an active POS order.');
                        $capacity = RestaurantTable::where('restaurant_id', $restaurantId)->whereIn('id', $memberIds)->sum('capacity');
                        abort_unless((int) ($data['guest_count'] ?? 1) <= $capacity, 422, 'Guest count exceeds the combined table capacity.');
                    } else {
                        abort_unless((int) ($data['guest_count'] ?? 1) <= $table->capacity, 422, 'Guest count exceeds the selected table capacity.');
                    }
                }
                $reservationTableIds = $tableGroup
                    ? \App\Models\RestaurantTableGroupMember::where('group_id', $tableGroup->id)->pluck('table_id')
                    : collect([$table->id]);
                abort_if(\App\Models\RestaurantTableReservation::query()->where('restaurant_id', $restaurantId)
                    ->whereIn('table_id', $reservationTableIds)->whereIn('status', ['pending', 'confirmed'])
                    ->where('starts_at', '>=', now(config('app.timezone'))->utc())->exists(), 409, 'This table is blocked by an upcoming reservation.');
            }
            $previousFulfillment = $resumedOrder?->fulfillment_status;
            if ($resumedOrder) {
                $order = $resumedOrder;
                $order->items()->delete();
                $order->update([
                    'updated_by' => $owner->id,
                    'order_type' => $data['order_type'],
                    'table_number' => $table?->table_number ?? ($data['table_number'] ?? null),
                    'floor_id' => $table?->floor_id,
                    'section_id' => $table?->section_id,
                    'table_id' => $table?->id,
                    'original_table_id' => $resumedOrder->original_table_id ?? $table?->id,
                    'guest_count' => $data['guest_count'] ?? null,
                    'fulfillment_status' => 'pending',
                    'payment_method' => $data['payment_method'],
                    'idempotency_key' => $data['idempotency_key'],
                ]);
            } else {
                $order = RestaurantPosOrder::create([
                    'restaurant_id' => $restaurantId,
                    'created_by' => $owner->id,
                    'order_number' => 'TMP-' . Str::random(20),
                    'status' => 'draft',
                    'order_type' => $data['order_type'],
                    'table_number' => $table?->table_number ?? ($data['table_number'] ?? null),
                    'floor_id' => $table?->floor_id,
                    'section_id' => $table?->section_id,
                    'table_id' => $table?->id,
                    'original_table_id' => $table?->id,
                    'guest_count' => $data['guest_count'] ?? null,
                    'fulfillment_status' => 'pending',
                    'payment_method' => $data['payment_method'],
                    'idempotency_key' => $data['idempotency_key'],
                    'subtotal' => '0.00',
                    'discount' => '0.00',
                    'tax' => '0.00',
                    'total' => '0.00',
                ]);
            }
            $subtotal = BigDecimal::zero();
            foreach ($submitted as $line) {
                $menuItemId = (int) $line['menu_item_id'];
                $savedItem = $savedItems->get($menuItemId);
                $productQuery = RestaurantMenuItem::query()->where('restaurant_id', $restaurantId)->lockForUpdate();
                if (! $savedItem) {
                    $productQuery->where('is_active', true)->where('is_available', true);
                }
                $product = $productQuery->findOrFail($menuItemId);
                $quantity = (int) $line['quantity'];
                $unitPrice = $savedItem?->unit_price ?? $product->price;
                $itemName = $savedItem?->item_name ?? $product->name;
                $lineTotal = BigDecimal::of((string) $unitPrice)->multipliedBy($quantity)->toScale(2, RoundingMode::HALF_UP);
                $subtotal = $subtotal->plus($lineTotal);
                RestaurantPosOrderItem::create([
                    'restaurant_id' => $restaurantId,
                    'pos_order_id' => $order->id,
                    'menu_item_id' => $product->id,
                    'item_name' => $itemName,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'special_instructions' => $this->lineInstructions($line),
                ]);
            }
            $discount = $subtotal->multipliedBy((string) $data['discount_percent'])
                ->dividedBy('100', 2, RoundingMode::HALF_UP);
            $tax = $subtotal->minus($discount)->multipliedBy('0.05')->toScale(2, RoundingMode::HALF_UP);
            $total = $subtotal->minus($discount)->plus($tax)->toScale(2, RoundingMode::HALF_UP);
            $tender = $this->confirmedTender($data, $total);
            $order->update([
                'order_number' => sprintf('POS%06d', $order->id),
                'subtotal' => $subtotal->toScale(2, RoundingMode::HALF_UP)->__toString(),
                'discount' => $discount->__toString(),
                'tax' => $tax->__toString(),
                'total' => $total->__toString(),
                'status' => 'completed',
                'completed_at' => now(),
                'fulfillment_status' => 'pending',
                'updated_by' => $owner->id,
            ]);
            if ($table && $tableGroup) {
                foreach ($groupOccupancies as $index => $occupancy) {
                    $occupancy->update([
                        'pos_order_id' => $order->id,
                        'guest_count' => $index === 0 ? (int) ($data['guest_count'] ?? 1) : 0,
                    ]);
                }
            } elseif ($table && $tableOccupancy) {
                RestaurantTableOccupancy::query()->whereKey($tableOccupancy->id)->update([
                    'pos_order_id' => $order->id,
                    'guest_count' => (int) ($data['guest_count'] ?? $tableOccupancy->guest_count),
                ]);
            } elseif ($table) {
                RestaurantTableOccupancy::create([
                    'restaurant_id' => $restaurantId,
                    'table_id' => $table->id,
                    'pos_order_id' => $order->id,
                    'started_by' => $owner->id,
                    'guest_count' => (int) ($data['guest_count'] ?? 1),
                    'started_at' => now(),
                ]);
                RestaurantTableStatusHistory::create([
                    'restaurant_id' => $restaurantId,
                    'table_id' => $table->id,
                    'actor_id' => $owner->id,
                    'from_status' => 'available',
                    'to_status' => 'occupied',
                    'reason' => 'Dine-in POS order ' . $order->order_number,
                    'changed_at' => now(),
                ]);
                $table->update(['status' => 'occupied']);
            }
            RestaurantPosOrderStatusHistory::create([
                'restaurant_id' => $restaurantId,
                'pos_order_id' => $order->id,
                'actor_id' => $owner->id,
                'actor_type' => 'restaurant_user',
                'from_status' => $previousFulfillment,
                'to_status' => 'pending',
                'changed_at' => now(),
            ]);
            $paymentReference = 'POSPAY'.sprintf('%010d', $order->id);
            $payment = RestaurantPosPayment::create([
                'restaurant_id' => $restaurantId,
                'pos_order_id' => $order->id,
                'recorded_by' => $owner->id,
                'payment_reference' => $paymentReference,
                'idempotency_key' => 'pos-payment:'.$order->id,
                'amount' => $order->total,
                'cash_received' => $tender['cash_received'],
                'change_amount' => $tender['change_amount'],
                'method' => $data['payment_method'],
                'external_reference' => $tender['external_reference'],
                'card_last_four' => $tender['card_last_four'],
                'status' => 'recorded',
                'recorded_at' => now(),
                'notes' => $tender['notes'],
            ]);
            $restaurant = $owner->restaurant()->firstOrFail();
            $invoice = RestaurantPosInvoice::create([
                'restaurant_id' => $restaurantId,
                'pos_order_id' => $order->id,
                'invoice_number' => 'TMP-'.Str::upper(Str::random(16)),
                'issued_at' => now(),
                'restaurant_name' => $restaurant->name,
                'restaurant_address' => $restaurant->address,
                'restaurant_phone' => $restaurant->phone,
                'subtotal' => $order->subtotal,
                'discount' => $order->discount,
                'tax' => $order->tax,
                'total' => $order->total,
                'payment_method' => $payment->method,
                'payment_reference' => $payment->payment_reference,
                'cash_received' => $tender['cash_received'],
                'change_amount' => $tender['change_amount'],
                'upi_reference' => $tender['upi_reference'],
                'card_last_four' => $tender['card_last_four'],
                'card_reference' => $tender['card_reference'],
            ]);
            $invoice->update(['invoice_number' => sprintf('INV%06d', $invoice->id)]);
            $order->update([
                'payment_status' => 'paid',
                'payment_method' => $payment->method,
            ]);
            $this->tickets->refreshSnapshot($order);
            $this->audit($owner, 'pos_payment_recorded', 'pos_order', $order->id, [
                'payment_reference' => $payment->payment_reference,
                'method' => $payment->method,
                'amount' => $payment->amount,
                'verified_by_operator' => true,
            ]);
            $this->audit($owner, 'pos_invoice_issued', 'pos_order', $order->id, [
                'invoice_number' => $invoice->invoice_number,
                'order_number' => $order->order_number,
                'total' => $order->total,
            ]);
            $this->audit($owner, 'pos_order_completed', 'pos_order', $order->id, [
                'order_number' => $order->order_number,
                'total' => $order->total,
            ]);

            return ['order' => $order, 'created' => true];
        });

        return $this->checkoutResponse($request, $result['order'], ! $result['created']);
    }

    public function storeKot(Request $request): JsonResponse
    {
        $owner = app(RestaurantAccessContext::class)->owner();
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
            'items_json' => ['required', 'json', 'max:30000'],
            'discount_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'order_type' => ['nullable', Rule::in(['dine_in', 'takeaway', 'delivery'])],
            'table_number' => ['nullable', 'string', 'max:32'],
            'table_id' => ['nullable', 'integer'],
            'floor_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'guest_count' => ['nullable', 'integer', 'between:1,100'],
            'resumed_order_id' => ['nullable', 'integer'],
        ]);
        $data['order_type'] = $data['order_type'] ?? 'dine_in';
        if ($data['order_type'] !== 'dine_in') {
            $data['table_number'] = null;
            $data['table_id'] = null;
            $data['floor_id'] = null;
            $data['section_id'] = null;
        }
        if ($data['order_type'] === 'dine_in' && RestaurantTable::where('restaurant_id', $owner->restaurant_id)->whereNull('archived_at')->where('is_active', true)->exists()) {
            validator($data, ['table_id' => ['required', 'integer']])->validate();
        }
        $submitted = json_decode($data['items_json'], true, 512, JSON_THROW_ON_ERROR);
        validator(['items' => $submitted], [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.menu_item_id' => ['required', 'integer', 'distinct', Rule::exists('restaurant_menu_items', 'id')->where('restaurant_id', $owner->restaurant_id)],
            'items.*.quantity' => ['required', 'integer', 'between:1,999'],
            'items.*.instructions' => ['nullable', 'string', 'max:200'],
        ])->validate();

        $restaurantId = (int) $owner->restaurant_id;
        [$order, $kot] = DB::transaction(function () use ($owner, $restaurantId, $submitted, $data): array {
            abort_unless(DB::table('restaurants')->where('id', $restaurantId)->lockForUpdate()->first() !== null, 404);
            $existing = RestaurantPosOrder::query()->where('restaurant_id', $restaurantId)
                ->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing && $existing->status !== 'draft') {
                return [$existing, $this->tickets->issue($owner, $existing, false)];
            }

            $order = null;
            $savedItems = collect();
            if (! empty($data['resumed_order_id'])) {
                $order = RestaurantPosOrder::query()->where('restaurant_id', $restaurantId)
                    ->where('status', 'draft')->whereIn('fulfillment_status', ['resumed', 'kot', 'held'])
                    ->lockForUpdate()->findOrFail((int) $data['resumed_order_id']);
                abort_if($existing && $existing->id !== $order->id, 409, 'This kitchen ticket is already linked to a different open order.');
                $savedItems = $order->items()->get()->keyBy('menu_item_id');
            } elseif ($existing) {
                abort_unless(in_array($existing->fulfillment_status, ['kot', 'resumed'], true), 409, 'Resume the held bill before printing its kitchen ticket.');
                $order = $existing;
                $savedItems = $order->items()->get()->keyBy('menu_item_id');
            }

            $table = null;
            if ($data['order_type'] === 'dine_in' && ! empty($data['table_id'])) {
                $table = RestaurantTable::query()->where('restaurant_id', $restaurantId)
                    ->where('is_active', true)->whereNull('archived_at')->findOrFail((int) $data['table_id']);
                abort_unless($table->floor_id === (int) ($data['floor_id'] ?? $table->floor_id), 422, 'The selected floor does not match this table.');
                abort_unless($table->section_id === (isset($data['section_id']) ? (int) $data['section_id'] : null), 422, 'The selected section does not match this table.');
            }

            if (! $order) {
                $order = RestaurantPosOrder::create([
                    'restaurant_id' => $restaurantId,
                    'created_by' => $owner->id,
                    'order_number' => 'TMP-'.Str::random(20),
                    'status' => 'draft',
                    'idempotency_key' => $data['idempotency_key'],
                    'subtotal' => '0.00',
                    'discount' => '0.00',
                    'tax' => '0.00',
                    'total' => '0.00',
                ]);
            } else {
                $order->items()->delete();
            }

            $subtotal = BigDecimal::zero();
            foreach ($submitted as $line) {
                $menuItemId = (int) $line['menu_item_id'];
                $savedItem = $savedItems->get($menuItemId);
                $productQuery = RestaurantMenuItem::query()->where('restaurant_id', $restaurantId)->lockForUpdate();
                if (! $savedItem) {
                    $productQuery->where('is_active', true)->where('is_available', true);
                }
                $product = $productQuery->findOrFail($menuItemId);
                $quantity = (int) $line['quantity'];
                $unitPrice = $savedItem?->unit_price ?? $product->price;
                $lineTotal = BigDecimal::of((string) $unitPrice)->multipliedBy($quantity)->toScale(2, RoundingMode::HALF_UP);
                $subtotal = $subtotal->plus($lineTotal);
                RestaurantPosOrderItem::create([
                    'restaurant_id' => $restaurantId,
                    'pos_order_id' => $order->id,
                    'menu_item_id' => $product->id,
                    'item_name' => $savedItem?->item_name ?? $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'special_instructions' => $this->lineInstructions($line),
                ]);
            }
            $discount = $subtotal->multipliedBy((string) $data['discount_percent'])->dividedBy('100', 2, RoundingMode::HALF_UP);
            $tax = $subtotal->minus($discount)->multipliedBy('0.05')->toScale(2, RoundingMode::HALF_UP);
            $total = $subtotal->minus($discount)->plus($tax)->toScale(2, RoundingMode::HALF_UP);
            $order->update([
                'updated_by' => $owner->id,
                'order_number' => str_starts_with((string) $order->order_number, 'TMP-') || str_starts_with((string) $order->order_number, 'HB')
                    ? sprintf('POS%06d', $order->id)
                    : $order->order_number,
                'idempotency_key' => $data['idempotency_key'],
                'status' => 'draft',
                'order_type' => $data['order_type'],
                'table_number' => $table?->table_number ?? ($data['table_number'] ?? null),
                'floor_id' => $table?->floor_id,
                'section_id' => $table?->section_id,
                'table_id' => $table?->id,
                'original_table_id' => $order->original_table_id ?? $table?->id,
                'guest_count' => $data['guest_count'] ?? null,
                'fulfillment_status' => 'kot',
                'payment_status' => 'unpaid',
                'subtotal' => $subtotal->toScale(2, RoundingMode::HALF_UP)->__toString(),
                'discount' => $discount->__toString(),
                'tax' => $tax->__toString(),
                'total' => $total->__toString(),
                'completed_at' => null,
            ]);

            return [$order, $this->tickets->issue($owner, $order, true)];
        });

        return response()->json([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'kot_number' => $kot->kot_number,
            'reprint_count' => (int) $kot->reprint_count,
            'reprinted' => (int) $kot->reprint_count > 0,
            'print_url' => route('restaurant.orders.kot', ['order' => $order, 'print' => 1]),
        ]);
    }

    public function orders(): View
    {
        $owner = app(RestaurantAccessContext::class)->owner();

        return view('pos.orders', [
            'currentUser' => $owner,
            'restaurant' => $owner->restaurant,
            'orders' => RestaurantPosOrder::query()->where('restaurant_id', $owner->restaurant_id)
                ->where('status', '!=', 'draft')->with('items')->latest()->paginate(30),
        ]);
    }

    public function cancel(Request $request, int $order): RedirectResponse
    {
        $owner = app(RestaurantAccessContext::class)->owner();
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        DB::transaction(function () use ($owner, $order, $data): void {
            $sale = RestaurantPosOrder::query()->where('restaurant_id', $owner->restaurant_id)
                ->with('refunds')->lockForUpdate()->findOrFail($order);
            if ($sale->status === 'cancelled') {
                return;
            }
            abort_unless($sale->status === 'completed', 409, 'Only completed orders can be cancelled.');
            if ($sale->payment_status === 'paid' || $sale->payment_status === 'partially_refunded') {
                $payment = RestaurantPosPayment::query()->where('restaurant_id', $owner->restaurant_id)
                    ->where('pos_order_id', $sale->id)->where('status', 'recorded')->first();
                $refunded = $sale->refunds->where('status', 'recorded')->sum('amount');
                abort_unless($payment && (float) $refunded >= (float) $payment->amount, 409, 'Record a full manual refund before cancelling a paid order.');
            }
            $movements = InventoryMovement::query()->where('restaurant_id', $owner->restaurant_id)
                ->where('source_type', 'pos_sale')->where('source_id', $sale->id)->get();
            foreach ($movements as $movement) {
                $this->stock->reverseIssue($owner, $movement, 'pos-reversal:' . $sale->id . ':movement:' . $movement->id);
            }
            $previousFulfillmentStatus = $sale->fulfillment_status;
            $sale->update([
                'status' => 'cancelled',
                'fulfillment_status' => 'cancelled',
                'cancelled_at' => now(),
                'updated_by' => $owner->id,
            ]);
            RestaurantPosOrderStatusHistory::create([
                'restaurant_id' => $owner->restaurant_id,
                'pos_order_id' => $sale->id,
                'actor_id' => request()->attributes->has('restaurant_admin_access_admin') ? null : $owner->id,
                'actor_type' => request()->attributes->has('restaurant_admin_access_admin') ? 'super_admin' : 'restaurant_user',
                'from_status' => $previousFulfillmentStatus,
                'to_status' => 'cancelled',
                'reason' => $data['reason'],
                'changed_at' => now(),
            ]);
            $this->releaseDiningTable($owner->id, $sale, 'Order cancelled: ' . $data['reason']);
            $this->audit($owner, 'pos_order_cancelled', 'pos_order', $sale->id, [
                'order_number' => $sale->order_number,
                'reason' => $data['reason'],
            ]);
        });

        return back()->with('status', 'Order cancelled; recorded stock deductions were reversed.');
    }

    private function releaseDiningTable(int $actorId, RestaurantPosOrder $sale, string $reason): void
    {
        if (! $sale->table_id) {
            return;
        }
        $occupancies = RestaurantTableOccupancy::where('restaurant_id', $sale->restaurant_id)
            ->where('pos_order_id', $sale->id)->whereNull('ended_at')->orderBy('table_id')->lockForUpdate()->get();
        if ($occupancies->isEmpty()) {
            return;
        }
        foreach ($occupancies as $occupancy) {
            $table = RestaurantTable::where('restaurant_id', $sale->restaurant_id)->lockForUpdate()->find($occupancy->table_id);
            if (! $table) {
                continue;
            }
            $occupancy->update(['ended_at' => now(), 'ended_by' => $actorId]);
            RestaurantTableStatusHistory::create([
                'restaurant_id' => $sale->restaurant_id,
                'table_id' => $table->id,
                'actor_id' => $actorId,
                'actor_type' => 'restaurant_user',
                'from_status' => $table->status,
                'to_status' => 'cleaning',
                'reason' => $reason,
                'changed_at' => now(),
            ]);
            $table->update(['status' => 'cleaning']);
        }
        $groupIds = $occupancies->pluck('group_id')->filter()->unique();
        if ($groupIds->isNotEmpty()) {
            \App\Models\RestaurantTableGroup::where('restaurant_id', $sale->restaurant_id)->whereIn('id', $groupIds)->whereNull('ended_at')->update(['ended_at' => now()]);
        }
    }

    public function reconcilePayment(Request $request, int $order): RedirectResponse
    {
        abort_unless(! app(RestaurantAccessContext::class)->isSuperAdminAccess(), 403);
        $owner = app(RestaurantAccessContext::class)->owner();
        $data = $request->validate([
            'method' => ['required', Rule::in(['cash', 'card', 'upi'])],
            'verification_note' => ['required', 'string', 'min:5', 'max:1000'],
            'confirmed' => ['required', 'accepted'],
        ]);

        DB::transaction(function () use ($owner, $order, $data): void {
            $sale = RestaurantPosOrder::query()
                ->where('restaurant_id', $owner->restaurant_id)
                ->lockForUpdate()
                ->findOrFail($order);
            abort_unless(
                $sale->status === 'completed' && $sale->fulfillment_status !== 'cancelled',
                409,
                'Only completed, non-cancelled orders can be reconciled.',
            );
            abort_unless($sale->payment_status === 'unpaid', 409, 'Only unpaid orders without a recorded payment can be reconciled.');
            abort_if($sale->payments()->where('status', 'recorded')->exists(), 409, 'A payment record already exists for this order.');

            $payment = RestaurantPosPayment::create([
                'restaurant_id' => $owner->restaurant_id,
                'pos_order_id' => $sale->id,
                'recorded_by' => $owner->id,
                'payment_reference' => 'POSREC' . Str::upper(Str::random(10)),
                'idempotency_key' => 'manual-reconciliation:' . $sale->id,
                'amount' => $sale->total,
                'method' => $data['method'],
                'status' => 'recorded',
                'recorded_at' => now(),
                'notes' => 'Manually reconciled after operator verification: ' . $data['verification_note'],
            ]);
            $sale->update([
                'payment_status' => 'paid',
                'payment_method' => $payment->method,
                'updated_by' => $owner->id,
            ]);
            $this->audit($owner, 'pos_payment_manually_reconciled', 'pos_order', $sale->id, [
                'payment_reference' => $payment->payment_reference,
                'method' => $payment->method,
                'amount' => $payment->amount,
                'verification_note' => $data['verification_note'],
            ]);
        });

        return back()->with('status', 'Verified full payment reconciled and recorded.');
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

    private function heldBillPayload(RestaurantPosOrder $order): array
    {
        $discountPercent = BigDecimal::zero();
        if (BigDecimal::of((string) $order->subtotal)->isGreaterThan('0')) {
            $discountPercent = BigDecimal::of((string) $order->discount)->multipliedBy('100')
                ->dividedBy((string) $order->subtotal, 2, RoundingMode::HALF_UP);
        }
        $items = $order->items->map(fn(RestaurantPosOrderItem $item): array => [
            'menu_item_id' => $item->menu_item_id,
            'name' => $item->item_name,
            'price' => (string) $item->unit_price,
            'quantity' => (int) $item->quantity,
            'instructions' => $item->special_instructions,
            'image' => $item->menuItem?->restaurant_id === $order->restaurant_id ? $item->menuItem->image_url : null,
        ])->values();

        return [
            'id' => $order->id,
            'reference' => $order->order_number,
            'created_at' => $order->updated_at?->timezone(config('app.timezone'))->toIso8601String(),
            'order_type' => $order->order_type,
            'table_number' => $order->table_number,
            'table_id' => $order->table_id,
            'floor_id' => $order->floor_id,
            'section_id' => $order->section_id,
            'guest_count' => $order->guest_count,
            'subtotal' => (string) $order->subtotal,
            'discount' => (string) $order->discount,
            'discount_percent' => $discountPercent->__toString(),
            'tax' => (string) $order->tax,
            'total' => (string) $order->total,
            'payment_method' => $order->payment_method,
            'item_count' => (int) $order->items->sum('quantity'),
            'thumbnail' => $items->firstWhere('image', '!=', null)['image'] ?? null,
            'items' => $items,
        ];
    }

    private function checkoutResponse(Request $request, RestaurantPosOrder $order, bool $already): RedirectResponse|JsonResponse
    {
        $order->loadMissing('invoice');
        $invoiceNumber = $order->invoice?->invoice_number;
        $message = $already
            ? "Invoice {$invoiceNumber} for order {$order->order_number} was already generated."
            : "Invoice {$invoiceNumber} generated for order {$order->order_number}.";

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'already_completed' => $already,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'invoice_number' => $invoiceNumber,
                'total' => (string) $order->total,
                'payment_method' => $order->payment_method,
                'invoice_url' => route('restaurant.orders.invoice', $order),
                'print_url' => route('restaurant.orders.invoice', ['order' => $order, 'print' => 1]),
                'pdf_url' => route('restaurant.orders.invoice.pdf', $order),
            ]);
        }

        return redirect()->route('pos.orders.index')->with('status', $message);
    }

    private function confirmedTender(array $data, BigDecimal $total): array
    {
        $method = $data['payment_method'];
        if ($method === 'cash') {
            $received = $data['cash_received'] === null || $data['cash_received'] === ''
                ? $total
                : BigDecimal::of((string) $data['cash_received'])->toScale(2, RoundingMode::HALF_UP);
            if ($received->isLessThan($total)) {
                throw ValidationException::withMessages([
                    'cash_received' => 'Cash received must cover the grand total.',
                ]);
            }
            $change = $received->minus($total)->toScale(2, RoundingMode::HALF_UP);

            return [
                'cash_received' => $received->__toString(),
                'change_amount' => $change->__toString(),
                'upi_reference' => null,
                'card_last_four' => null,
                'card_reference' => null,
                'external_reference' => null,
                'notes' => 'Cash received INR '.$received->__toString().'; change INR '.$change->__toString().'. Operator confirmed cash was received. This is not an automatic cash verification.',
            ];
        }

        if ($method === 'upi') {
            $reference = trim((string) ($data['upi_reference'] ?? ''));
            if ($reference === '') {
                throw ValidationException::withMessages([
                    'upi_reference' => 'Enter the UPI transaction ID or UTR after you have verified the payment.',
                ]);
            }

            return [
                'cash_received' => null,
                'change_amount' => null,
                'upi_reference' => $reference,
                'card_last_four' => null,
                'card_reference' => null,
                'external_reference' => $reference,
                'notes' => 'UPI UTR: '.$reference.'. Operator confirmed the payment was received outside TastyBite. The transaction ID alone is not treated as proof.',
            ];
        }

        $lastFour = trim((string) ($data['card_last_four'] ?? ''));
        $cardReference = trim((string) ($data['card_reference'] ?? ''));
        if (! preg_match('/^\d{4}$/', $lastFour)) {
            throw ValidationException::withMessages([
                'card_last_four' => 'Enter only the last 4 digits of the card.',
            ]);
        }
        if ($cardReference === '') {
            throw ValidationException::withMessages([
                'card_reference' => 'Enter the card terminal or transaction reference.',
            ]);
        }
        $digits = preg_replace('/\D+/', '', $cardReference) ?? '';
        if (strlen($digits) >= 13 && strlen($digits) <= 19) {
            throw ValidationException::withMessages([
                'card_reference' => 'Enter the terminal reference, not the full card number.',
            ]);
        }

        return [
            'cash_received' => null,
            'change_amount' => null,
            'upi_reference' => null,
            'card_last_four' => $lastFour,
            'card_reference' => $cardReference,
            'external_reference' => $cardReference,
            'notes' => 'Card ending '.$lastFour.'; terminal reference: '.$cardReference.'. Operator confirmed the terminal payment. Full card numbers are not stored.',
        ];
    }

    private function rejectSensitiveCardFields(Request $request): void
    {
        foreach (['cvv', 'cvc', 'cid', 'pin', 'card_number', 'pan', 'full_card_number', 'track_data'] as $field) {
            if ($request->exists($field)) {
                throw ValidationException::withMessages([
                    $field => 'Full card numbers, CVV, PIN, and card authentication data are not accepted.',
                ]);
            }
        }
    }

    private function trimPaymentInput(Request $request): void
    {
        $trimmed = [];
        foreach (['upi_reference', 'card_reference', 'card_last_four'] as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $trimmed[$field] = trim($value);
            }
        }
        if ($trimmed !== []) {
            $request->merge($trimmed);
        }
    }

    private function lineInstructions(array $line): ?string
    {
        $instructions = trim((string) ($line['instructions'] ?? ''));

        return $instructions === '' ? null : $instructions;
    }
}
