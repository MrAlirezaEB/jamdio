#!/usr/bin/liquidsoap

# ==========================================================================
# Jamdio radio source
#
#  - Polls the Laravel API (/api/next-track) for the next user-submitted song.
#  - Falls back to a local playlist when the user queue is empty.
#  - Exposes a "radio.skip" telnet command so Laravel can force-skip a track.
#  - Streams MP3 to the Icecast mountpoint.
#
# Tested against savonet/liquidsoap:v2.2.5.
# ==========================================================================

# ---- Configuration (from environment) -----------------------------------
api_base     = environment.get(default="http://app", "JAMDIO_API_BASE")
api_token    = environment.get(default="liquidsoap-internal-token", "LIQUIDSOAP_API_TOKEN")
fallback_dir = environment.get(default="/srv/fallback", "LIQUIDSOAP_FALLBACK_DIR")

icecast_host     = environment.get(default="icecast", "ICECAST_HOST")
icecast_port     = int_of_string(environment.get(default="8000", "ICECAST_PORT"))
icecast_mount    = environment.get(default="/stream", "ICECAST_MOUNT")
icecast_password = environment.get(default="hackme", "ICECAST_SOURCE_PASSWORD")

next_track_url    = "#{api_base}/api/next-track"
track_started_url = "#{api_base}/api/track-started"

# How long to wait between polls while the user queue is empty. request.dynamic
# retries a failed/empty fetch immediately, which would hammer the API many
# times per second; this throttles the actual HTTP call to a sane cadence.
empty_poll_interval = 3.0

# ---- Telnet control interface --------------------------------------------
settings.server.telnet.set(true)
settings.server.telnet.bind_addr.set("0.0.0.0")
settings.server.telnet.port.set(1234)

log.level.set(3)

# ---- Dynamic user-queue source -------------------------------------------
# Fetch the next track URI from Laravel. An empty body means "nothing queued".
# When the queue is empty we back off to empty_poll_interval so request.dynamic's
# immediate retries don't flood the API.
last_empty_poll = ref(0.)

def fetch_next() =
  if time() - last_empty_poll() < empty_poll_interval then
    []
  else
    log("Polling #{next_track_url} for next track")
    try
      response =
        http.get(
          next_track_url,
          headers=[("Authorization", "Bearer #{api_token}")],
          timeout=5.0
        )

      if response.status_code == 200 then
        uri = string.trim(response)
        if uri != "" and file.exists(uri) then
          log("Next user track: #{uri}")
          last_empty_poll := 0.
          [request.create(uri)]
        else
          last_empty_poll := time()
          []
        end
      else
        log("next-track returned status #{response.status_code}")
        last_empty_poll := time()
        []
      end
    catch err do
      log("Error fetching next track: #{string_of(err)}")
      last_empty_poll := time()
      []
    end
  end
end

user_queue = request.dynamic.list(id="user_queue", fetch_next)

# ---- Fallback playlist (24/7 background music) ---------------------------
fallback_playlist =
  playlist(
    id="fallback",
    mode="randomize",
    reload_mode="watch",
    fallback_dir
  )

# Absolute safety net so the source never dies, even with an empty playlist.
safety = blank(id="safety", duration=2.0)

# Prefer user tracks, but only switch on track boundaries so we never cut a
# song mid-play. Falls through to the playlist, then to silence.
radio =
  fallback(
    id="radio",
    track_sensitive=true,
    [user_queue, fallback_playlist, safety]
  )

radio = mksafe(radio)

# ---- Notify Laravel when a track actually goes on air --------------------
# This is the real track boundary. Laravel uses it to promote the buffered
# track to "now playing" (it does NOT advance on the prefetch above, so a
# freshly uploaded track stays visible in "Up next" until it truly starts).
def on_started(m) =
  file = m["filename"]
  if file != "" then
    log("Track started on air: #{file}")
    try
      ignore(
        http.post(
          track_started_url,
          headers=[
            ("Authorization", "Bearer #{api_token}"),
            ("Content-Type", "application/x-www-form-urlencoded")
          ],
          data="file=#{url.encode(file)}",
          timeout=5.0
        )
      )
    catch err do
      log("Error notifying track-started: #{string_of(err)}")
    end
  end
end

radio = source.on_track(radio, on_started)

# ---- Force-skip command (called by Laravel via telnet) -------------------
server.register(
  namespace="radio",
  description="Skip the currently playing track.",
  usage="skip",
  "skip",
  fun (_) -> begin
    source.skip(radio)
    "OK"
  end
)

# ---- Output to Icecast ---------------------------------------------------
output.icecast(
  %mp3(bitrate=128),
  host=icecast_host,
  port=icecast_port,
  password=icecast_password,
  mount=icecast_mount,
  name="Jamdio",
  description="Collaborative online radio",
  radio
)
