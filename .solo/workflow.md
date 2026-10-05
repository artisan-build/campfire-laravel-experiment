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
- phase: experiment
- default mode: A-autonomous. **Commit directly to `main`** in small commits with clear messages, and push. No PRs
  (Ed, 2026-10-05, matching the sibling experiments).
- Deploy target: the Artisan Build Laravel Cloud org. Ed has pre-authorised creating and deploying this experiment's OWN
  Cloud app and its resources. Nothing else in the org may be touched (the Rails and Rust Campfire apps are read-only).

## Hard gate
- `composer test` (PHPUnit) and `vendor/bin/pint --test` green on the committed SHA, run on PHP 8.5 where possible (CI target).
- Behaviour that matters is LIVE behaviour on Cloud. Live-verify user-visible behaviour (standing order 5).

## Agent-role constraints
- none. Fleet bindings come from `~/Herd/brain/agents.json`.

## Hard rules for this repo
- Never set a Cloud env var for a Cloud-provisioned resource. DB, cache, queue, bucket and websocket credentials are
  injected (the `laravel-cloud-deploy` skill). App secrets may be set by hand. Secrets never go on disk or into git.
- `.cloud/config.json` is committed (brain standing policy).
- Never enable any GitHub workflow that publishes images.
