# 🎧 Jamdio — Collaborative Online Radio

A web-based collaborative radio platform. An **admin** controls the station;
**guests** join with a temporary nickname + avatar (no registration), listen to
a synchronized live stream, submit MP3 tracks to a global queue, and vote to
skip the current track.

**Stack:** Laravel 13 · Inertia + Vue 3 · Laravel Reverb (WebSockets) ·
Icecast + Liquidsoap (streaming) · MySQL · Docker.

---

## Architecture & audio flow

```
            ┌──────────┐   /api/next-track    ┌───────────┐   MP3 source    ┌─────────┐
 Browser ◀──│  Nginx   │◀────────────────────▶│ Liquidsoap │────────────────▶│ Icecast │──▶ Browser <audio>
  (Vue)     │  + PHP   │  request.skip (telnet)│  radio.liq │                 │ /stream │
    ▲       └────┬─────┘◀────────────────────▶└───────────┘                 └─────────┘
    │            │
    │ WebSocket  │ DB (queue, votes, users, settings)
    └─────── Reverb :8080        MySQL
```

1. **Continuous play** — Liquidsoap streams 24/7. When the user queue is empty
   it plays a fallback playlist (`docker/fallback/*.mp3`).
2. **Dynamic queue** — On each track boundary Liquidsoap polls
   `GET /api/next-track`. Laravel marks the finished track `played`, promotes the
   next `pending` track to `playing`, and returns its **absolute file path**.
   Liquidsoap and the app share the `storage/app/public` volume, so the path
   resolves identically in both containers.
3. **Skip** — When votes pass the threshold (or an admin forces it), Laravel
   sends `radio.skip` over Liquidsoap's telnet interface; Liquidsoap ends the
   track and immediately polls for the next one.
4. **Realtime** — Reverb broadcasts station events to all browsers over a public
   `station` channel.

---

## Quick start (Docker)

```bash
make install          # build images, boot the stack, build frontend assets
```

That's the one command for first-time setup. Under the hood it copies
`.env.example` → `.env`, builds the images, runs `docker compose up -d`, and
builds the Vite assets. The `app` container itself waits for MySQL and runs
`composer install`, `key:generate`, `migrate --seed`, and `storage:link` on
first boot.

Prefer raw Docker? `cp .env.example .env && docker compose up -d --build`.

| Service          | URL / port                          |
|------------------|-------------------------------------|
| App (Nginx+PHP)  | http://localhost:8000               |
| Admin panel      | http://localhost:8000/admin/login   |
| Icecast stream   | http://localhost:8001/stream        |
| Reverb (WS)      | ws://localhost:8080                 |
| Vite dev server  | http://localhost:5173               |
| MySQL            | localhost:33060                     |

Add fallback music: drop `.mp3` files into `docker/fallback/`.

Admin password: `ADMIN_PASSWORD` in `.env` (default `change-me-admin`).

### Common commands (Makefile)

Run `make` (or `make help`) to see everything. The essentials:

| Command                         | What it does                                  |
|---------------------------------|-----------------------------------------------|
| `make install`                  | First-time setup (build + boot + assets)      |
| `make up` / `make down`         | Start / stop the stack                        |
| `make fresh`                    | Wipe volumes and rebuild from scratch         |
| `make ps`                       | Container status                              |
| `make logs` / `make logs-liquidsoap` | Tail all / one service's logs            |
| `make sh`                       | Bash shell in the **app** container           |
| `make sh-mysql` / `make sh-node` / `make sh-liquidsoap` | Shell into any service |
| `make artisan a="route:list"`   | Run any artisan command                       |
| `make migrate` / `make fresh-seed` | Migrations                                 |
| `make tinker`                   | Tinker REPL                                    |
| `make composer a="require x/y"` | Run composer                                  |
| `make npm a="install x"` / `make assets-build` | Frontend deps / production build |
| `make dev`                      | Vite dev server (HMR) in the foreground       |
| `make db`                       | MySQL shell                                    |
| `make liq-skip`                 | Force-skip the current track                  |
| `make liq-reload`               | Reload the Liquidsoap script                   |
| `make test` / `make pint` / `make lint` | Test suite / code style / syntax lint |

### Production assets

The `node` service runs the Vite dev server for HMR. For a production build:

```bash
make assets-build      # == docker compose run --rm node npm run build
```

…then the compiled manifest in `public/build` is served by Nginx (no dev server
needed).

---

## Local (non-Docker) notes

Requires PHP 8.3 with `pdo_mysql`, a MySQL server, Node 20+. Point `DB_HOST` at
`127.0.0.1`, then:

```bash
composer install && npm install
php artisan migrate --seed && php artisan storage:link
php artisan serve            # http://localhost:8000
php artisan reverb:start     # websockets
php artisan queue:work       # broadcasts run through the queue
npm run dev
```

---

## Data model

| Table        | Purpose                                                            |
|--------------|--------------------------------------------------------------------|
| `users`      | Soft-auth guests: nickname, avatar, session_id, ip, is_blocked, last_active_at |
| `tracks`     | Uploaded audio: title, file_path, duration, uploaded_by            |
| `queues`     | Play queue: track_id, status (`pending`/`playing`/`played`), created_at (FIFO order) |
| `skip_votes` | One row per (track, user); unique constraint prevents double votes |
| `settings`   | Key-value store (e.g. `station_status`)                            |

## HTTP surface

| Method | Route                       | Guard            | Purpose                          |
|--------|-----------------------------|------------------|----------------------------------|
| POST   | `/join`                     | —                | Create guest session             |
| POST   | `/leave`                    | guest.session    | End session                      |
| POST   | `/heartbeat`                | guest.session    | Refresh `last_active_at`         |
| POST   | `/tracks`                   | guest.session    | Upload MP3 → queue (max 15 MB)   |
| POST   | `/skip`                     | guest.session    | Vote to skip current track       |
| GET    | `/api/next-track`           | liquidsoap token | Pop next track for Liquidsoap    |
| *      | `/admin/*`                  | admin            | Station / queue / user controls  |

## WebSocket events (channel `station`)

`TrackChanged` · `QueueUpdated` · `VoteCountUpdated` · `UserJoined` ·
`UserLeft` · `UserKicked` · `StationToggled`

Skip-vote rule: a track skips when votes **>50%** of *active* users (those whose
`last_active_at` is within `ACTIVE_USER_WINDOW` seconds, default 30). Tunable via
`SKIP_VOTE_THRESHOLD` / `ACTIVE_USER_WINDOW`.

---

## Key files

- `app/Services/QueueService.php` — queue advance + payload builders
- `app/Services/SkipVoteService.php` — vote tally + skip decision
- `app/Services/LiquidsoapClient.php` — telnet `request.skip`
- `app/Http/Controllers/Api/LiquidsoapController.php` — `/api/next-track`
- `docker/liquidsoap/radio.liq` — the audio engine
- `routes/web.php`, `routes/api.php`, `routes/features/*` — routing
