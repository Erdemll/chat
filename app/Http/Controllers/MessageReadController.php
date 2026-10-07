<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMessageReadRequest;
use App\Http\Resources\MessageReadResource;
use App\Models\Message;
use App\Services\MessageReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MessageReadController extends Controller
{
    public function store(StoreMessageReadRequest $request, MessageReadService $reads): JsonResponse
    {
        $updates = $reads->markAsRead($request->user(), $request->validated('message_ids'));

        return response()->json(['success' => true, 'reads' => $updates]);
    }

    public function index(Message $message, MessageReadService $reads): AnonymousResourceCollection
    {
        Gate::authorize('view', $message);

        return MessageReadResource::collection($reads->readers($message));
    }
}
