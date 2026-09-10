## What this changes

<!-- One or two sentences. If it fixes an issue, link it. -->

## Why

<!-- What was wrong, or what was misleading. Not what you typed — the diff already says that. -->

## Checks

- [ ] `php -l` passes on every file I touched.
- [ ] `php tests/smoke.php` passes.
- [ ] A behaviour change is pinned by a check in `tests/smoke.php`.
- [ ] Output is escaped, input is sanitised, and anything that changes state checks a capability and a nonce.
- [ ] No new runtime dependency, and nothing loaded from a CDN.
- [ ] If this changes what the plugin sends to SendBeam, `readme.txt`'s **External service** section says so.
- [ ] If this adds an option, transient or user meta, `uninstall.php` removes it.
