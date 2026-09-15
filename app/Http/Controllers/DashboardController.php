<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
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
