<?php

namespace App\Http\Controllers\Restaurant;

use Closure;
use App\Http\Controllers\Controller;
use App\Models\RestaurantMenuCategory;
use App\Models\User;
use App\Services\RestaurantAccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MenuCategoryController extends Controller
{
    public function index(): View
    {
        $owner = $this->owner();

        return view('restaurant.menu-categories.index', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'categories' => $owner->restaurant->menuCategories()
                ->withCount('products')
                ->paginate(15),
        ]);
    }

    public function create(): View
    {
        return $this->formView($this->owner(), new RestaurantMenuCategory());
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $validated = $this->validateCategory($request, $owner);
        $restaurant = $owner->restaurant;

        $restaurant->menuCategories()->create([
            ...$validated,
            'slug' => $this->uniqueSlug($restaurant->id, $validated['name']),
        ]);

        return redirect()->route('restaurant.menu.categories.index')
            ->with('status', 'Category created successfully.');
    }

    public function edit(int $category): View
    {
        $owner = $this->owner();
        $category = $this->tenantCategory($category, $owner);

        return $this->formView($owner, $category);
    }

    public function update(Request $request, int $category): RedirectResponse
    {
        $owner = $this->owner();
        $category = $this->tenantCategory($category, $owner);
        $validated = $this->validateCategory($request, $owner, $category);

        $category->update([
            ...$validated,
            'slug' => $this->uniqueSlug($owner->restaurant_id, $validated['name'], $category->id),
        ]);

        return redirect()->route('restaurant.menu.categories.index')
            ->with('status', 'Category updated successfully.');
    }

    public function toggleStatus(int $category): RedirectResponse
    {
        $owner = $this->owner();
        $category = $this->tenantCategory($category, $owner);
        $category->update(['is_active' => ! $category->is_active]);

        return redirect()->route('restaurant.menu.categories.index')
            ->with('status', sprintf(
                'Category "%s" was %s.',
                $category->name,
                $category->is_active ? 'activated' : 'deactivated',
            ));
    }

    public function destroy(int $category): RedirectResponse
    {
        $owner = $this->owner();
        $category = $this->tenantCategory($category, $owner);

        if ($category->products()->exists()) {
            return redirect()->route('restaurant.menu.categories.index')
                ->with('status', 'This category has products assigned. Reassign or remove those products before deleting it.')
                ->with('toast_type', 'warning');
        }

        $imagePath = $category->image_path;
        $category->delete();

        if ($imagePath) {
            $this->deleteStoredImage($imagePath, $owner);
        }

        return redirect()->route('restaurant.menu.categories.index')
            ->with('status', 'Category deleted successfully.');
    }

    private function formView(User $owner, RestaurantMenuCategory $category): View
    {
        return view('restaurant.menu-categories.form', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'category' => $category,
        ]);
    }

    private function validateCategory(
        Request $request,
        User $owner,
        ?RestaurantMenuCategory $category = null,
    ): array {
        $request->merge([
            'name' => trim((string) $request->input('name')),
        ]);

        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($owner, $category): void {
                    $duplicate = $owner->restaurant->menuCategories()
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                        ->when($category !== null, fn ($query) => $query->where('id', '!=', $category->id))
                        ->exists();

                    if ($duplicate) {
                        $fail('A category with this name already exists for your restaurant.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:10000'],
            'icon' => ['nullable', 'string', Rule::in(array_keys(RestaurantMenuCategory::ICONS))],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ]);
    }

    private function uniqueSlug(int $restaurantId, string $name, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($name) ?: 'category';
        $slug = $baseSlug;
        $suffix = 2;

        while (
            RestaurantMenuCategory::query()
                ->where('restaurant_id', $restaurantId)
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return $slug;
    }

    private function owner(): User
    {
        return app(RestaurantAccessContext::class)->owner();
    }

    private function deleteStoredImage(string $path, User $owner): void
    {
        $directory = 'restaurant-menu-categories/'.$owner->restaurant_id;
        $filename = basename($path);

        if (
            dirname($path) !== $directory
            || $filename === ''
            || ! preg_match('/\A[A-Za-z0-9._-]+\z/', $filename)
        ) {
            return;
        }

        $disk = Storage::disk('public');
        if ($disk->exists($path) && ! $disk->delete($path)) {
            throw new RuntimeException('The category image could not be removed from storage.');
        }
    }

    private function tenantCategory(int $categoryId, User $owner): RestaurantMenuCategory
    {
        return $owner->restaurant->menuCategories()->findOrFail($categoryId);
    }
}
