# IPTV Manager

A self-contained PHP + SQLite web app for managing an IPTV channel library, generating M3U playlists, importing an XMLTV TV guide, and previewing channels — including DRM-protected ones — directly in the browser.

![Channel library](docs/screenshots/channels.png)

## Features

**Channel library**
- Every channel can have multiple feeds (stream variants), each encrypted or clear
- Compact channel table with enable/disable status dot, logo, TVG ID, category, language, type, and feed visibility summary
- Search and category filtering with pagination
- Add / edit / delete channels and manage their feeds, or import an existing M3U playlist
- Bulk select and delete channels (select-all on the current page)

**Playlist import**
- Import channels from an M3U/M3U8 playlist — uploaded as a file or fetched from a URL (gzip supported)
- Idempotent: entries sharing a channel name become feeds of one channel; re-imports update feeds in place and keep their enable/visibility settings
- `#KODIPROP` DRM settings (`license_type` / `license_key`, including license request headers) are mapped to the dedicated DRM fields
- Inline SVG icon actions for play, edit, and delete
- Category management: rename to merge, delete empty categories

**Playlist feed**
- Cached M3U feed served on its own port; only enabled channels and feeds are included
- Each feed chooses where it appears: playlist server, clear server, or both
- Cache is versioned per mutation and validated against the SQLite file signature (`X-Playlist-Cache: HIT|MISS`, ETag / 304 support)
- Optional `x-tvg-url` header written from the configured guide source

**TV guide (XMLTV)**

![TV guide](docs/screenshots/tv-guide.png)

- Synchronous XMLTV import over HTTP/HTTPS with gzip support and size caps
- Matches programmes to channels by TVG ID, then by normalized display name
- Transactional imports: a failed or empty source never wipes existing guide data
- Day navigation, category filter, current-time indicator, and pagination
- Guide data lives in its own `epg_programs` table and is pruned as it expires

**DRM support**
- Per-feed DRM type: `None / KODIPROP only`, `ClearKey`, `Widevine`, `PlayReady`
- Dedicated fields for license URL, ClearKey key ID + key, and license request headers
- Written into the generated playlist as `#KODIPROP:inputstream.adaptive.license_type` and `#KODIPROP:inputstream.adaptive.license_key` (license URL plus header segment for Kodi)
- Dedicated DRM fields override matching KODIPROP keys; feeds configured only through the KODIPROP textarea keep working unchanged

