<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index(Request $request, MessageService $messages): JsonResponse
    {
        $request->validate(['before_id' => ['sometimes', 'integer', 'min:1', 'prohibits:after_id'], 'after_id' => ['sometimes', 'integer', 'min:0']]);

        return response()->json($messages->history(Channel::general(), $request->has('before_id') ? $request->integer('before_id') : null, $request->has('after_id') ? $request->integer('after_id') : null));
    }

    public function show(Message $message): MessageResource
    {
        abort_unless($message->channel_id === Channel::general()->id, 404);

        return new MessageResource($message->load('user:id,name'));
    }

    public function store(StoreMessageRequest $request, MessageService $messages): JsonResponse
    {
        $result = $messages->send($request->user(), Channel::general(), $request->validated('body'));

        return response()->json(['data' => (new MessageResource($result['message']))->resolve(), 'realtime' => $result['realtime']], 201);
    }
}
