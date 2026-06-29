<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public Stream URL
    |--------------------------------------------------------------------------
    | The Icecast mountpoint URL the browser <audio> element connects to.
    */
    'stream_url' => env('ICECAST_PUBLIC_URL', 'http://localhost:8000/stream'),

    /*
    |--------------------------------------------------------------------------
    | Icecast
    |--------------------------------------------------------------------------
    */
    'icecast' => [
        'host' => env('ICECAST_HOST', 'icecast'),
        'port' => (int) env('ICECAST_PORT', 8000),
        'mount' => env('ICECAST_MOUNT', '/stream'),
        'source_password' => env('ICECAST_SOURCE_PASSWORD', 'hackme'),
        'admin_password' => env('ICECAST_ADMIN_PASSWORD', 'hackme'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Liquidsoap integration
    |--------------------------------------------------------------------------
    | - api_token: bearer token Liquidsoap presents when polling the API.
    | - telnet:    control interface Laravel uses to issue request.skip.
    */
    'liquidsoap' => [
        'api_token' => env('LIQUIDSOAP_API_TOKEN', 'liquidsoap-internal-token'),
        'telnet_host' => env('LIQUIDSOAP_TELNET_HOST', 'liquidsoap'),
        'telnet_port' => (int) env('LIQUIDSOAP_TELNET_PORT', 1234),
        'fallback_dir' => env('LIQUIDSOAP_FALLBACK_DIR', '/srv/fallback'),
        // Telnet command the .liq script exposes to force-skip the current track.
        'skip_command' => env('LIQUIDSOAP_SKIP_COMMAND', 'radio.skip'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Skip vote tuning
    |--------------------------------------------------------------------------
    | active_window: seconds within which a user counts as "active".
    | threshold:     fraction of active users required to skip a track.
    */
    'active_window' => (int) env('ACTIVE_USER_WINDOW', 30),
    'skip_threshold' => (float) env('SKIP_VOTE_THRESHOLD', 0.5),

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    */
    'max_upload_kb' => (int) env('TRACK_MAX_UPLOAD_KB', 15360),

    /*
    |--------------------------------------------------------------------------
    | Admin
    |--------------------------------------------------------------------------
    */
    'admin_password' => env('ADMIN_PASSWORD', 'change-me-admin'),

    /*
    |--------------------------------------------------------------------------
    | Predefined avatars offered in the join modal.
    |--------------------------------------------------------------------------
    */
    'avatars' => [
        'avatars/a1.svg',
        'avatars/a2.svg',
        'avatars/a3.svg',
        'avatars/a4.svg',
        'avatars/a5.svg',
        'avatars/a6.svg',
    ],

];
