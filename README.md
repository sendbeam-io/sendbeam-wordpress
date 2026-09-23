# SendBeam for WordPress

Your [SendBeam](https://sendbeam.io) forms on a WordPress site, plus pop-ups, opt-ins at registration and
checkout, and the site's own email from your verified domain. A block, four shortcodes and one settings page.

Full documentation: **[sendbeam.io/docs/wordpress](https://sendbeam.io/docs/wordpress)**

| To do this | Use |
| --- | --- |
| A signup form in a post, page or template | `SendBeam Form` block, or `[sendbeam_form id="…"]` |
| A contact form | `[sendbeam_contact]` |
| A pop-up | Settings → SendBeam → Pop-ups |
| Your own button that opens a pop-up | `[sendbeam_popup_button label="Subscribe"]`, or any element with `data-sendbeam-open="<form id>"` |
| Ask people to subscribe while they register, comment or check out | Settings → SendBeam → Audience |
| Send WordPress email from your verified domain | Settings → SendBeam → Site email |

Forms render from their hosted page, so a change in SendBeam (fields, double opt-in, thank-you text, the list
people join) shows on the site at once and the theme's CSS never fights the form's — while still taking the
site's own colours. Requires WordPress 6.1+ and PHP 7.4+. Works on the SendBeam Free plan.

## Install

**From a release:** download `sendbeam.zip` from the [releases](../../releases), then Plugins → Add New →
Upload Plugin.

**From source:**

```bash
git clone https://github.com/sendbeam-io/sendbeam-wordpress
cd sendbeam-wordpress && bin/build-zip.sh     # → build/sendbeam.zip
```

Then Settings → SendBeam and press **Connect SendBeam**. The button opens a window on sendbeam.io where you
create your account or sign in — the site's name and your administrator email address are already filled in —
and tick what this site may do: show your forms, add people who opt in, send the site's own email, send
WooCommerce events, set up this site's sending domain. Approve, and this site is handed an API key with
exactly those permissions. The key never passes through the browser: the window comes back with a single-use
grant bound to this site's address and to the `state` this site minted, and your server swaps it for the key
in one call. Nothing is sent to SendBeam until you press the button. If you already have a key, "I already
have an API key" on the same screen still takes one. Forms are then picked from a dropdown of the connected
workspace's own forms — no IDs to copy.

Leaving the sending-domain box ticked does the next piece of setting up for you. SendBeam adds this site's
domain to the workspace, and the Overview's second step shows its DNS records with a Copy button on every
value, a **Check now** button that re-runs the check without leaving wp-admin, and a **Set up DNS
automatically** button where your registrar supports one-click set-up. Site email fills in its sender from the
workspace but only switches on once that domain is verified — a site should never have its password resets
quietly routed through a domain nothing has set up yet. **Disconnect**, on the connected card, revokes the key
in SendBeam first and then forgets it here.

## API key permissions

Only what you use. Placing a form or a pop-up needs no key at all.

| Permission | Needed for |
| --- | --- |
| `forms:read` | Listing your forms in the settings page and the block |
| `lists:read` | The Audience tab and its subscriber counts |
| `contacts:read`, `contacts:write`, `lists:write` | The opt-in box at registration, comments or checkout |
| `transactional:send` | Site email |
| `domains:read` | The sending-domain step: its DNS records, and **Check now** |

Paste the key in the settings, or define `SENDBEAM_API_KEY` in `wp-config.php` to keep it out of the database.

## Shortcodes

```
[sendbeam_form]                              default form from settings
[sendbeam_form id="8f3c…" height="640" title="Join the list"]
[sendbeam_contact height="700"]
[sendbeam_popup_button label="Subscribe" class="wp-element-button"]
```

`height` is rarely needed: an embedded form measures itself and the plugin sizes the frame to match.

## Appearance

Settings → SendBeam → Forms → Appearance hands the hosted form your own button colour, text colour, field
background, field border, corner radius, text size and typeface (including *match my theme*). Values are
validated before use — a colour must be hex, sizes are clamped, and the typeface is named from a list rather
than supplied as a font stack. The same appearance is carried into pop-ups.

## Pop-ups

Add as many as you like. Rules are ordered and **the first that matches a page wins**, so two pop-ups can
never argue over one visitor. Each one picks its own look — split with an image, editorial, bold colour or a
slide-in — along with an eyebrow, a button label and an optional subscriber count.

| Setting | Values |
| --- | --- |
| Show on | Every page · Home page · Single posts · Pages · Address contains… |
| Open | After a delay · Scroll depth · Exit intent · Floating button · Only from a button I place |
| Style | Split with image · Editorial · Bold colour · Slide-in — each with its own image, eyebrow, button label and optional subscriber count, set per pop-up |
| Headline and copy | Per pop-up — it has no page around it to explain itself |
| After it is closed | Hidden for a day · a week · never again · show every visit |

A close or a submission is remembered in the visitor's browser, per form, and applies to every automatic
trigger. A button someone deliberately presses always opens the form.

## Audience

An opt-in tick box on account registration, comment forms and WooCommerce checkout, adding people to any
number of your lists. It will not subscribe anyone who did not tick it, the box is never pre-ticked, and there
is no bulk import of existing users. Each contact records where it came from in its `source` field
(`wordpress-registration`, `wordpress-comment`, `woocommerce-checkout`), so segments can filter on it.

## Site email

With **Send this site's email through SendBeam** on, every `wp_mail()` call — WooCommerce orders, password
resets, Contact Form 7 notifications, plugin alerts — becomes one HTTPS request to `POST /api/v1/transactional`
and goes out from the workspace's verified domain.

- HTML and plain-text messages, `Cc`/`Bcc`, `Reply-To` and `X-*` headers are carried. A `From:` header or the
  `wp_mail_from` filters are honoured when the address is on a verified domain; WordPress's invented
  `wordpress@…` address is never sent (the workspace sender is used instead).
- Messages with attachments are left to the server's own mailer.
- **Fall back to the server's own mailer** (on by default): a refused or failed message goes out the old way and
  the result table on the settings page says why. Off: it fails and `wp_mail_failed` fires with the reason.
- Recipients who unsubscribed from your newsletter still get their receipts; addresses that bounced or reported
  spam before are refused. Every message counts against the workspace's monthly quota.

## Hooks

```php
// Point at a self-hosted SendBeam.
add_filter( 'sendbeam_app_url', fn() => 'https://mail.example.com' );
```

```js
// A form in the page was submitted.
document.addEventListener( 'sendbeam:submitted', e => console.log( e.detail.formId ) );
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
