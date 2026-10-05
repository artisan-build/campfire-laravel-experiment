# once-campfire-laravel

Native Laravel 13 / PHP 8.4 implementation. `reference/` pins the public Rails source at 659f957; treat it as immutable. Use its models/controllers and `compat/rails_compat.json` as behavior oracles.

Preserve the SQLite schema and uploaded blob paths. The Laravel-native frontend direction supersedes Rails signed/encrypted cookie and CSRF compatibility; use Laravel's native contracts as that work lands. Retain signed-id and sgid formats while routes and stored rich text depend on them. Document deliberate differences in README and update plans/contracts.json after verification. Do not claim browser, realtime or external-service parity from unit tests.

Build production with Docker. Run PHP tests using the production toolchain with dev dependencies installed, or pinned Composer image. Format PHP with `vendor/bin/pint`. Raw test/benchmark artifacts belong in ignored `tmp/`; never commit results.
