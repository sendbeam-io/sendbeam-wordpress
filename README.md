# SendBeam for WordPress

Newsletter signup forms, a pop-up and a contact form from your [SendBeam](https://sendbeam.io) account, on a
WordPress site: a **block**, three **shortcodes** and one **settings page**. No embed code to copy around,
nothing stored but three form IDs.

| What | How |
| --- | --- |
| Signup form in a post, page or template | `SendBeam Form` block, or `[sendbeam_form id="…"]` |
| Contact form | `[sendbeam_contact]` (form chosen once in settings) |
| Pop-up after a delay or from a floating button | Settings → SendBeam → Pop-up |
| Your own "Subscribe" button that opens the pop-up | `[sendbeam_popup_button label="Subscribe"]` or any element with `data-sendbeam-open="<form id>"` |
| Track a signup in analytics | `document.addEventListener('sendbeam:submitted', e => …)` |

Forms render as an iframe of the form's hosted page, so they always match what is set up in SendBeam
(fields, double opt-in, thank-you text, the list people join) and the theme's CSS never fights the form's.
The plugin makes no server-side requests; the visitor's browser talks to sendbeam.io directly.

Requires WordPress 6.1+ and PHP 7.4+. Works on the SendBeam Free plan.

## Install

**From a release:** download `sendbeam.zip` from the [releases](../../releases), then Plugins → Add New →
Upload Plugin.

**From source:**

```bash
git clone https://github.com/sendbeam-io/sendbeam-wordpress
cd sendbeam-wordpress && bin/build-zip.sh     # → build/sendbeam.zip
```

Then Settings → SendBeam, paste your form IDs (Forms → your form → Embed in SendBeam) and add the block.

## Shortcodes

```
[sendbeam_form]                              default form from settings
[sendbeam_form id="8f3c…" height="640" title="Join the list"]
[sendbeam_contact height="700"]
[sendbeam_popup_button label="Subscribe" class="wp-element-button"]
```

## Pop-up settings

| Setting | Values |
| --- | --- |
| Show on | Nowhere · Every page · Single posts · Pages · Home page |
| Open | After a delay · From a floating button |
| Delay | 0–120 seconds |
| After it is closed | Hidden for a day · a week · never again · show every visit |

The pop-up remembers a close (or a submission) in the visitor's browser, per form. `[sendbeam_popup_button]`
on a page where the pop-up is off still loads it, in a mode where only the button opens it.

## Self-hosted SendBeam

```php
add_filter( 'sendbeam_app_url', fn() => 'https://mail.example.com' );
```

## Development

There is no build step: the block uses the `wp.*` globals and ships `index.asset.php` by hand.

```bash
php -l sendbeam.php                           # syntax
php tests/smoke.php                           # output checks against WordPress stubs (no WP install needed)
```

CI runs those on PHP 7.4 and 8.3 plus [WordPress Plugin Check](https://github.com/WordPress/plugin-check-action),
the same checks the wordpress.org review uses.

## Publishing to wordpress.org

One-time, then per release. Everything below is done by a person with the `sendbeam` wordpress.org account.

1. **Submit** — <https://wordpress.org/plugins/developers/add/> with `build/sendbeam.zip`. The reviewer checks
   the readme, the licence, escaping, and the external-service disclosure (already in `readme.txt`). Expect a
   reply within a couple of weeks; fix anything they ask and re-upload.
2. **First SVN commit** once approved (the slug is `sendbeam`):
   ```bash
   svn co https://plugins.svn.wordpress.org/sendbeam sendbeam-svn
   bin/build-zip.sh && rsync -a --delete build/sendbeam/ sendbeam-svn/trunk/
   # screenshots-1.png … into sendbeam-svn/assets/ (plus icon-256x256.png, banner-1544x500.png)
   cd sendbeam-svn && svn add --force trunk assets && svn ci -m "1.0.0"
   svn cp trunk tags/1.0.0 && svn ci -m "Tag 1.0.0"
   ```
3. **Each release** — bump `Version:` in `sendbeam.php`, `Stable tag:` and the changelog in `readme.txt`,
   `version` in `blocks/form/block.json` and `index.asset.php`, `SENDBEAM_VERSION`; tag on GitHub; repeat the
   rsync + `svn ci` + `svn cp trunk tags/x.y.z`.

Directory-listing users update from the wordpress.org tag; GitHub releases carry the same zip for people who
install by upload.

## Licence

GPL-2.0-or-later, as wordpress.org requires. See [LICENSE](LICENSE).
