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
| Video posters | `ffmpeg` via `Symfony\Process` | the same, when `ffmpeg` is on `PATH`; no poster when it is not |
| Web Push keys | `storage/vapid.json`, generated per host | generated once onto the account row, shared by every replica |
| Realtime | `bin/cable`, a Workerman Action Cable server fed by a local append-only `storage/events.log` | Laravel broadcasting over Reverb; the file, the server and Workerman are deleted |
| Channel authorization | signed Turbo stream names, because the client named a channel *class* | `routes/channels.php`; the client names the channel and the server decides |
| Typing / presence | messages up the Action Cable socket | small HTTP endpoints, because a managed Reverb cannot call the app back |
| Sessions | `file` driver on local disk | `cookie` — shared across replicas with no store to keep awake |
| Cache | `file` driver on local disk | `database`, on the same serverless Postgres |
| Queue | SQLite file + an in-container worker | the configured queue; on Cloud, the managed queue |

The browser side changed in exactly one place. turbo-rails routes every `cable.subscribeTo` call and
every stream-source element through one consumer object, so
`public/assets/campfire/echo/consumer.js` implements that interface on top of Laravel Echo and
pusher-js. **No Stimulus controller and no helper was touched.**

Rails compatibility is **dropped**: this fork cannot read an existing Rails database or uploads
directory. Rails' cookie and signed-id formats are still implemented (`app/Support/RailsCrypto.php`)
and still tested against the pinned reference, because the login cookie format is cheap to keep and
upstream's oracles still pass.

## Deploying to Laravel Cloud

You need the [Cloud CLI](https://cloud.laravel.com) and a GitHub repository. Everything below is a
real command that was run to produce the live deployment.

```sh
# 1. The application, pointed at your fork.
cloud app:create --name=campfire --repository=<owner>/<repo> --source-provider=github --region=us-east-2
# Creating the app also creates a "production" environment on your default branch.

# 2. Bind the repository so later commands know which app they mean.
cloud repo:config <application id>

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
themselves onto the account row. The build command is Cloud's default and the deploy command is
`php artisan migrate --force`.

Check what an instance actually resolved:

```sh
cloud cmd:run production --cmd='php artisan campfire:doctor'
```

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
Reverb configured the app falls back to Turbo's own refresh-on-reconnect and still works, just not
instantly.

## Development and tests

```sh
docker run -d --name campfire-pg -e POSTGRES_PASSWORD=secret -e POSTGRES_USER=campfire \
  -e POSTGRES_DB=campfire -p 5432:5432 postgres:18-alpine
docker exec campfire-pg psql -U campfire -d campfire -c 'CREATE DATABASE campfire_test OWNER campfire;'
composer install && cp .env.example .env && php artisan key:generate && php artisan migrate
composer test          # PHPUnit, against Postgres
vendor/bin/pint        # formatting
```

The suite runs on Postgres because production runs on Postgres and the full text search only exists
there. `phpunit.xml` pins the database name; host, port and credentials come from `.env`.

## Known differences from upstream

- **No Rails data compatibility.** The schema is Laravel's; it cannot adopt a Rails SQLite file or an
  Active Storage directory. Objects live at `blobs/<key>` and `variants/<key>/<digest>.<format>`.
- **Stricter about images.** libvips decoded a PNG with a bad IDAT CRC; GD and Imagick refuse it. An
  image the imaging library cannot read is now a 422 rather than an accepted upload.
- **Search tokenises differently.** Postgres keeps `pixel.png` whole where FTS5's porter tokenizer
  split it, so the indexed text and the query both go through one normaliser
  (`app/Support/Search.php`). Punctuation is not searchable.
- **Broadcast fragments are compressed.** A managed Reverb application caps a frame at 10 000 bytes
  and one rendered message is about 9 000 bytes of HTML, so a fragment past the budget is gzipped and
  the client inflates it. Anything still too large becomes a pointer the client resolves over HTTP.
- **Presence is HTTP-driven.** A managed Reverb cannot call the app, so the browser reports presence
  and a stale entry expires after 60 seconds, as it already did upstream.
- `campfire:backup` is gone. Cloud snapshots Postgres and the bucket holds the uploads.

The original upstream benchmarks are not reproduced here; they measured a different storage engine
and a different socket server.
