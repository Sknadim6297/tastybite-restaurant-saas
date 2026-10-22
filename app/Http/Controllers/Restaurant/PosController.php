<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Services\RestaurantAccessContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PosController extends Controller
{
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
        ]);
    }
}
