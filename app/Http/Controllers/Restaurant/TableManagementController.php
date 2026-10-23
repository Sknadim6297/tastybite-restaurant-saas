<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantFloor;
use App\Models\RestaurantPosOrder;
use App\Models\RestaurantSection;
use App\Models\RestaurantTable;
use App\Models\RestaurantTableGroup;
use App\Models\RestaurantTableGroupMember;
use App\Models\RestaurantTableOccupancy;
use App\Models\RestaurantTableReservation;
use App\Models\RestaurantTableStatusHistory;
use App\Models\RestaurantTableTransfer;
use App\Services\RestaurantAccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TableManagementController extends Controller
{
    private function owner()
    {
        return app(RestaurantAccessContext::class)->owner();
    }

    private function adminActorId(): ?int
    {
        $admin = request()->attributes->get('restaurant_admin_access_admin');

        return is_object($admin) && method_exists($admin, 'getKey') ? (int) $admin->getKey() : null;
    }

    private function tables(int $restaurantId)
    {
        return RestaurantTable::query()->where('restaurant_id', $restaurantId)->whereNull('archived_at');
    }

    public function overview(Request $request): View
    {
        $owner = $this->owner();
        $tab = $request->query('tab', 'live');
        if (! in_array($tab, ['live', 'operations', 'cleaning', 'sessions'], true)) {
            $tab = 'live';
        }
        $localNow = CarbonImmutable::now(config('app.timezone'));
        $todayStart = $localNow->startOfDay()->utc();
        $todayEnd = $localNow->endOfDay()->utc();
        $tables = $this->tables($owner->restaurant_id)->with([
            'floor', 'section', 'activeOccupancy.order', 'activeOccupancy.group', 'statusHistory',
        ])->withCount(['reservations as upcoming_reservations_count' => function ($query) {
        $query->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', CarbonImmutable::now(config('app.timezone'))->utc());
        }]);
        if ($tab === 'live' && $request->filled('floor')) {
            $tables->where('floor_id', $request->integer('floor'));
        }

        $all = $tables->orderBy('floor_id')->orderBy('sort_order')->orderBy('table_number')->get();
        $restaurantId = (int) $owner->restaurant_id;
        $activeDineInOrders = RestaurantPosOrder::query()->where('restaurant_id', $restaurantId)
            ->where('order_type', 'dine_in')->where('status', 'completed')
            ->whereIn('fulfillment_status', ['pending', 'confirmed', 'preparing', 'ready', 'served'])->count();

        return view('restaurant.tables.overview', [
            'owner' => $owner,
            'tab' => $tab,
            'floors' => RestaurantFloor::query()->where('restaurant_id', $restaurantId)->whereNull('archived_at')->orderBy('display_order')->get(),
            'tables' => $all,
            'groups' => RestaurantTableGroup::where('restaurant_id', $restaurantId)->whereNull('ended_at')->with('members.table')->get(),
            'transfers' => RestaurantTableTransfer::where('restaurant_id', $restaurantId)->with(['source', 'destination'])->latest('transferred_at')->limit(15)->get(),
            'metrics' => [
                'total' => $all->count(),
                'available' => $all->where('status', 'available')->count(),
                'occupied' => $all->where('status', 'occupied')->count(),
                'cleaning' => $all->where('status', 'cleaning')->count(),
                'out_of_service' => $all->where('status', 'out_of_service')->count(),
                'guests' => $all->sum(fn ($table) => $table->activeOccupancy?->guest_count ?? 0),
                'orders' => $activeDineInOrders,
                'reservations' => RestaurantTableReservation::query()->where('restaurant_id', $restaurantId)
                    ->whereBetween('starts_at', [$todayStart, $todayEnd])->whereIn('status', ['pending', 'confirmed'])->count(),
                'upcoming' => RestaurantTableReservation::query()->where('restaurant_id', $restaurantId)
                    ->where('starts_at', '>', CarbonImmutable::now(config('app.timezone'))->utc())->whereIn('status', ['pending', 'confirmed'])->count(),
            ],
        ]);
    }

    public function statusFeed(Request $request): JsonResponse
    {
        $owner = $this->owner();
        $query = $this->tables($owner->restaurant_id);
        if ($request->filled('floor')) {
            $query->where('floor_id', $request->integer('floor'));
        }

        return response()->json([
            'tables' => $query->orderBy('id')->get(['id', 'status', 'updated_at']),
        ])->header('Cache-Control', 'no-store');
    }

    public function index(Request $request): RedirectResponse
    {
        return redirect()->route('restaurant.tables.manage', ['tab' => 'tables'] + $request->query());
    }

    public function manage(Request $request): View
    {
        $owner = $this->owner();
        $restaurantId = (int) $owner->restaurant_id;
        $tab = $request->query('tab', 'tables');
        if (! in_array($tab, ['tables', 'floors', 'sections'], true)) {
            $tab = 'tables';
        }
        $query = $this->tables($restaurantId)->with(['floor', 'section', 'activeOccupancy.order']);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'floor_search' => ['nullable', 'string', 'max:100'],
            'section_search' => ['nullable', 'string', 'max:100'],
            'floor' => ['nullable', 'integer'],
            'section' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['available', 'occupied', 'cleaning', 'out_of_service'])],
            'active' => ['nullable', Rule::in(['1', '0'])],
            'capacity' => ['nullable', 'integer', 'between:1,1000'],
        ]);
        if (! empty($filters['search'])) {
            $term = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('table_number', 'like', $term)->orWhere('name', 'like', $term));
        }
        foreach (['floor' => 'floor_id', 'section' => 'section_id', 'status' => 'status', 'capacity' => 'capacity'] as $filter => $column) {
            if (isset($filters[$filter]) && $filters[$filter] !== '') {
                $query->where($column, $filters[$filter]);
            }
        }
        if (isset($filters['active'])) {
            $query->where('is_active', (bool) $filters['active']);
        }
        $floors = RestaurantFloor::where('restaurant_id', $restaurantId)->whereNull('archived_at')
            ->withCount([
                'tables as tables_count' => fn ($tableQuery) => $tableQuery->whereNull('archived_at'),
                'tables as available_count' => fn ($tableQuery) => $tableQuery->whereNull('archived_at')->where('status', 'available'),
                'tables as occupied_count' => fn ($tableQuery) => $tableQuery->whereNull('archived_at')->where('status', 'occupied'),
            ])->withSum(['tables as capacity_total' => fn ($tableQuery) => $tableQuery->whereNull('archived_at')], 'capacity')
            ->orderBy('display_order')->orderBy('name')->get();
        $sectionsQuery = RestaurantSection::where('restaurant_id', $restaurantId)->whereNull('archived_at')
            ->with('floor')->withCount([
                'tables as tables_count' => fn ($tableQuery) => $tableQuery->whereNull('archived_at'),
                'tables as available_count' => fn ($tableQuery) => $tableQuery->whereNull('archived_at')->where('status', 'available'),
                'tables as occupied_count' => fn ($tableQuery) => $tableQuery->whereNull('archived_at')->where('status', 'occupied'),
            ])->withSum(['tables as capacity_total' => fn ($tableQuery) => $tableQuery->whereNull('archived_at')], 'capacity');
        if ($request->filled('floor_search')) {
            $floors = $floors->filter(fn ($floor) => str_contains(mb_strtolower($floor->name.' '.$floor->code), mb_strtolower($request->string('floor_search')->toString())))->values();
        }
        if ($request->filled('section_search')) {
            $term = '%'.addcslashes($request->string('section_search')->toString(), '%_\\').'%';
            $sectionsQuery->where(fn ($builder) => $builder->where('name', 'like', $term)->orWhere('code', 'like', $term));
        }
        $sections = $sectionsQuery->orderBy('floor_id')->orderBy('sort_order')->orderBy('name')->get();

        return view('restaurant.tables.manage', [
            'owner' => $owner,
            'tab' => $tab,
            'tables' => $query->orderBy('floor_id')->orderBy('sort_order')->orderBy('table_number')->paginate(20)->withQueryString(),
            'floors' => $floors,
            'floorOptions' => RestaurantFloor::where('restaurant_id', $restaurantId)->whereNull('archived_at')->where('is_active', true)->orderBy('display_order')->orderBy('name')->get(),
            'sections' => $sections,
            'filters' => $filters,
            'floorCount' => RestaurantFloor::where('restaurant_id', $restaurantId)->whereNull('archived_at')->count(),
            'sectionCount' => RestaurantSection::where('restaurant_id', $restaurantId)->whereNull('archived_at')->count(),
            'tableCount' => $this->tables($restaurantId)->count(),
            'totalCapacity' => $this->tables($restaurantId)->sum('capacity'),
        ]);
    }

    public function show(int $table): View
    {
        $owner = $this->owner();
        $record = $this->tables($owner->restaurant_id)->with([
            'floor', 'section', 'activeOccupancy.order', 'statusHistory',
            'occupancies' => fn ($query) => $query->with('order')->latest('started_at')->limit(20),
            'reservations' => fn ($query) => $query->where('starts_at', '>=', CarbonImmutable::now(config('app.timezone'))->utc())->whereIn('status', ['pending', 'confirmed'])->orderBy('starts_at'),
        ])->findOrFail($table);

        return view('restaurant.tables.show', [
            'owner' => $owner,
            'table' => $record,
            'recentOrders' => RestaurantPosOrder::where('restaurant_id', $owner->restaurant_id)->where('table_id', $record->id)->latest()->limit(10)->get(),
            'transfers' => RestaurantTableTransfer::where('restaurant_id', $owner->restaurant_id)
                ->where(fn ($query) => $query->where('source_table_id', $record->id)->orWhere('destination_table_id', $record->id))
                ->with(['source', 'destination'])->latest('transferred_at')->limit(10)->get(),
        ]);
    }

    public function floors(Request $request): RedirectResponse
    {
        return redirect()->route('restaurant.tables.manage', ['tab' => 'floors'] + $request->query());
    }

    public function storeFloor(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:32', Rule::unique('restaurant_floors')->where('restaurant_id', $owner->restaurant_id)],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        RestaurantFloor::create($data + ['restaurant_id' => $owner->restaurant_id]);

        return back()->with('status', 'Floor created.');
    }

    public function updateFloor(Request $request, int $floor): RedirectResponse
    {
        $owner = $this->owner();
        $record = RestaurantFloor::where('restaurant_id', $owner->restaurant_id)->whereNull('archived_at')->findOrFail($floor);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:32', Rule::unique('restaurant_floors')->where('restaurant_id', $owner->restaurant_id)->ignore($record->id)],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (! $request->boolean('is_active') && $record->is_active) {
            abort_if($record->tables()->whereNull('archived_at')->where('status', 'occupied')->exists(), 409, 'Resolve active table sessions before deactivating this floor.');
            abort_if(RestaurantTableReservation::where('restaurant_id', $owner->restaurant_id)->where('floor_id', $record->id)->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', CarbonImmutable::now(config('app.timezone'))->utc())->exists(), 409, 'Reschedule upcoming reservations before deactivating this floor.');
        }
        $record->update($data + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Floor updated.');
    }

    public function archiveFloor(int $floor): RedirectResponse
    {
        $owner = $this->owner();
        $record = RestaurantFloor::where('restaurant_id', $owner->restaurant_id)->whereNull('archived_at')->findOrFail($floor);
        abort_if($record->tables()->whereNull('archived_at')->where(fn ($query) => $query->where('status', 'occupied')->orWhereHas('occupancies', fn ($occupancy) => $occupancy->whereNull('ended_at')))->exists(), 409, 'A floor with occupied tables cannot be archived.');
        abort_if(RestaurantTableReservation::where('restaurant_id', $owner->restaurant_id)->where('floor_id', $record->id)->where(fn ($query) => $query->where('status', 'seated')->orWhere(fn ($active) => $active->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', CarbonImmutable::now(config('app.timezone'))->utc())))->exists(), 409, 'A floor with upcoming reservations cannot be archived.');
        $record->update(['archived_at' => now(), 'is_active' => false]);

        return back()->with('status', 'Floor archived; history retained.');
    }

    public function storeSection(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'floor_id' => ['required', 'integer', Rule::exists('restaurant_floors', 'id')->where('restaurant_id', $owner->restaurant_id)->whereNull('archived_at')->where('is_active', true)],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:32'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $duplicate = RestaurantSection::where('restaurant_id', $owner->restaurant_id)->where('floor_id', $data['floor_id'])->where('code', $data['code'])->exists();
        abort_if($duplicate, 422, 'Section code is already used on this floor.');
        RestaurantSection::create($data + ['restaurant_id' => $owner->restaurant_id]);

        return back()->with('status', 'Section created.');
    }

    public function updateSection(Request $request, int $section): RedirectResponse
    {
        $owner = $this->owner();
        $record = RestaurantSection::where('restaurant_id', $owner->restaurant_id)->whereNull('archived_at')->findOrFail($section);
        $data = $request->validate([
            'floor_id' => ['required', 'integer', Rule::exists('restaurant_floors', 'id')->where('restaurant_id', $owner->restaurant_id)->whereNull('archived_at')->where('is_active', true)],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:32'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        abort_if(RestaurantSection::where('restaurant_id', $owner->restaurant_id)->where('floor_id', $data['floor_id'])->where('code', $data['code'])->whereKeyNot($record->id)->exists(), 422, 'Section code is already used on this floor.');
        DB::transaction(function () use ($record, $data, $owner, $request): void {
            if ((int) $record->floor_id !== (int) $data['floor_id']) {
                abort_if($record->tables()->whereNull('archived_at')->where('status', 'occupied')->exists(), 409, 'Move occupied tables through Table Transfer before moving this section.');
                abort_if(RestaurantTableReservation::where('restaurant_id', $owner->restaurant_id)->where('section_id', $record->id)->where(fn ($query) => $query->where('status', 'seated')->orWhere(fn ($active) => $active->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', CarbonImmutable::now(config('app.timezone'))->utc())))->exists(), 409, 'Reschedule upcoming reservations before moving this section.');
                $record->tables()->whereNull('archived_at')->update(['floor_id' => $data['floor_id']]);
            }
            if (! $request->boolean('is_active') && $record->is_active) {
                abort_if($record->tables()->whereNull('archived_at')->where('status', 'occupied')->exists(), 409, 'Resolve active table sessions before deactivating this section.');
                abort_if(RestaurantTableReservation::where('restaurant_id', $owner->restaurant_id)->where('section_id', $record->id)->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', CarbonImmutable::now(config('app.timezone'))->utc())->exists(), 409, 'Reschedule upcoming reservations before deactivating this section.');
            }
            $record->update($data + ['is_active' => $request->boolean('is_active')]);
        });

        return back()->with('status', 'Section updated.');
    }

    public function archiveSection(int $section): RedirectResponse
    {
        $owner = $this->owner();
        $record = RestaurantSection::where('restaurant_id', $owner->restaurant_id)->whereNull('archived_at')->findOrFail($section);
        abort_if($record->tables()->whereNull('archived_at')->where(fn ($query) => $query->whereIn('status', ['occupied', 'cleaning'])->orWhereHas('occupancies', fn ($occupancy) => $occupancy->whereNull('ended_at')))->exists(), 409, 'Move or resolve this section’s occupied/cleaning tables first.');
        abort_if(RestaurantTableReservation::where('restaurant_id', $owner->restaurant_id)->where('section_id', $record->id)->where(fn ($query) => $query->where('status', 'seated')->orWhere(fn ($active) => $active->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', CarbonImmutable::now(config('app.timezone'))->utc())))->exists(), 409, 'A section with upcoming reservations cannot be archived.');
        $record->update(['archived_at' => now(), 'is_active' => false]);

        return back()->with('status', 'Section archived; history retained.');
    }

    public function storeTable(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $this->validateTable($request, (int) $owner->restaurant_id);
        RestaurantTable::create($data + ['restaurant_id' => $owner->restaurant_id]);

        return back()->with('status', 'Table created.');
    }

    public function updateTable(Request $request, int $table): RedirectResponse
    {
        $owner = $this->owner();
        $data = $this->validateTable($request, (int) $owner->restaurant_id, $table);
        DB::transaction(function () use ($owner, $table, $data): void {
            $record = $this->tables($owner->restaurant_id)->lockForUpdate()->findOrFail($table);
            abort_if($record->occupancies()->whereNull('ended_at')->exists() && ($record->floor_id !== (int) $data['floor_id'] || $record->section_id !== ($data['section_id'] ?? null)), 409, 'Move an occupied table only through Table Transfer.');
            $record->update($data);
        });

        return back()->with('status', 'Table updated.');
    }

    private function validateTable(Request $request, int $restaurantId, ?int $tableId = null): array
    {
        $data = $request->validate([
            'floor_id' => ['required', 'integer', Rule::exists('restaurant_floors', 'id')->where('restaurant_id', $restaurantId)->whereNull('archived_at')->where('is_active', true)],
            'section_id' => ['nullable', 'integer', Rule::exists('restaurant_sections', 'id')->where('restaurant_id', $restaurantId)->whereNull('archived_at')->where('is_active', true)],
            'table_number' => ['required', 'string', 'max:32', Rule::unique('restaurant_tables')->where('restaurant_id', $restaurantId)->ignore($tableId)],
            'name' => ['nullable', 'string', 'max:100'],
            'capacity' => ['required', 'integer', 'between:1,1000'],
            'shape' => ['required', Rule::in(['round', 'square', 'rectangle'])],
            'type' => ['required', Rule::in(['standard', 'family', 'vip', 'outdoor', 'banquet'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if (! empty($data['section_id'])) {
            abort_unless(RestaurantSection::where('restaurant_id', $restaurantId)->where('floor_id', $data['floor_id'])->whereKey($data['section_id'])->exists(), 422, 'Select a section on the chosen floor.');
        }
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    public function bulkTables(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'floor_id' => ['required', 'integer', Rule::exists('restaurant_floors', 'id')->where('restaurant_id', $owner->restaurant_id)->whereNull('archived_at')],
            'section_id' => ['nullable', 'integer', Rule::exists('restaurant_sections', 'id')->where('restaurant_id', $owner->restaurant_id)->whereNull('archived_at')],
            'prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
            'start' => ['required', 'integer', 'min:0', 'max:999999'],
            'count' => ['required', 'integer', 'between:1,100'],
            'capacity' => ['required', 'integer', 'between:1,1000'],
        ]);
        if (! empty($data['section_id'])) {
            abort_unless(RestaurantSection::where('restaurant_id', $owner->restaurant_id)->where('floor_id', $data['floor_id'])->whereNull('archived_at')->where('is_active', true)->whereKey($data['section_id'])->exists(), 422, 'Select an active section on the selected floor.');
        }
        $digits = max(2, strlen((string) $data['start']));
        $ids = collect(range($data['start'], $data['start'] + $data['count'] - 1))
            ->map(fn ($number) => $data['prefix'].str_pad((string) $number, $digits, '0', STR_PAD_LEFT));
        abort_if($ids->unique()->count() !== $ids->count(), 422, 'Generated table identifiers are not unique.');
        $existing = RestaurantTable::query()->where('restaurant_id', $owner->restaurant_id)->whereIn('table_number', $ids)->exists();
        abort_if($existing, 422, 'One or more generated table identifiers already exist.');
        DB::transaction(function () use ($ids, $data, $owner): void {
            foreach ($ids as $identifier) {
                RestaurantTable::create([
                    'restaurant_id' => $owner->restaurant_id, 'floor_id' => $data['floor_id'],
                    'section_id' => $data['section_id'] ?? null, 'table_number' => $identifier,
                    'capacity' => $data['capacity'], 'shape' => 'rectangle', 'type' => 'standard',
                    'is_active' => true, 'status' => 'available',
                ]);
            }
        });

        return back()->with('status', $ids->count().' tables created atomically.');
    }

    public function archiveTable(int $table): RedirectResponse
    {
        $owner = $this->owner();
        DB::transaction(function () use ($owner, $table): void {
            $record = $this->tables($owner->restaurant_id)->lockForUpdate()->findOrFail($table);
            abort_if($record->status === 'occupied' || $record->occupancies()->whereNull('ended_at')->exists(), 409, 'An occupied table cannot be archived.');
            abort_if(RestaurantTableReservation::where('restaurant_id', $owner->restaurant_id)->where('table_id', $record->id)->where(fn ($query) => $query->where('status', 'seated')->orWhere(fn ($active) => $active->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', CarbonImmutable::now(config('app.timezone'))->utc())))->exists(), 409, 'A table with upcoming reservations cannot be archived.');
            $record->update(['archived_at' => now(), 'is_active' => false]);
        });

        return back()->with('status', 'Table archived; historical order references are preserved.');
    }

    public function reservations(Request $request): View
    {
        $owner = $this->owner();
        $viewMode = $request->input('view', 'list');
        $calendarInput = $request->validate([
            'period' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'date' => ['nullable', 'date'],
        ]);
        $query = RestaurantTableReservation::query()->where('restaurant_id', $owner->restaurant_id)
            ->with(['floor', 'section', 'table']);
        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('customer_name', 'like', '%'.addcslashes($request->string('search')->toString(), '%_\\').'%')
                ->orWhere('phone', 'like', '%'.addcslashes($request->string('search')->toString(), '%_\\').'%')
                ->orWhere('reference', 'like', '%'.addcslashes($request->string('search')->toString(), '%_\\').'%'));
        }
        $calendarPeriod = $calendarInput['period'] ?? 'day';
        $calendarDate = $calendarInput['date'] ?? now(config('app.timezone'))->toDateString();
        if ($calendarPeriod) {
            $anchor = CarbonImmutable::parse($calendarDate, config('app.timezone'));
            [$calendarStart, $calendarEnd] = match ($calendarPeriod) {
                'week' => [$anchor->startOfWeek(), $anchor->endOfWeek()],
                'month' => [$anchor->startOfMonth(), $anchor->endOfMonth()],
                default => [$anchor->startOfDay(), $anchor->endOfDay()],
            };
            $query->whereBetween('starts_at', [$calendarStart->utc(), $calendarEnd->utc()]);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('floor')) {
            $query->where('floor_id', $request->integer('floor'));
        }
        if ($request->filled('table')) {
            $query->where('table_id', $request->integer('table'));
        }

        $timezone = config('app.timezone');
        $todayStart = CarbonImmutable::now($timezone)->startOfDay()->utc();
        $todayEnd = CarbonImmutable::now($timezone)->endOfDay()->utc();
        $nowUtc = CarbonImmutable::now('UTC');

        $kpis = [
            'today' => RestaurantTableReservation::query()->where('restaurant_id', $owner->restaurant_id)->whereBetween('starts_at', [$todayStart, $todayEnd])->count(),
            'confirmed' => RestaurantTableReservation::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'confirmed')->count(),
            'upcoming' => RestaurantTableReservation::query()->where('restaurant_id', $owner->restaurant_id)->where('starts_at', '>=', $nowUtc)->whereIn('status', ['pending', 'confirmed'])->count(),
            'seated' => RestaurantTableReservation::query()->where('restaurant_id', $owner->restaurant_id)->where('status', 'seated')->count(),
        ];
        $hasAnyReservations = RestaurantTableReservation::query()->where('restaurant_id', $owner->restaurant_id)->exists();

        return view('restaurant.tables.reservations', [
            'owner' => $owner,
            'reservations' => $query->orderBy('starts_at')->paginate(20)->withQueryString(),
            'floors' => RestaurantFloor::where('restaurant_id', $owner->restaurant_id)->whereNull('archived_at')->orderBy('display_order')->get(),
            'tables' => $this->tables($owner->restaurant_id)->where('is_active', true)->with(['floor', 'section'])->orderBy('table_number')->get(),
            'calendarPeriod' => $calendarPeriod,
            'calendarDate' => $calendarDate,
            'viewMode' => in_array($viewMode, ['list', 'calendar'], true) ? $viewMode : 'list',
            'kpis' => $kpis,
            'hasAnyReservations' => $hasAnyReservations,
        ]);
    }

    public function storeReservation(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $this->validateReservation($request, (int) $owner->restaurant_id);
        $table = isset($data['table_id']) ? $this->tables($owner->restaurant_id)->where('is_active', true)->where('status', '!=', 'out_of_service')->findOrFail($data['table_id']) : null;
        $data['reference'] = 'RSV-'.strtoupper(Str::random(8));
        $data['created_by'] = $this->adminActorId() ? null : $owner->id;
        $data['created_by_admin_id'] = $this->adminActorId();
        $data['restaurant_id'] = $owner->restaurant_id;
        DB::transaction(function () use ($owner, $table, &$data): void {
            if ($table) {
                $table = $this->tables($owner->restaurant_id)->where('is_active', true)->lockForUpdate()->findOrFail($table->id);
                abort_if($data['party_size'] > $table->capacity, 422, 'Party size exceeds table capacity.');
                $data['floor_id'] = $table->floor_id;
                $data['section_id'] = $table->section_id;
                $this->assertReservationAvailable((int) $owner->restaurant_id, $table->id, $data['starts_at'], $data['duration_minutes']);
            }
            RestaurantTableReservation::create($data);
        });

        return back()->with('status', 'Reservation created.');
    }

    public function updateReservation(Request $request, int $reservation): RedirectResponse
    {
        $owner = $this->owner();
        $record = RestaurantTableReservation::where('restaurant_id', $owner->restaurant_id)->findOrFail($reservation);
        abort_if(in_array($record->status, ['completed', 'cancelled', 'no_show'], true), 409, 'This reservation is closed.');
        $data = $this->validateReservation($request, (int) $owner->restaurant_id);
        $table = isset($data['table_id']) ? $this->tables($owner->restaurant_id)->where('is_active', true)->where('status', '!=', 'out_of_service')->findOrFail($data['table_id']) : null;
        DB::transaction(function () use ($owner, $record, $table, &$data): void {
            if ($table) {
                $table = $this->tables($owner->restaurant_id)->where('is_active', true)->lockForUpdate()->findOrFail($table->id);
                abort_if($data['party_size'] > $table->capacity, 422, 'Party size exceeds table capacity.');
                $data['floor_id'] = $table->floor_id;
                $data['section_id'] = $table->section_id;
                $this->assertReservationAvailable((int) $owner->restaurant_id, $table->id, $data['starts_at'], $data['duration_minutes'], $record->id);
            }
            $record->update($data);
        });

        return back()->with('status', 'Reservation updated.');
    }

    private function validateReservation(Request $request, int $restaurantId): array
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'starts_at' => ['required', 'date', 'after_or_equal:now'],
            'duration_minutes' => ['required', 'integer', 'between:15,1440'],
            'party_size' => ['required', 'integer', 'between:1,1000'],
            'floor_id' => ['nullable', 'integer', Rule::exists('restaurant_floors', 'id')->where('restaurant_id', $restaurantId)],
            'section_id' => ['nullable', 'integer', Rule::exists('restaurant_sections', 'id')->where('restaurant_id', $restaurantId)],
            'table_id' => ['nullable', 'integer', Rule::exists('restaurant_tables', 'id')->where('restaurant_id', $restaurantId)],
            'special_requests' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['starts_at'] = CarbonImmutable::parse($data['starts_at'], config('app.timezone'))->utc()->format('Y-m-d H:i:s');
        if (! empty($data['section_id']) && ! empty($data['floor_id'])) {
            abort_unless(RestaurantSection::where('restaurant_id', $restaurantId)->where('floor_id', $data['floor_id'])->whereKey($data['section_id'])->exists(), 422, 'The selected section is not on the selected floor.');
        }

        return $data;
    }

    private function assertReservationAvailable(int $restaurantId, int $tableId, string $startsAt, int $duration, ?int $ignore = null): void
    {
        $start = CarbonImmutable::parse($startsAt, 'UTC');
        $end = $start->addMinutes($duration);
        $query = RestaurantTableReservation::query()->where('restaurant_id', $restaurantId)->where('table_id', $tableId)
            ->whereIn('status', ['pending', 'confirmed', 'seated'])->where('starts_at', '<', $end);
        if ($ignore) {
            $query->whereKeyNot($ignore);
        }
        $conflict = $query->get()
            ->contains(function (RestaurantTableReservation $existing) use ($start, $end): bool {
                $existingStart = $existing->starts_at->utc();
                $existingEnd = $existingStart->addMinutes($existing->duration_minutes);

                return $existingStart->lt($end) && $existingEnd->gt($start);
            });
        abort_if($conflict, 409, 'This table already has an overlapping reservation.');
    }

    public function reservationStatus(Request $request, int $reservation): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate(['status' => ['required', Rule::in(['pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show'])]]);
        $record = RestaurantTableReservation::where('restaurant_id', $owner->restaurant_id)->findOrFail($reservation);
        $allowed = [
            'pending' => ['confirmed', 'cancelled', 'no_show', 'seated'],
            'confirmed' => ['cancelled', 'no_show', 'seated'],
            'seated' => ['completed'],
        ];
        abort_unless(in_array($data['status'], $allowed[$record->status] ?? [], true), 409, 'Invalid reservation status transition.');
        DB::transaction(function () use ($owner, $record, $data): void {
            $record = RestaurantTableReservation::where('restaurant_id', $owner->restaurant_id)->lockForUpdate()->findOrFail($record->id);
            if ($data['status'] === 'seated') {
                abort_unless($record->table_id, 409, 'Assign a table before seating this reservation.');
                $table = $this->tables($owner->restaurant_id)->lockForUpdate()->findOrFail($record->table_id);
                abort_unless($table->status === 'available' && $table->is_active, 409, 'The assigned table is not available.');
                abort_if($record->party_size > $table->capacity, 422, 'Party size exceeds table capacity.');
                RestaurantTableOccupancy::create([
                    'restaurant_id' => $owner->restaurant_id, 'table_id' => $table->id,
                    'started_by' => $this->adminActorId() ? null : $owner->id,
                    'started_by_admin_id' => $this->adminActorId(),
                    'guest_count' => $record->party_size, 'started_at' => now(),
                ]);
                $this->recordStatus($table, $owner->id, 'occupied', 'Reservation '.$record->reference.' seated');
            }
            if ($data['status'] === 'completed' && $record->table_id) {
                $table = $this->tables($owner->restaurant_id)->lockForUpdate()->findOrFail($record->table_id);
                $occupancy = $table->occupancies()->whereNull('ended_at')->whereNull('pos_order_id')->lockForUpdate()->first();
                if ($occupancy) {
                    $occupancy->update([
                        'ended_at' => now(),
                        'ended_by' => $this->adminActorId() ? null : $owner->id,
                        'ended_by_admin_id' => $this->adminActorId(),
                    ]);
                    $this->recordStatus($table, $owner->id, 'cleaning', 'Reservation completed');
                }
            }
            $record->update(['status' => $data['status']]);
        });

        return back()->with('status', 'Reservation status updated.');
    }

    public function operations(Request $request): RedirectResponse
    {
        return redirect()->route('restaurant.tables.overview', ['tab' => 'operations'] + $request->query());
    }

    public function status(Request $request, int $table): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'status' => ['required', Rule::in(['available', 'cleaning', 'out_of_service'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        DB::transaction(function () use ($owner, $table, $data): void {
            $record = $this->tables($owner->restaurant_id)->lockForUpdate()->findOrFail($table);
            $active = $record->occupancies()->whereNull('ended_at')->lockForUpdate()->first();
            abort_if($active && $data['status'] !== 'occupied', 409, 'Resolve or complete the active table session before releasing this table.');
            if (in_array($data['status'], ['cleaning', 'out_of_service'], true)) {
                abort_if(RestaurantTableReservation::where('restaurant_id', $owner->restaurant_id)->where('table_id', $record->id)->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', CarbonImmutable::now(config('app.timezone'))->utc())->exists(), 409, 'Resolve upcoming reservations before changing table availability.');
            }
            $allowed = [
                'available' => ['cleaning', 'out_of_service'],
                'cleaning' => ['available', 'out_of_service'],
                'out_of_service' => ['available'],
                'occupied' => ['cleaning'],
            ];
            abort_unless(in_array($data['status'], $allowed[$record->status] ?? [], true), 409, 'Invalid table status transition.');
            $this->recordStatus($record, $owner->id, $data['status'], $data['reason'] ?? null);
        });

        return back()->with('status', 'Table status updated.');
    }

    private function recordStatus(RestaurantTable $table, int $actorId, string $new, ?string $reason): void
    {
        RestaurantTableStatusHistory::create([
            'restaurant_id' => $table->restaurant_id, 'table_id' => $table->id,
            'actor_id' => $this->adminActorId() ? null : $actorId,
            'actor_admin_id' => $this->adminActorId(),
            'actor_type' => $this->adminActorId() ? 'super_admin' : 'restaurant_user',
            'from_status' => $table->status, 'to_status' => $new,
            'reason' => $reason, 'changed_at' => now(),
        ]);
        $table->update(['status' => $new]);
    }

    public function transfer(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'source_table_id' => ['required', 'integer'],
            'destination_table_id' => ['required', 'integer', 'different:source_table_id'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        DB::transaction(function () use ($owner, $data): void {
            $ids = [(int) $data['source_table_id'], (int) $data['destination_table_id']];
            sort($ids);
            $locked = $this->tables($owner->restaurant_id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($locked->count() === 2, 404);
            $source = $locked[$data['source_table_id']];
            $destination = $locked[$data['destination_table_id']];
            abort_unless($source->status === 'occupied', 409, 'The source table is not occupied.');
            abort_unless($destination->status === 'available' && $destination->is_active, 409, 'The destination table is unavailable.');
            $occupancy = $source->occupancies()->whereNull('ended_at')->lockForUpdate()->firstOrFail();
            abort_if($occupancy->group_id, 409, 'Complete and split the physical table group before transferring one of its tables.');
            abort_if($occupancy->guest_count > $destination->capacity, 422, 'Destination capacity is below the current guest count.');
            abort_if(RestaurantTableReservation::where('restaurant_id', $owner->restaurant_id)->where('table_id', $destination->id)->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', CarbonImmutable::now(config('app.timezone'))->utc())->exists(), 409, 'The destination has an upcoming reservation.');
            $occupancy->update([
                'ended_at' => now(),
                'ended_by' => $this->adminActorId() ? null : $owner->id,
                'ended_by_admin_id' => $this->adminActorId(),
            ]);
            RestaurantTableOccupancy::create([
                'restaurant_id' => $owner->restaurant_id, 'table_id' => $destination->id,
                'pos_order_id' => $occupancy->pos_order_id,
                'started_by' => $this->adminActorId() ? null : $owner->id,
                'started_by_admin_id' => $this->adminActorId(),
                'guest_count' => $occupancy->guest_count, 'started_at' => now(),
            ]);
            if ($occupancy->pos_order_id) {
                RestaurantPosOrder::where('restaurant_id', $owner->restaurant_id)->whereKey($occupancy->pos_order_id)
                    ->update(['table_id' => $destination->id, 'floor_id' => $destination->floor_id, 'section_id' => $destination->section_id, 'table_number' => $destination->table_number]);
            }
            $this->recordStatus($source, $owner->id, 'cleaning', 'Table transfer: '.$data['reason']);
            $this->recordStatus($destination, $owner->id, 'occupied', 'Table transfer: '.$data['reason']);
            RestaurantTableTransfer::create([
                'restaurant_id' => $owner->restaurant_id, 'source_table_id' => $source->id,
                'destination_table_id' => $destination->id, 'pos_order_id' => $occupancy->pos_order_id,
                'actor_id' => $this->adminActorId() ? null : $owner->id,
                'actor_admin_id' => $this->adminActorId(),
                'reason' => $data['reason'], 'transferred_at' => now(),
            ]);
        });

        return back()->with('status', 'Table session transferred; source table is ready for cleaning.');
    }

    public function merge(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate([
            'table_ids' => ['required', 'array', 'min:2', 'max:10'],
            'table_ids.*' => ['required', 'integer', 'distinct'],
            'guest_count' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        DB::transaction(function () use ($owner, $data): void {
            $tables = $this->tables($owner->restaurant_id)->whereIn('id', $data['table_ids'])->orderBy('id')->lockForUpdate()->get();
            abort_if($tables->count() !== count($data['table_ids']), 404);
            abort_if($tables->contains(fn ($table) => ! $table->is_active || $table->status !== 'available'), 409, 'Only active, available tables can be grouped.');
            abort_if((int) $data['guest_count'] > $tables->sum('capacity'), 422, 'Party size exceeds the combined table capacity.');
            abort_if(RestaurantTableReservation::where('restaurant_id', $owner->restaurant_id)->whereIn('table_id', $tables->pluck('id'))->whereIn('status', ['pending', 'confirmed'])->where('starts_at', '>=', CarbonImmutable::now(config('app.timezone'))->utc())->exists(), 409, 'One or more tables have an upcoming reservation.');
            $activeGroup = RestaurantTableGroup::where('restaurant_id', $owner->restaurant_id)->whereNull('ended_at')
                ->whereHas('members', fn ($query) => $query->whereIn('table_id', $data['table_ids']))->exists();
            abort_if($activeGroup, 409, 'One or more tables already belong to an active group.');
            $group = RestaurantTableGroup::create([
                'restaurant_id' => $owner->restaurant_id,
                'created_by' => $this->adminActorId() ? null : $owner->id,
                'created_by_admin_id' => $this->adminActorId(),
                'guest_count' => $data['guest_count'],
            ]);
            foreach ($tables->values() as $index => $table) {
                RestaurantTableGroupMember::create(['group_id' => $group->id, 'table_id' => $table->id]);
                RestaurantTableOccupancy::create([
                    'restaurant_id' => $owner->restaurant_id,
                    'table_id' => $table->id,
                    'group_id' => $group->id,
                    'started_by' => $this->adminActorId() ? null : $owner->id,
                    'started_by_admin_id' => $this->adminActorId(),
                    'guest_count' => $index === 0 ? $data['guest_count'] : 0,
                    'started_at' => now(),
                ]);
                $this->recordStatus($table, $owner->id, 'occupied', 'Physical table group #'.$group->id);
            }
        });

        return back()->with('status', 'Physical table group created. Financial orders remain separate.');
    }

    public function split(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $data = $request->validate(['group_id' => ['required', 'integer']]);
        DB::transaction(function () use ($owner, $data): void {
            $group = RestaurantTableGroup::where('restaurant_id', $owner->restaurant_id)->whereNull('ended_at')->lockForUpdate()->findOrFail($data['group_id']);
            $members = $group->members()->with('table.activeOccupancy')->get();
            foreach ($members as $member) {
                $table = $member->table;
                $occupancy = $table->occupancies()->where('group_id', $group->id)->whereNull('ended_at')->lockForUpdate()->first();
                abort_if($occupancy?->pos_order_id, 409, 'Complete the POS order before splitting this group.');
                if ($occupancy) {
                    $occupancy->update([
                        'ended_at' => now(),
                        'ended_by' => $this->adminActorId() ? null : $owner->id,
                        'ended_by_admin_id' => $this->adminActorId(),
                    ]);
                }
                if ($table->status === 'occupied') {
                    $this->recordStatus($table, $owner->id, 'cleaning', 'Physical table group split');
                }
            }
            $group->update(['ended_at' => now()]);
        });

        return back()->with('status', 'Physical table group split.');
    }

    public function reports(Request $request): View
    {
        $owner = $this->owner();
        [$start, $end] = $this->reportBounds($request);
        $restaurantId = (int) $owner->restaurant_id;
        $tables = $this->tables($restaurantId);
        $floors = RestaurantFloor::where('restaurant_id', $restaurantId)->whereNull('archived_at')->withCount([
            'tables as total_tables' => fn ($query) => $query->whereNull('archived_at'),
            'tables as occupied_tables' => fn ($query) => $query->whereNull('archived_at')->where('status', 'occupied'),
        ])->orderBy('display_order')->get();
        $reservationQuery = RestaurantTableReservation::where('restaurant_id', $restaurantId)->whereBetween('starts_at', [$start, $end]);
        if ($request->filled('floor')) {
            $tables->where('floor_id', $request->integer('floor'));
            $reservationQuery->where('floor_id', $request->integer('floor'));
        }
        if ($request->filled('section')) {
            $tables->where('section_id', $request->integer('section'));
            $reservationQuery->where('section_id', $request->integer('section'));
        }
        if ($request->filled('table')) {
            $tables->whereKey($request->integer('table'));
            $reservationQuery->where('table_id', $request->integer('table'));
        }
        $scopeTableIds = (clone $tables)->pluck('id');
        $sessions = RestaurantTableOccupancy::where('restaurant_id', $restaurantId)->whereIn('table_id', $scopeTableIds)
            ->whereNotNull('ended_at')->whereBetween('started_at', [$start, $end]);
        $sessionRows = (clone $sessions)->get();
        $duration = $sessionRows->avg(fn ($session) => $session->started_at->diffInMinutes($session->ended_at)) ?? 0;
        $orders = RestaurantPosOrder::where('restaurant_id', $restaurantId)->whereIn('table_id', $scopeTableIds)
            ->where('order_type', 'dine_in')->where('status', 'completed')->whereBetween('created_at', [$start, $end]);
        $rangeTables = (clone $tables)->with([
            'floor', 'section',
            'occupancies' => fn ($query) => $query->whereNotNull('ended_at')->whereBetween('started_at', [$start, $end]),
        ])->orderBy('floor_id')->orderBy('table_number')->get();
        $tableSales = (clone $orders)->select('table_id', DB::raw('SUM(total) as total_sales'), DB::raw('COUNT(*) as order_count'))
            ->groupBy('table_id')->get()->keyBy('table_id');
        $floorSales = RestaurantPosOrder::where('restaurant_id', $restaurantId)->where('order_type', 'dine_in')->where('status', 'completed')
            ->whereIn('table_id', $scopeTableIds)->whereBetween('created_at', [$start, $end])->select('floor_id', DB::raw('SUM(total) as total_sales'))
            ->groupBy('floor_id')->pluck('total_sales', 'floor_id');
        $sectionsQuery = RestaurantSection::where('restaurant_id', $restaurantId)->whereNull('archived_at')->with('floor')
            ->withCount([
                'tables as total_tables' => fn ($query) => $query->whereNull('archived_at'),
                'tables as occupied_tables' => fn ($query) => $query->whereNull('archived_at')->where('status', 'occupied'),
            ]);
        if ($request->filled('floor')) {
            $sectionsQuery->where('floor_id', $request->integer('floor'));
        }
        if ($request->filled('section')) {
            $sectionsQuery->whereKey($request->integer('section'));
        }
        if ($request->filled('table')) {
            $sectionsQuery->whereHas('tables', fn ($query) => $query->whereKey($request->integer('table')));
        }
        $sections = $sectionsQuery->orderBy('floor_id')->orderBy('sort_order')->get();
        $sectionSales = RestaurantPosOrder::where('restaurant_id', $restaurantId)->whereIn('table_id', $scopeTableIds)
            ->where('order_type', 'dine_in')->where('status', 'completed')->whereBetween('created_at', [$start, $end])
            ->select('section_id', DB::raw('SUM(total) as total_sales'))->groupBy('section_id')->pluck('total_sales', 'section_id');
        $peakHours = $sessionRows->groupBy(fn ($session) => $session->started_at->timezone(config('app.timezone'))->format('H'))
            ->map->count()->sortKeys();
        $reservationTotal = (clone $reservationQuery)->count();
        $cancelledReservations = (clone $reservationQuery)->where('status', 'cancelled')->count();
        $noShowReservations = (clone $reservationQuery)->where('status', 'no_show')->count();
        $scopedTableCount = (clone $tables)->count();
        $tableHistory = RestaurantTableStatusHistory::where('restaurant_id', $restaurantId)->whereIn('table_id', $scopeTableIds)
            ->whereIn('to_status', ['cleaning', 'available'])->where('changed_at', '<=', $end)->orderBy('changed_at')->get();
        $cleaningSince = [];
        $cleaningMinutes = [];
        foreach ($tableHistory as $event) {
            if ($event->to_status === 'cleaning') {
                $cleaningSince[$event->table_id] = $event->changed_at;
            } elseif (isset($cleaningSince[$event->table_id]) && $event->changed_at->greaterThanOrEqualTo($start)) {
                $cleaningMinutes[] = $cleaningSince[$event->table_id]->diffInMinutes($event->changed_at);
                unset($cleaningSince[$event->table_id]);
            }
        }
        $averageCleaningMinutes = $cleaningMinutes ? (int) round(array_sum($cleaningMinutes) / count($cleaningMinutes)) : null;
        $collectedPayments = DB::table('restaurant_pos_payments as payments')
            ->join('restaurant_pos_orders as orders', 'orders.id', '=', 'payments.pos_order_id')
            ->where('payments.restaurant_id', $restaurantId)->where('orders.restaurant_id', $restaurantId)
            ->where('orders.order_type', 'dine_in')->where('payments.status', 'recorded')
            ->whereBetween('payments.recorded_at', [$start, $end]);
        if ($request->filled('floor')) {
            $collectedPayments->where('orders.floor_id', $request->integer('floor'));
        }
        if ($request->filled('section')) {
            $collectedPayments->where('orders.section_id', $request->integer('section'));
        }
        if ($request->filled('table')) {
            $collectedPayments->where('orders.table_id', $request->integer('table'));
        }
        $refunds = DB::table('restaurant_pos_refunds as refunds')
            ->join('restaurant_pos_orders as orders', 'orders.id', '=', 'refunds.pos_order_id')
            ->where('refunds.restaurant_id', $restaurantId)->where('orders.restaurant_id', $restaurantId)
            ->where('orders.order_type', 'dine_in')->where('refunds.status', 'recorded')
            ->whereBetween('refunds.recorded_at', [$start, $end]);
        if ($request->filled('floor')) {
            $refunds->where('orders.floor_id', $request->integer('floor'));
        }
        if ($request->filled('section')) {
            $refunds->where('orders.section_id', $request->integer('section'));
        }
        if ($request->filled('table')) {
            $refunds->where('orders.table_id', $request->integer('table'));
        }
        $recordedPaymentsTotal = $collectedPayments->sum('payments.amount');
        $refundTotal = $refunds->sum('refunds.amount');
        $mostUsedTables = $rangeTables->sortByDesc(fn ($table) => $table->occupancies->count())->take(5)->values();
        $leastUsedTables = $rangeTables->sortBy(fn ($table) => $table->occupancies->count())->take(5)->values();

        return view('restaurant.tables.reports', [
            'owner' => $owner, 'floors' => $floors,
            'tables' => $rangeTables,
            'tableCount' => (clone $tables)->count(),
            'floorCount' => RestaurantFloor::where('restaurant_id', $restaurantId)->whereNull('archived_at')->count(),
            'sectionCount' => RestaurantSection::where('restaurant_id', $restaurantId)->whereNull('archived_at')->count(),
            'occupiedCount' => (clone $tables)->where('status', 'occupied')->count(),
            'averageMinutes' => $sessionRows->isEmpty() ? null : (int) round($duration),
            'averageCleaningMinutes' => $averageCleaningMinutes,
            'turnover' => $scopedTableCount ? round($sessionRows->count() / $scopedTableCount, 2) : 0,
            'reservationCount' => $reservationTotal,
            'cancelledCount' => $cancelledReservations,
            'cancelledRate' => $reservationTotal ? round($cancelledReservations / $reservationTotal * 100, 1) : 0,
            'noShowCount' => $noShowReservations,
            'noShowRate' => $reservationTotal ? round($noShowReservations / $reservationTotal * 100, 1) : 0,
            'sales' => (clone $orders)->sum('total'),
            'recordedPayments' => $recordedPaymentsTotal,
            'refundTotal' => $refundTotal,
            'collected' => $recordedPaymentsTotal - $refundTotal,
            'tableSales' => $tableSales,
            'floorSales' => $floorSales,
            'sections' => $sections,
            'sectionSales' => $sectionSales,
            'mostUsedTables' => $mostUsedTables,
            'leastUsedTables' => $leastUsedTables,
            'peakHours' => $peakHours,
            'sessionCount' => $sessionRows->count(),
            'start' => $start->toDateString(), 'end' => $end->toDateString(),
        ]);
    }

    public function exportReports(Request $request): StreamedResponse
    {
        $owner = $this->owner();
        [$start, $end] = $this->reportBounds($request);
        $query = $this->tables($owner->restaurant_id)->with(['floor', 'section', 'occupancies' => fn ($q) => $q->whereNotNull('ended_at')->whereBetween('started_at', [$start, $end])]);
        if ($request->filled('floor')) {
            $query->where('floor_id', $request->integer('floor'));
        }
        if ($request->filled('section')) {
            $query->where('section_id', $request->integer('section'));
        }
        if ($request->filled('table')) {
            $query->whereKey($request->integer('table'));
        }
        $tables = $query->orderBy('table_number')->get();

        $sales = RestaurantPosOrder::where('restaurant_id', $owner->restaurant_id)->where('order_type', 'dine_in')
            ->where('status', 'completed')->whereIn('table_id', $tables->pluck('id'))
            ->whereBetween('created_at', [$start, $end])->select('table_id', DB::raw('SUM(total) as total_sales'))
            ->groupBy('table_id')->pluck('total_sales', 'table_id');
        return response()->streamDownload(function () use ($tables, $sales): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Table', 'Floor', 'Section', 'Capacity', 'Current status', 'Sessions in range', 'Completed order value']);
            foreach ($tables as $table) {
                fputcsv($out, array_map($this->safeCsvCell(...), [
                    $table->table_number, $table->floor?->name, $table->section?->name, $table->capacity,
                    $table->status, $table->occupancies->count(), number_format((float) ($sales[$table->id] ?? 0), 2, '.', ''),
                ]));
            }
            fclose($out);
        }, 'table-reports.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function safeCsvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }

    private function reportBounds(Request $request): array
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(['today', 'yesterday', '7', '30', 'custom'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $zone = config('app.timezone');
        $today = CarbonImmutable::now($zone)->startOfDay();
        $start = match ($data['period'] ?? 'today') {
            'yesterday' => $today->subDay(),
            '7' => $today->subDays(6),
            '30' => $today->subDays(29),
            'custom' => isset($data['from']) ? CarbonImmutable::parse($data['from'], $zone)->startOfDay() : $today,
            default => $today,
        };
        $end = ($data['period'] ?? 'today') === 'custom' && isset($data['to'])
            ? CarbonImmutable::parse($data['to'], $zone)->endOfDay()
            : (($data['period'] ?? 'today') === 'yesterday' ? $today->subSecond() : $today->endOfDay());

        return [$start->utc(), $end->utc()];
    }
}
