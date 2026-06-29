<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $guest = $request->attributes->get('guest_user');

        return [
            ...parent::share($request),
            'guest' => $guest ? [
                'id' => $guest->id,
                'nickname' => $guest->nickname,
                'avatar_path' => $guest->avatar_url,
            ] : null,
            'isAdmin' => (bool) $request->session()->get('is_admin', false),
            'station' => [
                'status' => Setting::get('station_status', 'on'),
                'streamUrl' => config('radio.stream_url'),
            ],
            'reverb' => [
                'key' => env('REVERB_APP_KEY'),
                'host' => env('REVERB_HOST', 'localhost'),
                'port' => (int) env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
