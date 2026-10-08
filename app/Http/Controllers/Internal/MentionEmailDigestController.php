<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Notifications\MentionEmailDigestService;
use Illuminate\Http\JsonResponse;

class MentionEmailDigestController extends Controller
{
    public function __invoke(MentionEmailDigestService $service): JsonResponse
    {
        return response()->json(['status' => 'ok', ...$service->run()]);
    }
}
