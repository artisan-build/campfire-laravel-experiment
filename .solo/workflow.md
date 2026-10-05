# Workflow: campfire-laravel-experiment

**EXPERIMENT, with a possible future.** This is a fork of basecamp/once-campfire-laravel (git remote `upstream`), DHH's
agent-led Laravel port of Campfire. The question it answers: *can Campfire-in-Laravel run on Laravel Cloud the Laravel
way, with every resource that can sleep or scale to zero doing so, and with horizontal scaling that works?* It is the last
of the Campfire experiments (siblings: campfire-experiment = Rails, campfire-rust-experiment = Rust). If it succeeds,
Artisan Build may maintain it as "Campfire optimized for Laravel Cloud", so code quality matters more than in the siblings.

Upstream is NOT expected to be maintained. **Minimal diff is NOT a goal.** Wherever Laravel has a first-party way to do
something (migrations, Eloquent, the Storage facade, broadcasting, queues, cache, sessions, config/env), use it, even when
that means replacing upstream code wholesale. Record every such replacement and its rationale.

## Phase & mode
- phase: **laravel-native** (from 2026-10-05). The experiment answered "can it run on Cloud?": yes. Now the work is
  "make it really Laravel-like", aiming at a **one-click install**. Upstream will never be maintained, so **no Rails default
  survives unless it has a real advantage over the Laravel way** (Ed, 2026-10-05). Rails cookie/CSRF formats, `/rails/...` URL
  shapes, Hotwire/importmap and the Rails asset build are all on the table.
- **Work in BRANCHES with PRs** (Ed, 2026-10-05). One concern per branch. Never commit to `main` directly (the one exception is the
  presence-without-polling pass that was already in flight on main when this changed).
- Merge policy: **merge on green CI** (Ed, 2026-10-05). Squash-merge once every required CI check passes on the PR head. A
  PR whose brief says "do not merge" overrides this.
- Deploy target: the Artisan Build Laravel Cloud org. Ed has pre-authorised creating and deploying this experiment's OWN
  Cloud app and its resources. Nothing else in the org may be touched (the Rails and Rust Campfire apps are read-only).

## Hard gate
- `composer ready` (pint, Larastan, then `composer test` on Postgres) green on the committed SHA, on PHP 8.5 (the CI target).
- GitHub Actions CI is REQUIRED for every PR and is the merge gate. `.github/workflows/ci.yml` runs four jobs:
  `tests (PHP 8.5, Postgres 18)`, `pint`, `larastan`, `audit`. Larastan runs at **level 5** over a `phpstan-baseline.neon`
  holding 62 pre-existing errors; never regenerate the baseline to absorb a new error, fix the error.
- Only one branch at a time may deploy to the production Cloud env. Coordinate through brain; live sleep measurements need a quiet env.
- Behaviour that matters is LIVE behaviour on Cloud. Live-verify user-visible behaviour (standing order 5).

## Agent-role constraints
- none. Fleet bindings come from `~/Herd/brain/agents.json`.

## Hard rules for this repo
- Never set a Cloud env var for a Cloud-provisioned resource. DB, cache, queue, bucket and websocket credentials are
  injected (the `laravel-cloud-deploy` skill). App secrets may be set by hand. Secrets never go on disk or into git.
- `.cloud/config.json` is committed (brain standing policy).
- Never enable any GitHub workflow that publishes images.
- `gh` defaults to `artisan-build/campfire-laravel-experiment` (set 2026-10-05). In a fresh clone, run
  `gh repo set-default artisan-build/campfire-laravel-experiment` first, or pass `--repo`: otherwise gh can resolve to Basecamp's upstream.
- Worktrees need a REAL `composer install`. A symlinked `vendor/` autoloads `App\` from the main checkout, so the suite goes green
  while proving nothing about the branch.
