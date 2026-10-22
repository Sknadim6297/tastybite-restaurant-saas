<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Services\DemoDataManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DemoDataController extends Controller
{
    public function index(DemoDataManagementService $demoData): View
    {
        return view('super-admin.demo-data.index', [
            'preview' => $demoData->preview(),
        ]);
    }

    public function generate(Request $request, DemoDataManagementService $demoData): RedirectResponse
    {
        abort_unless(app()->environment('local'), 403, 'Demo data generation is disabled outside the local environment.');
        $request->validate([
            'generate_confirmation' => ['required', 'in:GENERATE DEMO DATA'],
        ]);

        $admin = Auth::guard('super_admin')->user();
        if (! $admin instanceof SuperAdmin) {
            abort(403);
        }
        $summary = $demoData->generate($admin);

        return redirect()->route('super-admin.demo-data.index')->with('demo_generated', $summary);
    }

    public function cleanup(Request $request, DemoDataManagementService $demoData): RedirectResponse
    {
        abort_unless(app()->environment('local'), 403, 'Demo data cleanup is disabled outside the local environment.');
        $request->validate([
            'cleanup_confirmation' => ['required', 'in:DELETE DEMO-TB-2026'],
        ]);

        $admin = Auth::guard('super_admin')->user();
        if (! $admin instanceof SuperAdmin) {
            abort(403);
        }
        $removed = $demoData->cleanup($admin);

        return redirect()->route('super-admin.demo-data.index')->with('demo_cleaned', $removed);
    }
}
