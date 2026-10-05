# Campfire, optimised for Laravel Cloud

An **independent fork, not affiliated with 37signals.** It starts from
[basecamp/once-campfire-laravel](https://github.com/basecamp/once-campfire-laravel), DHH's agent-led
Laravel port of ONCE Campfire, and takes it somewhere that port deliberately did not go: a Laravel
app that runs on Laravel Cloud, horizontally, with every resource that can sleep doing so.

Campfire itself is 37signals' work and theirs alone. Nothing here is endorsed by them, and this fork
does not track upstream.

## What changed, and why

Upstream is a faithful Laravel reimplementation of a Rails app designed to run as **one container on
one host**. Three of its load-bearing parts are single-host by construction, and a second replica
breaks all three. Each was replaced with the first-party Laravel answer rather than adapted.

| | Upstream | Here |
|---|---|---|
| Schema | `database/schema.sql` (Rails' SQLite dump) + `database/versions.json`, loaded by `campfire:install` | real migrations, `php artisan migrate` |
| Database | SQLite with a custom `SQLiteGrammar`, plus a second SQLite file for the queue | Postgres everywhere; both are gone |
| Search | an FTS5 virtual table queried with `body MATCH ?` | `$table->fullText()` + `whereFullText()` on Postgres |
| Uploads | `file_put_contents` into Rails' Active Storage on-disk layout; zero use of `Storage` | every byte through the `Storage` facade, on whatever disk is configured |
| Serving uploads | `response()->file()` from local disk | a redirect to a short-lived signed URL when the disk can sign one, streamed otherwise |
| Image variants | `vips` via `Symfony\Process` | `intervention/image` on Imagick or GD — no binary needed |
| Video posters | `ffmpeg` via `Symfony\Process` | the same, against a pinned static `ffmpeg` the build step vendors into the app; no poster when neither that nor `PATH` has one |
| Web Push keys | `storage/vapid.json`, generated per host | generated once onto the account row, shared by every replica |
| Realtime | `bin/cable`, a Workerman Action Cable server fed by a local append-only `storage/events.log` | Laravel broadcasting over Reverb; the file, the server and Workerman are deleted |
| Channel authorization | signed Turbo stream names, because the client named a channel *class* | `routes/channels.php`; the client names the channel and the server decides |
| Typing / presence | messages up the Action Cable socket | small HTTP endpoints, because a managed Reverb cannot call the app back |
| Sessions | `file` driver on local disk | `cookie` — shared across replicas with no store to keep awake |
| Cache | `file` driver on local disk | `database`, on the same serverless Postgres |
| Queue | SQLite file + an in-container worker | the configured queue; on Cloud, the managed queue |

Room messages now use a page-owned Alpine stream over Laravel Echo. Initial history is rendered by
Blade; posts, edits, deletes and boosts use the same `MessageResource` JSON contract over HTTP and
Reverb, including optimistic reconciliation by `client_message_id`. The default import map does not
load Turbo, Action Cable compatibility, Turbo stream rendering, or the message-only Stimulus stack.

That cutover deliberately gives up Turbo prefetch, view transitions and restoration visits. Normal
links and forms continue to use browser navigation. A temporary `config('campfire.json_message_stream')`
rollback switch defaults to the JSON implementation and retains the old Turbo entry point, stream
consumer, message controllers/models and rendered broadcast path for PR9 to delete. The retained path
is not imported, subscribed, or broadcast while the default is active.

This fork supports **fresh installs**, not migration from an existing Rails database or uploads
directory. Rails cookie and CSRF formats remain the active implementation while the frontend is
migrated, but they are no longer compatibility guarantees: Laravel-native sessions, encryption and
CSRF are the approved direction. Signed IDs still back upload/avatar URLs, and SGIDs are stored in
rich text, so those formats remain intentionally supported.

## Deploying to Laravel Cloud

You need the [Cloud CLI](https://cloud.laravel.com) and a GitHub repository. Everything below is a
real command that was run to produce the live deployment. This is the current fresh-install path;
one-click provisioning is the target, not functionality this revision claims to provide.

```sh
# 1. The application, pointed at your fork.
cloud app:create --name=campfire --repository=<owner>/<repo> --source-provider=github --region=us-east-2
# Creating the app also creates a "production" environment on your default branch.

# 2. Bind the repository so later commands know which app they mean.
cloud repo:config <application id>
# With more than one organisation token on the machine, `repo:config` cannot resolve the
# organisation before an application exists. Write .cloud/config.json with just
# {"organization_id": "org-..."} first, then run app:create, then repo:config.

# 3. Serverless Postgres. The "Dev" preset is the only one that suspends when idle
#    (cu 0.25, suspend_seconds 300); "Prod" and "Scale" set suspend_seconds to 0.
cloud db-cluster:create --name=campfire --type=neon_serverless_postgres_18 --region=us-east-2

# 4. A WebSocket cluster and an application on it.
cloud ws-cluster:create --name=campfire-reverb --region=us-east-2 --max-connections=100
cloud ws-app:create <ws cluster id> --name=campfire --ping-interval=60 --activity-timeout=30 \
  --allowed-origins='*'

# 5. Attach the database and Reverb. Cloud injects their configuration; set nothing yourself.
cloud env:update production --database-id=<schema id> --websocket-application-id=<ws app id>

# 6. A bucket for uploads, and a key for it.
cloud bucket:create --name=campfire --region=us-east-2 --visibility=private --jurisdiction=default \
  --key-name=campfire --key-permission=read_write --allowed-origins=<your app url>

# 7. Attach the bucket. The CLI cannot do this; the REST API can.
curl -X PATCH https://cloud.laravel.com/api/environments/<environment id> \
  -H "Authorization: Bearer $CLOUD_TOKEN" -H 'Content-Type: application/json' \
  -d '{"filesystem_keys":[{"id":"<bucket key id>","disk":"s3","is_default_disk":true}]}'

# 8. The managed queue, scaled to zero when idle.
cloud queue:create production --name=default --size=mq.flex.256mb --max-workers=5
cloud instance:update <queue instance id> --min-replicas=0 --scale-to-zero=true --scale-to-zero-timeout=1

# 9. Push. Push-to-deploy is on by default.
git push origin main
```

**Set no environment variables.** Cloud generates `APP_KEY` and injects `DB_*`, `QUEUE_CONNECTION`,
the `AWS_*` group with `FILESYSTEM_DISK`, and the `REVERB_*` group. Campfire needs nothing else:
`SECRET_KEY_BASE` falls back to a value derived from `APP_KEY`, and the Web Push keys generate
themselves onto the account row. The deploy command is `php artisan migrate --force`.

### Vendor ffmpeg into the build, for video posters

Cloud's PHP runtime has Imagick and **no ffmpeg**, so without this step a video attachment gets no
poster and no duration. `campfire:provision-ffmpeg` downloads one pinned static LGPL build from
[BtbN/FFmpeg-Builds](https://github.com/BtbN/FFmpeg-Builds), checks it against the release's own
published SHA-256, throws away everything but `ffmpeg` and `ffprobe`, and installs the pair into
`runtime/ffmpeg/bin` inside the application root. That directory is gitignored and rebuilt on every
deploy. Read the environment's current build command first and append to it, rather than replacing
it:

```sh
cloud environment:get production --json --fields=buildCommand

cloud environment:update production --force \
    --build-command="<the existing command> && php artisan campfire:provision-ffmpeg"
```

Nothing needs to be configured for the app to find it. `config('campfire.ffmpeg.directory')` points
at that path and `App\Support\Media` looks there *before* it looks at `PATH`, so there is no
environment variable to set and no reliance on the build having prepended anything to `PATH` — which
is exactly what silently failed when the Rust sibling tried this. A developer's own ffmpeg on `PATH`
keeps working untouched.

Measured in an arm64 container: the archive expands to **291.2 MB**, the trimmed install is
**179.7 MB** (ffmpeg 90.0 MB, ffprobe 89.8 MB), and the whole download-verify-extract-trim takes
**8 s**. The build image needs `tar` and `xz`; the command checks for both and exits non-zero with
the package to install if either is missing.

To check the build step the way CI does, run it on linux/arm64:

```sh
docker run --rm --platform linux/arm64 --user root -v "$PWD:/app" -w /app \
    serversideup/php:8.4-cli sh -c '
        apt-get update -qq && apt-get install -y -qq xz-utils
        install-php-extensions gd
        php artisan campfire:provision-ffmpeg --force
        php artisan campfire:doctor
        vendor/bin/phpunit --group ffmpeg
    '
```

That leaves **Linux** binaries in a bind-mounted `runtime/`, which macOS reports as executable
because the permission bits say so. Running them on the host dies with exit 126, so Campfire only
consults `runtime/ffmpeg/bin` on a platform it actually vendors a build for; on macOS the directory
is ignored and `PATH` answers. Nothing needs cleaning up, and `rm -rf runtime` is safe whenever.

Check what an instance actually resolved:

```sh
cloud cmd:run production --cmd='php artisan campfire:doctor'
```

The build log is **not** evidence. `campfire:doctor` prints the absolute path it resolved for
`ffmpeg` and `ffprobe`, or `absent`, which is the only thing that answers whether the application
found them.

**The queue worker is a different host.** Cloud's managed queue runs jobs off the application
instance, and `DeliverMessageNotifications` reaches ffmpeg whenever a bot's webhook replies with
`video/mp4` — it creates an attachment, which makes a poster. So ask the worker too:

```sh
cloud cmd:run production --cmd='php artisan campfire:doctor --queue'
```

It dispatches a job that reports the worker's own hostname and the paths *it* resolved, waits up to
60 s for the answer, and prints both tables side by side. If no worker answers it says so and exits
non-zero rather than implying agreement.

It prints the driver for every subsystem and round-trips the database, the cache and the disk. It
prints names, never credentials.

### Sleeping and scaling

Scale-to-zero and a replica count are in tension: with sleep mode on, the environment runs **one**
container whatever `min_replicas` says. Turn sleep mode off to run two:

```sh
curl -X PATCH https://cloud.laravel.com/api/instances/<app instance id> \
  -H "Authorization: Bearer $CLOUD_TOKEN" -H 'Content-Type: application/json' \
  -d '{"uses_sleep_mode":false,"scaling_type":"custom","min_replicas":2,"max_replicas":2}'
```

A replica-count change needs a deploy to take effect.

## Running it anywhere else

```sh
docker build -t campfire .
docker run --rm -p 8080:80 \
  -e DB_HOST=<host> -e DB_DATABASE=campfire -e DB_USERNAME=campfire -e DB_PASSWORD=<password> \
  -v campfire:/rails/storage campfire
```

Postgres is required. `APP_KEY` is generated on first boot and kept in the mounted volume's `.env`;
`HTTP_PORT` changes the listening port. The image runs nginx, PHP-FPM and a queue worker. Without
Reverb configured, pages and HTTP mutations still work, but cross-tab message, typing, unread and
sidebar updates require a reload. The default frontend does not silently fall back to Turbo.

## Development and tests

```sh
docker run -d --name campfire-pg -e POSTGRES_PASSWORD=secret -e POSTGRES_USER=campfire \
  -e POSTGRES_DB=campfire -p 5432:5432 postgres:18-alpine
docker exec campfire-pg psql -U campfire -d campfire -c 'CREATE DATABASE campfire_test OWNER campfire;'
composer install && cp .env.example .env && php artisan key:generate && php artisan migrate
composer test          # PHPUnit, against Postgres
vendor/bin/pint        # formatting
composer tailwind      # rebuild the committed application stylesheet; Node is not required
```

The suite runs on Postgres because production runs on Postgres and the full text search only exists
there. `phpunit.xml` pins the database name; host, port and credentials come from `.env`.

The application serves the committed, fingerprinted stylesheet, so installs and production runtime
do not need Tailwind or Node. `composer tailwind` downloads the pinned standalone binary for the
current platform into ignored `runtime/`, verifies its SHA-256, and deterministically rebuilds the
committed asset when frontend source changes.

## Known differences from upstream

- **No Rails data compatibility.** The schema is Laravel's; it cannot adopt a Rails SQLite file or an
  Active Storage directory. Objects live at `blobs/<key>` and `variants/<key>/<digest>.<format>`.
- **Stricter about images.** libvips decoded a PNG with a bad IDAT CRC; GD and Imagick refuse it. An
  image the imaging library cannot read is now a 422 rather than an accepted upload.
- **Search tokenises differently.** Postgres keeps `pixel.png` whole where FTS5's porter tokenizer
  split it, so the indexed text and the query both go through one normaliser
  (`app/Support/Search.php`). Punctuation is not searchable.
- **Room mutations use bounded JSON.** The default frontend broadcasts `MessageResource` data for
  posts and edits plus small JSON delete/boost events. Oversized resources carry a bounded sanitized
  preview and an HTTP fetch-required marker; active frames never use Turbo HTML, gzip or pointers.
  The temporary `campfire.json_message_stream=false` rollback path retains those legacy encodings
  until PR9, but it is neither imported nor broadcast while the default is active.
- **Turbo navigation behavior is temporarily absent.** The default nodeless Alpine path does not
  provide Turbo prefetch, view transitions or restoration visits. The false-flag rollback path keeps
  them until PR9.
- **PR5 browser proof is pending.** The committed two-context harness at
  `tests/Browser/pr5-json-stream.mjs` covers the JSON mutation, reconnect, sidebar, media, responsive
  and frame-confidentiality matrix, but branch-head and post-merge managed-Reverb runs remain required.
- **Message presentation residue remains.** Socket-created `/play` messages do not synthesize the
  legacy sound widget or autoplay; local timestamps lack the old full-timestamp hover text; custom
  boosts use the native prompt; and some inactive legacy data hooks remain for rollback.
- **Presence is HTTP-driven.** A managed Reverb cannot call the app, so the browser reports presence
  and a stale entry expires after 60 seconds, as it already did upstream.
- `campfire:backup` is gone. Cloud snapshots Postgres and the bucket holds the uploads.

The original upstream benchmarks are not reproduced here; they measured a different storage engine
and a different socket server.
