# SendBeam for WordPress

Your [SendBeam](https://sendbeam.io) forms on a WordPress site, plus pop-ups, opt-ins at registration and
checkout, and the site's own email from your verified domain. A block, four shortcodes and its own admin menu.

Full documentation: **[sendbeam.io/docs/wordpress](https://sendbeam.io/docs/wordpress)**

| To do this | Use |
| --- | --- |
| A signup form in a post, page or template | `SendBeam Form` block, or `[sendbeam_form id="…"]` |
| A contact form | `[sendbeam_contact]` |
| A pop-up | SendBeam → Pop-ups |
| Your own button that opens a pop-up | `[sendbeam_popup_button label="Subscribe"]`, or any element with `data-sendbeam-open="<form id>"` |
| Ask people to subscribe while they register, comment or check out | SendBeam → Audience |
| Send WordPress email from your verified domain | SendBeam → Site email |
| Find out why something is not working | SendBeam → Help, or Tools → Site Health |

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

Activating it takes you once to **SendBeam → Set up**: connect, verify your sending domain, switch on site
email, place a form. Every step can be skipped, the whole thing can be left from any step, and where you got
to is remembered. It never appears when several plugins are activated at once, never on a site that already
has a key, and `add_filter( 'sendbeam_setup_wizard', '__return_false' )` switches it off for good.

The first step is **Connect SendBeam**. The button opens a window on sendbeam.io where you
create your account or sign in — the site's name and your administrator email address are already filled in —
and tick what this site may do: show your forms, add people who opt in, send the site's own email, send
WooCommerce events, set up this site's sending domain. Approve, and this site is handed an API key with
exactly those permissions. The key never passes through the browser: the window comes back with a single-use
grant bound to this site's address and to the `state` this site minted, and your server swaps it for the key
in one call. Nothing is sent to SendBeam until you press the button. If you already have a key, "I already
have an API key" on the same screen still takes one. Forms are then picked from a dropdown of the connected
workspace's own forms — no IDs to copy.

### Where things are

SendBeam has a top-level menu with one page per section rather than tabs nothing in the menu mentions:
**Overview** (what this site is connected to, how far through set-up it is, what the workspace holds),
**Forms**, **Pop-ups**, **Audience**, **Site email**, **E-commerce**, **Settings** (Connection · Sending
domain · Advanced) and **Help**. The old `options-general.php?page=sendbeam` addresses and every one of their
tabs 301 to whatever replaced them.

Forms, lists and the email log are `WP_List_Table` subclasses, so they search, offer a rows-per-screen setting
under Screen Options, collapse the way core's screens do on a phone, and — for the log — delete in bulk.

Leaving the sending-domain box ticked does the next piece of setting up for you. The consent page suggests
this site's own domain and lets you type a different one — a brand domain, or `mail.` something — and whatever
you enter is what SendBeam sets up and what the plugin shows. The Overview's second step lists its DNS records
with a Copy button on every value and a column saying which of them are live yet, a **Check now** button that
re-runs the check without leaving wp-admin, and a **Set up DNS automatically** button where your registrar
supports one-click set-up — that one brings you back here when it is done. Records rarely spread while you are
still looking at wp-admin, so the site quietly checks again every hour for a day and finishes the job when
they appear.

Site email fills in its sender from the workspace but only switches on once that domain is verified — a site
should never have its password resets quietly routed through a domain nothing has set up yet. If a permission
turns out to be missing, the step that needs it offers **Reconnect with more permissions** rather than making
you disconnect first. **Disconnect**, on the connected card, revokes the key in SendBeam, forgets it here, and
puts back the settings Connect filled in — your list, form, sending domain and sender stay in the workspace,
where other sites may still be using them.

## API key permissions

Only what you use. Placing a form or a pop-up needs no key at all.

| Permission | Needed for |
| --- | --- |
| `forms:read` | Listing your forms in the settings page and the block |
| `lists:read` | The Audience screen and its subscriber counts |
| `contacts:read`, `contacts:write`, `lists:write` | The opt-in box at registration, comments or checkout |
| `transactional:send` | Site email |
| `domains:read` | The sending-domain step: its DNS records, and **Check now** |

Paste the key under **SendBeam → Settings → Advanced**, or define `SENDBEAM_API_KEY` in `wp-config.php` to
keep it out of the database. With the constant set the plugin offers neither the button nor the paste field,
because either would store a second key nothing would ever use.

### What the site sends as

Two settings, easy to confuse and once easy for the plugin to contradict itself about. **Verifying a domain**
proves the domain is yours. **The From address** decides which address a message actually goes out as, and it
starts as SendBeam's shared `ws-…@post.sendbeam.io`. `sendbeam_effective_sender()` is the one place that works
out the address the next message would use and classifies it — `own_domain`, `shared`, `other` or `none` — and
the Overview's connection line, the site-email step and Site Health all read it. Where the domain is verified
and the address is still the shared one, the step says so and offers one press to move it.

**SendBeam → Help → Run the setup guide again** puts the current user back at step 1 of the wizard without
touching `sendbeam_setup_done`, which is the site's own answer to whether anybody has set it up.

## Diagnosing a site

**SendBeam → Site email → Send a test email** sends a real, designed message built from the same
`sendbeam_effective_sender()` answer the Overview uses: the headline says which address it came from, a
**Signed by** row names the domain that authenticated it, and the sending-domain row distinguishes "verified
and in use" from "verified, but not in use yet". Where something is left to do it carries a **Next step**
block with the button that does it, and the in-page result mirrors that with an amber note.
The result replaces the form in the page: a success card, or a failure card saying what went wrong, what it
means, what to do about it, and a **Details for support** block captured at the moment of failure — versions,
where the key is kept (never the key), the domain state, the HTTP status and the raw error — that copies in
one press.

**Tools → Site Health** carries three checks: `sendbeam-key` (the key works), `sendbeam-domain` (the sending
domain is verified) and `sendbeam-mail` (site email is not switched on behind an unverified domain — critical
when it is). Site Health → Info has a SendBeam section with every state this site holds, and **SendBeam →
Help** shows the same values with a **Copy for support** button.

## Shortcodes

```
[sendbeam_form]                              default form from settings
[sendbeam_form id="8f3c…" height="640" title="Join the list"]
[sendbeam_contact height="700"]
[sendbeam_popup_button label="Subscribe" class="wp-element-button"]
```

`height` is rarely needed: an embedded form measures itself and the plugin sizes the frame to match.

## Appearance

SendBeam → Forms → Appearance hands the hosted form your own button colour, text colour, field
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
