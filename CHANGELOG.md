# Changelog

## 1.3.0

- **Pop-ups became a list, not a setting.** Add as many as you like; each has its own form, targeting (every page / home / single posts / pages / address contains…) and frequency. The first enabled rule that matches a page wins and nothing else is printed, so two modals can never fight over the same visitor.
- **Two new triggers: scroll depth and exit intent.** Built on the hosted loader's manual mode — a hidden opener plus a few lines of script — rather than by inventing attributes it would ignore. Exit intent binds only where a real pointer exists, so it does not misfire on phones.
- **Audience sync.** An opt-in tick box on account registration, comment forms and WooCommerce checkout, adding people to any number of lists. Three things it will not do: subscribe anyone who has not ticked the box, pre-tick the box, or bulk-import existing users who never agreed to anything.
- A returning customer who ticks the box still lands on the list: the API answers 409 with no ID for an address already on file, so the contact is looked up by exact address instead of failing.
- Recent subscriptions are logged, so a failure is visible rather than silent.
- Upgrades fold the old single pop-up into rule one, so nothing stops working on update.

## 1.2.0

- **The admin is SendBeam's own design now** — warm paper, ink rules, mono labels and the vermilion/cobalt/moss accents from the product, instead of a default WordPress settings page. Fonts are the system stack, not a remote webfont, so nothing is fetched from a third party inside wp-admin.
- **Five tabs** — Overview, Forms, Audience, Pop-ups, Site email — replacing one long scroll. Each tab saves only its own fields.
- **Forms library.** Every form in the workspace, with its kind, a ready-made shortcode and a one-click copy, plus a preview link. Any form can be placed as many times as you like, on as many pages as you like.
- **Audience tab.** Your lists with live subscriber counts and whether each is double opt-in.
- **The block editor picks forms by name too**, through a new read-only `sendbeam/v1/forms` route available to anyone who can edit posts — the API key never leaves the server.
- Overview shows forms, lists and total subscribers at a glance.
- The settings page now walks you through setup instead of presenting every field at once. A three-step checklist at the top shows what is done and what is next, and links to the section that does it.
- **Form pickers instead of pasted IDs.** When the saved key can read forms, the signup, contact and pop-up fields become dropdowns of your own forms, filtered by kind. A workspace with exactly one matching form has it preselected. Keys that cannot read forms, or an unreachable API, fall back to the original ID field, so nothing that worked before stops working.
- **Connect is its own first section**, ahead of Forms; the API key used to sit inside Site email, which implied it was only for email.
- The connection state is shown and distinguishes the three cases that need different fixes: key rejected, key valid but missing the Forms (read) permission, and API unreachable. "Check again" re-tests without saving.
- Pop-up and site-email detail fields are hidden while those features are off.
- One dismissible notice after activation, which removes itself once connected. No redirect on activation.

## 1.1.0

- Site email: `pre_wp_mail` relays every `wp_mail()` message to `POST /api/v1/transactional` (HTML or plain text, cc/bcc, reply-to, X-* headers, From overrides). Settings → SendBeam → Site email: switch, API key (or `SENDBEAM_API_KEY` in wp-config.php), From name/address, fallback to the server mailer, "Send a test email", last 20 results. Attachments stay with the server mailer.

## 1.0.0

First release.

- `SendBeam Form` block (dynamic, previews in the editor, no build step).
- `[sendbeam_form id height title]`, `[sendbeam_contact height]`, `[sendbeam_popup_button label class]`.
- Pop-up: form, where (off / everywhere / posts / pages / home), trigger (delay or floating button), delay, hide-after-close, button label.
- `sendbeam:submitted` DOM event relayed from the hosted form.
- `sendbeam_app_url` filter for self-hosted installs.
