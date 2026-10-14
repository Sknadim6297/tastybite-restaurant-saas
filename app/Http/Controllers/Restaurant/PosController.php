<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantMenuCategory;
use App\Models\RestaurantMenuItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PosController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::guard('web')->user();

        abort_unless($user && $user->restaurant, 403, 'A valid restaurant account is required.');

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
        ]);
    }
}
