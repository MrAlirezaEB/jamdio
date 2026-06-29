# Fallback playlist

Drop `.mp3` files in this directory. Liquidsoap plays them on shuffle whenever
the user queue is empty (24/7 background music). The folder is hot-reloaded
(`reload_mode="watch"`), so new files are picked up without a restart.

If this folder is empty the stream falls back to short silence — add at least
one track for a usable station.
