<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('super-admin.dashboard', [
            'superAdmin' => Auth::guard('super_admin')->user(),
        ]);
    }

    public function profile(): View
    {
        return view('super-admin.profile', [
            'superAdmin' => Auth::guard('super_admin')->user(),
        ]);
    }
}
