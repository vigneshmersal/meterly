<?php

namespace App\Http\Controllers;

use App\Actions\Usage\RecordUsageAction;
use App\Http\Requests\StoreUsageRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UsageController extends Controller
{
    public function store(
        StoreUsageRequest $request,
        RecordUsageAction $recordUsage,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $result = $recordUsage->handle($user, $request->usageData());
        $event = $result['event'];

        return response()->json([
            'message' => $result['alreadyRecorded']
                ? 'Usage event already recorded'
                : 'Usage recorded successfully',
            'event_id' => $event->event_key,
        ], $result['alreadyRecorded'] ? 200 : 201);
    }
}
