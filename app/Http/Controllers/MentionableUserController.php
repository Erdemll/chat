<?php

namespace App\Http\Controllers;

use App\Http\Resources\MentionableUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MentionableUserController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        return MentionableUserResource::collection(User::query()->select(['id', 'name'])
            ->where('is_active', true)->where('id', '!=', $request->user()->id)
            ->orderBy('name')->orderBy('id')->get());
    }
}
