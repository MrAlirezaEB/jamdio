<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FallbackUploadRequest;
use App\Services\FallbackLibrary;
use Illuminate\Http\RedirectResponse;

class FallbackController extends Controller
{
    public function __construct(
        private readonly FallbackLibrary $fallback,
    ) {}

    /** Add an MP3 to the fallback playlist Liquidsoap plays when the queue is empty. */
    public function store(FallbackUploadRequest $request): RedirectResponse
    {
        $name = $this->fallback->store($request->file('track'));

        return back()->with('success', "“{$name}” added to the fallback playlist.");
    }

    /** Remove a track from the fallback playlist. */
    public function destroy(string $name): RedirectResponse
    {
        if ($this->fallback->delete($name)) {
            return back()->with('success', 'Fallback track removed.');
        }

        return back()->with('error', 'Fallback track not found.');
    }
}
