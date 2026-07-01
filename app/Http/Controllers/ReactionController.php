<?php

namespace App\Http\Controllers;

use App\Events\EmojiReaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReactionController extends Controller
{
    /** Broadcast an emoji reaction from the current guest to the station. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'emoji' => ['required', 'string', Rule::in(config('radio.reactions', []))],
        ]);

        $guest = $request->attributes->get('guest_user');

        broadcast(new EmojiReaction($guest, $validated['emoji']));

        return response()->json(['ok' => true]);
    }
}
