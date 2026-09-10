# Contributing

Thanks for looking. This is the official SendBeam plugin for WordPress, and it is deliberately small: a
block, four shortcodes, pop-ups, an opt-in box and a `wp_mail()` relay. Changes that keep it that way are
much easier to accept than changes that grow it.

## Before you start

For anything beyond a typo, **open an issue first**. It is a short conversation and it saves you writing
something we then ask you to change. If you are not sure whether an idea fits, ask — the answer is often yes
with a different shape.

Questions about SendBeam itself — your account, plans, the API, deliverability — are better at
<https://sendbeam.io/contact> than in this repository.

## Reporting a bug

Use the **Bug report** template. The version fields are not bureaucracy: most reports here turn out to depend
on the WordPress version, the PHP version, or another plugin also filtering `wp_mail()`. Include them and we
can usually reproduce it the same day.

If it is exploitable, do not open an issue — see [SECURITY.md](SECURITY.md).

## Working on a change

No build step. The block uses the `wp.*` globals and ships `index.asset.php` by hand, so cloning the
repository into `wp-content/plugins/` is enough to run it.

```bash
php -l sendbeam.php            # syntax
php tests/smoke.php            # output checks against WordPress stubs — no WordPress install needed
```

`tests/smoke.php` runs the plugin against a small set of stubs in `tests/wp-stubs.php`. It is fast, it needs
nothing installed, and it is where a behaviour change should be pinned. If you add a stub, make it behave the
way WordPress actually behaves — a stub that is more forgiving than the real function hides the bug you were
trying to catch.

CI runs both on PHP 7.4 and 8.3, plus
[WordPress Plugin Check](https://github.com/WordPress/plugin-check-action) — the same automated checks the
wordpress.org review uses. All three must pass.

## House style

- **Escape on output, sanitise on input, and check the capability and the nonce** on anything that changes
  state. There are no exceptions in this codebase and we will not add one.
- Every global function is prefixed `sendbeam_`. Every translatable string carries the `sendbeam` text domain.
- Comments explain *why*, not what. If the reason a line exists is not obvious in six months, write it down;
  if it is obvious, do not.
- Match the surrounding code rather than your own preferences. Tabs, WordPress brace style, real sentences in
  comments.
- No new runtime dependencies. Nothing loaded from a CDN — everything the plugin needs ships with it.

## Pull requests

One change per pull request. Fill in the template; the checklist is short and each line has caught a real
problem before.

Keep the history readable: a clear subject line in the imperative, and a body that says what was wrong rather
than what you typed. Maintainers squash on merge, so the pull request title becomes the commit.

## Licence

By contributing you agree that your work is licensed under the
[GPL-2.0-or-later](LICENSE), as WordPress.org requires.
