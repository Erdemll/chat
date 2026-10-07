<?php

namespace App\Http\Controllers;

use App\Services\UserActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserActivityController extends Controller
{
    public function __invoke(Request $request, UserActivityService $activity): JsonResponse
    {
        $activity->record($request->user());

        return response()->json(['success' => true]);
    }
}
