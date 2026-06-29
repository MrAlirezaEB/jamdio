# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Jamdio is a collaborative online radio platform. An admin controls a station; unauthenticated guests join with a nickname + avatar, listen to a synchronized Icecast stream, upload MP3s to a global queue, and vote to skip the current track. Stack: Laravel 13 · Inertia + Vue 3 · Laravel Reverb · Icecast + Liquidsoap · MySQL · Docker.

`IDEA.md` is the original product spec; `README.md` covers setup, the data model, and the HTTP/WebSocket surface. Read those for feature-level detail.

## Commands

Everything runs inside Docker; the Makefile wraps `docker compose`. Don't run `php`/`artisan`/`composer`/`npm` directly on the host — they expect to run in the `app`/`node` containers.

```bash
make install                      # first-time setup (build + boot + migrate --seed + assets)
make up / make down               # start / stop the stack
make fresh                        # nuke volumes and rebuild from scratch
make sh                           # bash in the app container
make artisan a="route:list"       # any artisan command
make test                         # PHPUnit/Pest suite (runs in the app container)
make pint                         # Laravel Pint code style
make lint                         # php -l syntax check across app/routes/config/database
make dev                          # Vite dev server (HMR) in foreground
make assets-build                 # production Vite build → public/build
make liq-skip                     # force-skip current track via LiquidsoapClient
make logs-<service>               # tail one service (e.g. logs-liquidsoap)
```

Run a single test: `make artisan a="test --filter=SomeTest"`.

Tests use an in-memory SQLite DB and a `null` broadcast driver (see `phpunit.xml`), so they never touch MySQL, Reverb, or Liquidsoap. The current suite is only the Laravel skeleton's `ExampleTest`s.

## Architecture

### Audio flow — the core loop

The browser plays a single continuous Icecast stream; tracks are swapped server-side, so there is no per-client seek/sync logic.

1. **Liquidsoap is the player.** `docker/liquidsoap/radio.liq` streams 24/7. On each track boundary it polls `GET /api/next-track` (bearer token, `LiquidsoapAuth` middleware). When the queue is empty it falls back to `docker/fallback/*.mp3`.
2. **Laravel advances the queue, Liquidsoap consumes it.** `LiquidsoapController::nextTrack` → `QueueService::advance()` marks the `playing` row `played`, promotes the next `pending` row to `playing`, and returns the track's **absolute file path** as plain text. The `app` and `liquidsoap` containers share the `storage/app/public` volume, so that path resolves identically in both.
3. **Skipping is asynchronous.** Laravel never switches the track itself. `SkipVoteService` / admin force-skip calls `LiquidsoapClient::skip()`, which sends `radio.skip` over Liquidsoap's telnet control port (1234). Liquidsoap kills the track and immediately re-polls `/api/next-track` — the same path as a natural track end.

Because of this, queue state lives entirely in the DB and `advance()` is the single chokepoint that mutates "what's playing." It runs in a `lockForUpdate` transaction.

### Skip-vote rule

A track skips when votes are **strictly more than** `SKIP_VOTE_THRESHOLD` (default 0.5) of *active* users. "Active" = `users.last_active_at` within `ACTIVE_USER_WINDOW` seconds (default 30) — see `User::scopeActive`. Required votes = `floor(active * threshold) + 1`, min 1 (`SkipVoteService::requiredVotes`). Votes are cleared when a track leaves the deck (`QueueService::advance` → `SkipVoteService::clear`).

### Realtime

Reverb broadcasts to a single public `station` channel. Events live in `app/Events/`: `TrackChanged`, `QueueUpdated`, `VoteCountUpdated`, `UserJoined`, `UserLeft`, `UserKicked`, `StationToggled`. Broadcasts run through the `worker` queue container (`queue:work`), so the worker must be up for realtime to function. Service methods (`QueueService::broadcastNowPlaying` / `broadcastQueue`) are the canonical places that emit these — prefer calling them over constructing events ad hoc.

### Request layers

- **Guest soft-auth**: no passwords. `POST /join` creates a `users` row + session. `RequireGuestSession` (`guest.session` alias) guards `/player`, uploads, voting, heartbeat. `TrackActivity` middleware refreshes `last_active_at` on every web request; `BlockListMiddleware` rejects blocked users/IPs.
- **Admin**: session-flag based (`is_admin`), password from `ADMIN_PASSWORD`. `RequireAdmin` (`admin` alias) guards everything under `/admin`.
- **Liquidsoap API**: `LiquidsoapAuth` (`liquidsoap` alias) checks a shared bearer token (`LIQUIDSOAP_API_TOKEN`). Routes in `routes/api.php` return JSON on error (see `bootstrap/app.php` exception config).

### Routing layout

`routes/web.php` is the spine; feature route groups are `require`d in from `routes/features/{queue,voting,admin}.php`. `queue.php` and `voting.php` are pulled *inside* the `guest.session` middleware group in `web.php` — they assume that guard is already applied and don't re-declare it. Middleware aliases are registered in `bootstrap/app.php` (Laravel 11+ style; there is no `Http/Kernel.php`).

### Service layer

Business logic lives in `app/Services/`, not controllers. `QueueService` (queue advance + the payload builders shared by controllers and broadcast events), `SkipVoteService` (vote tally + skip decision), `LiquidsoapClient` (telnet), `AudioMetadata` (getID3 duration extraction on upload). Controllers are thin and inject these.

### Frontend

Inertia + Vue 3 (`@inertiajs/vue3`). Pages in `resources/js/Pages/` (`Home`, `Player`, `Blocked`, `Admin/Login`, `Admin/Dashboard`). `HandleInertiaRequests::share()` exposes global props to every page: `guest`, `isAdmin`, `station` (status + stream URL), `reverb` (connection config for laravel-echo), and `flash`. Echo is configured in `resources/js/bootstrap.js` from those `reverb` props.

### Config

App-specific tuning is centralized in `config/radio.php` (stream URL, Icecast/Liquidsoap connection, skip threshold, active window, max upload KB, admin password, avatar list) — all env-driven. Prefer adding new knobs here over scattering `env()` calls.

## Docker services

`app` (PHP-FPM), `nginx` (:8000), `mysql` (:33060→3306), `reverb` (:8080 WS), `worker` (queue), `node` (Vite :5173), `icecast` (:8001→8000), `liquidsoap` (telnet 1234, polls nginx internally). The `app`, `reverb`, and `worker` containers share one PHP image and branch on the `JAMDIO_ROLE` env var — only `JAMDIO_ROLE=app` runs the bootstrap (composer install, key:generate, migrate --seed, storage:link) in `docker/php/entrypoint.sh`. The other two just start their command.
