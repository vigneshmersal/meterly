<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardService $dashboard): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $merchant = $user->merchant;

        return view('dashboard', [
            'merchant' => $merchant,
            'metrics' => $merchant === null ? null : $dashboard->handle($merchant),
        ]);
    }

    public function show(
        Request $request,
        Merchant $merchant,
        DashboardService $dashboard,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->merchant_id === $merchant->id, 404);

        return response()->json($dashboard->handle($merchant));
    }
}
