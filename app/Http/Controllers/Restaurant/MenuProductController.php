<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantMenuCategory;
use App\Models\RestaurantMenuItem;
use App\Models\User;
use App\Services\RestaurantAccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class MenuProductController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $owner = $this->owner();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('restaurant_menu_categories', 'id')
                    ->where('restaurant_id', $owner->restaurant_id),
            ],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        $categoryId = $validated['category_id'] ?? null;

        $products = $owner->restaurant->menuItems()
            ->with('category')
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        if ($request->expectsJson()) {
            return response()->json([
                'html' => view('restaurant.menu-products.partials.results', [
                    'products' => $products,
                    'search' => $search,
                    'categoryId' => $categoryId,
                ])->render(),
            ]);
        }

        return view('restaurant.menu-products.index', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'products' => $products,
            'search' => $search,
            'categories' => $owner->restaurant->menuCategories()->orderBy('name')->get(['id', 'name']),
            'categoryId' => $categoryId,
        ]);
    }

    public function create(): View
    {
        return $this->formView($this->owner(), new RestaurantMenuItem());
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = $this->owner();
        $validated = $this->validateProduct($request, $owner);
        $image = $validated['image'] ?? null;
        unset($validated['image']);

        $imagePath = $image instanceof UploadedFile
            ? $this->storeImage($image, $owner)
            : null;

        $owner->restaurant->menuItems()->create([
            ...$validated,
            'image_path' => $imagePath,
        ]);

        return redirect()->route('restaurant.menu.products.index')
            ->with('status', 'Product created successfully.');
    }

    public function edit(int $product): View
    {
        $owner = $this->owner();
        $product = $this->tenantProduct($product, $owner);

        return $this->formView($owner, $product);
    }

    public function update(Request $request, int $product): RedirectResponse
    {
        $owner = $this->owner();
        $product = $this->tenantProduct($product, $owner);
        $validated = $this->validateProduct($request, $owner);
        $image = $validated['image'] ?? null;
        $removeImage = (bool) ($validated['remove_image'] ?? false);
        unset($validated['image'], $validated['remove_image']);

        $oldImagePath = $product->image_path;
        $newImagePath = $image instanceof UploadedFile
            ? $this->storeImage($image, $owner)
            : null;

        if ($newImagePath !== null) {
            $validated['image_path'] = $newImagePath;
        } elseif ($removeImage) {
            $validated['image_path'] = null;
        }

        $product->update($validated);

        if ($oldImagePath && ($newImagePath !== null || $removeImage)) {
            $this->deleteStoredImage($oldImagePath, $owner);
        }

        return redirect()->route('restaurant.menu.products.index')
            ->with('status', 'Product updated successfully.');
    }

    public function destroy(int $product): RedirectResponse
    {
        $owner = $this->owner();
        $product = $this->tenantProduct($product, $owner);
        $imagePath = $product->image_path;

        $product->delete();

        if ($imagePath) {
            $this->deleteStoredImage($imagePath, $owner);
        }

        return redirect()->route('restaurant.menu.products.index')
            ->with('status', 'Product deleted successfully.');
    }

    private function formView(User $owner, RestaurantMenuItem $product): View
    {
        return view('restaurant.menu-products.form', [
            'user' => $owner,
            'restaurant' => $owner->restaurant,
            'product' => $product,
            'categories' => $owner->restaurant->menuCategories()
                ->orderBy('name')
                ->get(),
        ]);
    }

    private function validateProduct(Request $request, User $owner): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('restaurant_menu_categories', 'id')
                    ->where('restaurant_id', $owner->restaurant_id),
            ],
            'description' => ['nullable', 'string', 'max:10000'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'food_type' => ['required', Rule::in(['veg', 'non_veg'])],
            'is_available' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'remove_image' => ['sometimes', 'boolean'],
        ]);
    }

    private function storeImage(UploadedFile $image, User $owner): string
    {
        $path = $image->store('restaurant-menu/'.$owner->restaurant_id, 'public');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The product image could not be stored.');
        }

        return $path;
    }

    private function deleteStoredImage(string $path, User $owner): void
    {
        $directory = 'restaurant-menu/'.$owner->restaurant_id;
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
            throw new RuntimeException('The product image could not be removed from storage.');
        }
    }

    private function owner(): User
    {
        return app(RestaurantAccessContext::class)->owner();
    }

    private function tenantProduct(int $productId, User $owner): RestaurantMenuItem
    {
        return $owner->restaurant->menuItems()->findOrFail($productId);
    }
}
