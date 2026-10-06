<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\MessageService;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    public function __invoke(MessageService $messages): Response
    {
        $channel = Channel::general();

        return Inertia::render('Chat/Index', ['channel' => $channel->only(['id', 'name', 'slug']), 'history' => $messages->history($channel)]);
    }
}
