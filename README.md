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
| The site's own email (orders, password resets, notifications) from your verified domain | Settings → SendBeam → Site email — one switch and an API key, no SMTP |

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

## Site email

With **Send this site's email through SendBeam** on, every `wp_mail()` call — WooCommerce orders, password
resets, Contact Form 7 notifications, plugin alerts — becomes one HTTPS request to `POST /api/v1/transactional`
and goes out from the workspace's verified domain. The key needs only the **Send site email** permission
(`transactional:send`); paste it in the settings or define `SENDBEAM_API_KEY` in `wp-config.php`.

- HTML and plain-text messages, `Cc`/`Bcc`, `Reply-To` and `X-*` headers are carried. A `From:` header or the
  `wp_mail_from` filters are honoured when the address is on a verified domain; WordPress's invented
  `wordpress@…` address is never sent (the workspace sender is used instead).
- Messages with attachments are left to the server's own mailer.
- **Fall back to the server's own mailer** (on by default): a refused or failed message goes out the old way and
  the result table on the settings page says why. Off: it fails and `wp_mail_failed` fires with the reason.
- Recipients who unsubscribed from your newsletter still get their receipts; addresses that bounced or reported
  spam before are refused. Every message counts against the workspace's monthly quota.

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

## Licence

GPL-2.0-or-later, as wordpress.org requires. See [LICENSE](LICENSE).
