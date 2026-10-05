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
- Merge policy: **PENDING Ed's answer (human review vs gate on CI).** Until it's recorded here: open the PR, get CI green, do NOT merge.
- Deploy target: the Artisan Build Laravel Cloud org. Ed has pre-authorised creating and deploying this experiment's OWN
  Cloud app and its resources. Nothing else in the org may be touched (the Rails and Rust Campfire apps are read-only).

## Hard gate
- `composer test` (PHPUnit, on Postgres) and `vendor/bin/pint --test` green on the committed SHA, on PHP 8.5 (the CI target).
  Static analysis (Larastan) joins the gate once the CI PR lands. GitHub Actions CI is REQUIRED for every PR; the fork had none
  as of 2026-10-05.
- Only one branch at a time may deploy to the production Cloud env. Coordinate through brain; live sleep measurements need a quiet env.
- Behaviour that matters is LIVE behaviour on Cloud. Live-verify user-visible behaviour (standing order 5).

## Agent-role constraints
- none. Fleet bindings come from `~/Herd/brain/agents.json`.

## Hard rules for this repo
- Never set a Cloud env var for a Cloud-provisioned resource. DB, cache, queue, bucket and websocket credentials are
  injected (the `laravel-cloud-deploy` skill). App secrets may be set by hand. Secrets never go on disk or into git.
- `.cloud/config.json` is committed (brain standing policy).
- Never enable any GitHub workflow that publishes images.