**Browser preview**
- Play button on every feed (and on each channel row, using its first enabled feed) opens an inline player dialog
- HLS/DASH streams play through [Shaka Player](https://shaka-player-demo.appspot.com/) (loaded on demand from jsDelivr); MP4/WebM/Ogg use native playback
- DRM streams configure EME with `clearKeys` or license `servers`, and license request headers are attached through Shaka's networking engine

![Adding a DRM channel](docs/screenshots/add-channel.png)

## Screenshots

| | |
|---|---|
| ![Channel library](docs/screenshots/channels.png) | ![TV guide](docs/screenshots/tv-guide.png) |
| Channel library with status dots and icon actions | TV guide with current-time indicator |
| ![Edit channel](docs/screenshots/edit-channel-drm.png) | ![Add channel](docs/screenshots/add-channel.png) |
| Editing a Widevine channel (DRM fields) | New channel form with DRM section |
| ![Sign in](docs/screenshots/sign-in.png) | |
| Sign-in screen | |

## Requirements

- PHP 8.1+ (developed and tested on PHP 8.5) with `pdo_sqlite`
- No Composer dependencies, no external database, no build step

Verify your setup:

```sh
php -m | grep -i pdo_sqlite
```

## Quick start

```sh
cp .env.example .env   # optional — defaults to ports 8000/8001/8002 on 127.0.0.1
./iptv-manager start
```

Then open <http://127.0.0.1:8000> and sign in with **admin / admin**.

```sh
./iptv-manager status     # check all servers
./iptv-manager restart    # apply code changes
./iptv-manager stop       # stop all servers
```

Three servers are started:

| Service | Default URL | Purpose |
|---|---|---|
| Web UI | `http://127.0.0.1:8000` | Administration interface |
| Playlist | `http://127.0.0.1:8001` | Cached M3U feed with every feed assigned to the playlist server (`/` only; other paths redirect) |
| Clear | `http://127.0.0.1:8002` | Cached M3U feed with every feed assigned to the clear server |

You can also run the servers directly:

```sh
set -a; . ./.env; set +a
php -S "$IPTV_HOST:$IPTV_PORT" -t . router.php
php -S "$IPTV_HOST:$IPTV_PLAYLIST_PORT" -t . router.php
php -S "$IPTV_HOST:$IPTV_CLEAR_PORT" -t . router.php
```

### Configuration

Ports and bind address are read from a `.env` file in the project root (see `.env.example`). Real environment variables always override `.env` values.

| Variable | Default | Description |
|---|---|---|
| `IPTV_HOST` | `127.0.0.1` | Bind address for all servers |
| `IPTV_PORT` | `8000` | Web UI port |
| `IPTV_PLAYLIST_PORT` | `8001` | Playlist feed port |
| `IPTV_CLEAR_PORT` | `8002` | Clear-only feed port |
| `PHP_BIN` | `php` | PHP binary used by the launcher |

Example: expose to a trusted LAN on other ports — edit `.env`:

```ini
IPTV_HOST=0.0.0.0
IPTV_PORT=8080
IPTV_PLAYLIST_PORT=8081
IPTV_CLEAR_PORT=8082
```

or override for a single run:

```sh
IPTV_HOST=0.0.0.0 IPTV_PORT=8080 IPTV_PLAYLIST_PORT=8081 IPTV_CLEAR_PORT=8082 ./iptv-manager start
```

### Clear feed

The clear feed exists for players that cannot handle DRM. It serves the same M3U format as the playlist feed, but only includes feeds whose visibility is **clear** or **both**:

- Visibility is per feed and set explicitly: **playlist server only**, **clear server only**, or **both servers**.
- A feed can be encrypted or clear regardless of where it is assigned; the edit page warns when an encrypted feed is exposed on the clear server.
- Feeds keep their original `stream_url` and KODIPROP lines — the servers only filter, they do not relay or rewrite streams.
- A channel with several feeds appears once per feed on a server; the channel name is suffixed with the feed label (or `Feed N`) to keep entries distinguishable.

## Data and storage

Everything lives in a single SQLite file at `data/iptv.sqlite`. On first run the schema and the seeded `admin` account are created automatically, so a fresh clone starts with an empty channel library that you populate through the UI.

- `data/` is git-ignored (database, cache, logs, PID files) — nothing local is committed
- Existing single-stream channels are migrated automatically into one feed each; DRM feeds are initially assigned to the playlist server only, clear feeds to both, preserving the previous output
- Query and playlist caches live in `data/cache/` and are rebuilt automatically
- Channel and feed edits bump a cache version, so the playlist feed reflects changes immediately

### Security notes

- Change the default `admin` password before exposing the service beyond localhost. The seeded account stores a password hash in SQLite; to reset it, delete the `admin` row from the `admins` table and reload the app.
- The built-in PHP server and single-port-per-role routing are intended for local or trusted-network use, not as an internet-facing production deployment.
- Browser DRM playback requires a secure context (`https://` or `localhost`) and a license server that allows cross-origin requests from your origin.

## Project layout

```
index.php        Web UI: routes, actions, views, preview player
lib.php          Schema, migrations, caching, M3U builder, XMLTV importer, DRM helpers, .env loader
playlist.php     Cached M3U endpoint (ETag / 304), reused for the full and clear feeds
playlist-clear.php Clear-server M3U endpoint (feeds assigned to clear or both)
router.php       Per-port routing and style.css cache headers
style.css        UI styling
iptv-manager     start / stop / restart / status launcher
.env.example     Configuration template (copy to .env)
docs/screenshots README screenshots
data/            SQLite DB, cache, logs (git-ignored)
```

## Notes

- The manager does not auto-sync an upstream playlist; channels are managed locally or imported on demand.
- Guide times are displayed in the server's timezone.
- The Shaka Player library is fetched from a CDN on first preview; if the CDN is unreachable, native formats (MP4/WebM/Ogg) still play.
