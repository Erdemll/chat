<?php

namespace App\Http\Controllers;

use App\Exceptions\MessageRejectedException;
use App\Http\Requests\StoreMessageRequest;
use App\Http\Requests\UpdateMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    public function destroy(Request $request, Message $message, MessageService $messages): JsonResponse
    {
        Gate::authorize('delete', $message);
        $result = $messages->delete($request->user(), $message);

        return response()->json(['message_id' => $message->id, 'channel_id' => $message->channel_id, 'realtime' => $result['realtime']]);
    }

    public function update(UpdateMessageRequest $request, Message $message, MessageService $messages): JsonResponse
    {
        try {
            $result = $messages->update($request->user(), $message, $request->validated('body'), $request->validated('mentions') ?? [], $request->validated('expected_body'));
        } catch (MessageRejectedException) {
            throw ValidationException::withMessages([
                'body' => 'Mesajınız şirket iletişim kurallarına uygun olmadığı için düzenlenemedi.',
            ]);
        }

        return response()->json(['data' => (new MessageResource($result['message']))->resolve(), 'realtime' => $result['realtime']]);
    }

    public function index(Request $request, MessageService $messages): JsonResponse
    {
        $validated = $request->validate(['before_id' => ['sometimes', 'integer', 'min:1', 'prohibits:after_id,message_ids'], 'after_id' => ['sometimes', 'integer', 'min:0', 'prohibits:message_ids'],
            'message_ids' => ['sometimes', 'array', 'list', 'min:1', 'max:100'], 'message_ids.*' => ['required', 'integer', 'min:1', 'distinct']]);

        return response()->json($messages->history(Channel::general(), $request->has('before_id') ? $request->integer('before_id') : null, $request->has('after_id') ? $request->integer('after_id') : null, $validated['message_ids'] ?? null));
    }

    public function show(Message $message): MessageResource
    {
        Gate::authorize('view', $message);

        return new MessageResource($message->load(['user:id,name', 'mentionedUsers:users.id,name'])->loadCount('reads'));
    }

    public function store(StoreMessageRequest $request, MessageService $messages): JsonResponse
    {
        try {
            $result = $messages->send($request->user(), Channel::general(), $request->validated('body'), $request->validated('mentions') ?? []);
        } catch (MessageRejectedException) {
            throw ValidationException::withMessages([
                'body' => 'Mesajınız şirket iletişim kurallarına uygun olmadığı için gönderilemedi.',
            ]);
        }

        return response()->json(['data' => (new MessageResource($result['message']))->resolve(), 'realtime' => $result['realtime']], 201);
    }
}
