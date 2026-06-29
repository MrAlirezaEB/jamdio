# Project Specification: Collaborative Online Radio Platform
**Tech Stack:** Laravel 13 (Backend & API), Icecast + Liquidsoap (Audio Streaming), Laravel Reverb (WebSockets for Real-time events), Inertia & Vue3 (Frontend Player & Admin), Docker (Dockerize setup for local and production).

---

## 1. Project Overview
A web-based collaborative radio platform where an Admin controls the station, and unauthenticated Users can join a live audio session using a temporary Nickname and Avatar. Users listen to a perfectly synchronized live stream, submit music tracks to a global queue, and vote to skip tracks.

---

## 2. Core Architecture & Audio Flow
1. **Continuous Background Play:** Icecast + Liquidsoap runs 24/7. If the User Queue is empty, Liquidsoap falls back to a default folder/playlist of music.
2. **Dynamic Queue:** When a user uploads a song, Laravel stores it and updates the DB queue. Liquidsoap polls a Laravel API endpoint (`/api/next-track`) to fetch the next song from the user queue. Once played, the track is popped out of the queue.
3. **Synchronized Playback:** Since Icecast is a streaming server, all connected users naturally hear the exact same audio timing simultaneously.

---

## 3. Database Schema Requirement

### `users` (Soft Auth)
- `id` (Primary)
- `nickname` (string)
- `avatar_path` (string)
- `session_id` (string)
- `ip_address` (string)
- `is_blocked` (boolean, default: false)
- `last_active_at` (timestamp)

### `tracks`
- `id` (Primary)
- `title` (string)
- `file_path` (string)
- `duration` (integer - seconds)
- `uploaded_by` (unsignedBigInteger, nullable - links to users)

### `queues`
- `id` (Primary)
- `track_id` (ForeignKey)
- `status` (enum: 'pending', 'playing', 'played')
- `created_at` (timestamp - used for ordering)

### `skip_votes`
- `id` (Primary)
- `track_id` (ForeignKey)
- `user_id` (ForeignKey)

### `settings`
- `key` (string, e.g., 'station_status')
- `value` (string, e.g., 'on' / 'off')

---

## 4. Detailed Feature Breakdown

### Feature 1: User "Soft Authentication" & Player Page
* **Access:** No traditional email/password registration.
* **Flow:** When a guest visits the site, they see a modal asking for a **Nickname** and a selection of predefined **Avatars**.
* **Session:** Store this info in Laravel Session/Cookie and save a record in the `users` table to track online status.
* **UI:** An interactive dashboard featuring:
    * The live HTML5 Audio Player (streaming from Icecast mountpoint).
    * Current playing track info.
    * Live list of online users in the session.
    * A "Submit Track" button.
    * A "Skip Vote" button with a live counter (e.g., `3/7 Votes`).

### Feature 2: User Queue & Upload Management
* **Upload:** Users can upload `.mp3` files. Max size restricted (e.g., 15MB).
* **Queue Entry:** Upon successful upload, the track is added to the `tracks` table and appended to `queues` with `status = 'pending'`.
* **Consuming Queue:** Laravel provides a secure internal endpoint for Liquidsoap. When a song ends, Liquidsoap calls Laravel -> Laravel marks the current song as `'played'`, changes the next pending song to `'playing'`, and returns the file path to Liquidsoap.

### Feature 3: Real-time Skip Vote System
* **Logic:** A track skips **ONLY** if more than 50% of the currently *active* session users vote to skip.
* **Formula:** `Active Users` = Users whose `last_active_at` is within the last 30 seconds.
* **Workflow:**
    1. User clicks "Skip". Laravel registers the vote in `skip_votes`.
    2. Laravel calculates: `Total Current Votes` vs `(Active Users Count / 2)`.
    3. If threshold is passed, Laravel triggers a backend command to Liquidsoap (`request.skip`) to instantly kill the current track.
    4. WebSockets broadcast the updated vote count instantly to all clients UI.

### Feature 4: Admin Control Panel
* **Station Toggle:** A master switch to turn the station 'On' or 'Off'. If 'Off', Liquidsoap stops streaming or plays a "Station Offline" loop, and the frontend player deactivates.
* **Queue Control:** Admin can see the entire live queue list, re-order tracks via drag-and-drop, delete tracks from the queue, or manually force a skip.
* **User Management:** * A live data table showing all connected users (IP, Nickname, Join Time).
    * **Kick Button:** Sends a real-time WebSocket event (`UserKicked`) forcing that specific user's frontend to clear their session and redirect them to an error page.
    * **Block Button:** Updates `is_blocked = true` and flags their IP address. Middleware will prevent them from re-joining.

---

## 5. Real-time Events (WebSockets)
Please implement the following WebSocket events using Laravel Echo:
* `TrackChanged`: Broadcasts new track details when a new song starts playing.
* `QueueUpdated`: Broadcasts to all users when a new song is added or modified in the queue.
* `VoteCountUpdated`: Broadcasts the new skip vote ratio (`current_votes/required_votes`).
* `UserJoined` / `UserLeft`: Updates the live active user list widget.
* `UserKicked`: Targeted broadcast to terminate a specific user's session.

---

## 6. Implementation Instructions
Act as an expert Full-Stack Laravel developer. Please generate this project step-by-step:
1. Start with the **Database Migrations and Models** including relations.
2. Implement the **Soft Auth / Guest Session Controller** and Middlewares.
3. Write the **Queue Logic & Liquidsoap integration API controllers**.
4. Implement the **Real-time Skip Vote logic and WebSocket Events**.
5. Finally, write the **Admin Controller (Kick/Block/Queue Management)**.
