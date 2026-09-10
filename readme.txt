=== SendBeam ===
Contributors: sendbeam
Tags: newsletter, email marketing, signup form, popup, contact form
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Newsletter signup forms, pop-ups, opt-ins at registration and checkout, and your site's own email, from your SendBeam account.

== Description ==

[SendBeam](https://sendbeam.io) is email marketing for people who run more than one website: one account, a workspace per site, and a plan that counts subscribers across all of them.

This plugin puts your SendBeam forms on a WordPress site without copying embed code around:

* **SendBeam Form block** — pick a form, set a height, done. Works in posts, pages, templates and the site editor.
* **Shortcodes** — `[sendbeam_form id="…"]`, `[sendbeam_contact]` and `[sendbeam_popup_button]` for widgets, page builders and the classic editor.
* **Pop-up** — show a signup form in a modal after a delay or from a floating button, on every page, posts only, pages only or the home page; hidden for a day, a week or for good once a visitor closes it.
* **A default form** — set it once under Settings → SendBeam and every block and shortcode without an ID uses it.
* **Site email** — send everything WordPress sends with `wp_mail()` (WooCommerce order confirmations, password resets, form and comment notifications, plugin alerts) through your verified SendBeam domain. No SMTP host, port or password: one API key, one switch, a test button and a log of recent results.

Forms are shown exactly as configured in SendBeam (fields, double opt-in, the thank-you message, the list they join), so changing a form there changes it on your site straight away. Displaying a form makes no request from your server — the visitor's browser fetches it. Site email, when you switch it on, is one HTTPS call per message to SendBeam's API.

A site can listen for `sendbeam:submitted` on `document` to track a signup as an analytics goal or redirect to a thank-you page.

= External service =

This plugin talks to SendBeam (sendbeam.io) in four distinct ways. Nothing is sent anywhere else.

**1. Showing a form or pop-up.** The visitor's browser loads the form from `https://sendbeam.io/f/<form id>` and, for the pop-up, the script `https://sendbeam.io/f/<form id>/popup.js`. The URL carries the appearance you chose (colours, corner radius, text size, typeface) so the form matches your theme; these are your settings, not anything about the visitor. Your server makes no request to show a form.

**2. A visitor submitting a form.** What they typed — their email address and any other fields on that form — is sent to SendBeam to create the subscriber or deliver the message.

**3. The plugin's settings screens.** While a site administrator has the SendBeam settings page open, your server calls SendBeam with your API key to list your forms (`/api/v1/forms`), your lists (`/api/v1/lists`), the number of people on each list (`/api/v1/lists/<id>/contacts`) and your subscriber total (`/api/v1/contacts`). This is what lets the plugin offer your forms in a dropdown instead of asking you to paste an ID. The results are cached for five minutes. No visitor data is involved and nothing is sent on front-end page loads.

**4. Subscribing someone who ticked the opt-in box** (off by default). When a visitor registers an account, leaves a comment or checks out in WooCommerce *and* ticks the subscribe box, their email address, first and last name and which of those three things they were doing are sent to `/api/v1/contacts` and added to the lists you chose. Nobody who has not ticked the box is ever sent, and the box is never pre-ticked.

**Site email** (off by default) posts each email your site sends from your server to `https://sendbeam.io/api/v1/transactional` with your API key: the recipient addresses, subject, body and reply-to, so SendBeam can deliver it from your verified domain. Messages with attachments are left to the server's own mailer.

Full setup documentation: [sendbeam.io/docs/wordpress](https://sendbeam.io/docs/wordpress). See also the [SendBeam privacy policy](https://sendbeam.io/privacy) and [terms](https://sendbeam.io/terms).

= Requirements =

A SendBeam account (the Free plan is enough) with at least one form. Form IDs are shown under Forms → your form → Embed.

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings → SendBeam** and paste the ID of your signup form (and, if you like, a contact form and a pop-up form).
3. Add the **SendBeam Form** block to a post or page, or use `[sendbeam_form]` anywhere shortcodes work.

== Frequently Asked Questions ==

= Where do I find a form ID? =

In SendBeam, open **Forms**, click the form, and copy the ID from the Embed panel. It looks like `8f3c1a2e-3b1d-4c55-9a0e-1f2d3c4b5a69`.

= The form is cut off or has a scrollbar =

Raise the height: in the block's settings sidebar, or with `[sendbeam_form id="…" height="640"]`.

= Can I open the pop-up from my own link or button? =

Yes. Give any element `data-sendbeam-open="<the pop-up form's ID>"`, or use `[sendbeam_popup_button label="Subscribe"]`. Pages where the pop-up is not shown automatically still get the loader when the button is on them.

= Does this replace my site's own email sending? =

No. WordPress keeps sending its own mail. SendBeam only receives what visitors submit through its forms, and sends the newsletters and campaigns you write in SendBeam.

= What does Site email change? =

With it on, every email WordPress sends goes through SendBeam's API instead of the server's `mail()` function, from your verified domain, so it stops landing in spam. WooCommerce, membership, booking and form plugins all use `wp_mail()`, so they are covered without any setting of their own. Turn it off and everything goes back to how it was.

= Which API key permissions does the plugin need? =

Only what you actually use, and the plugin works with less:

* **Forms (read)** — so the settings page and the block can list your forms instead of asking for an ID. Without it you can still type IDs by hand.
* **Lists (read)** — for the Audience tab and its subscriber counts.
* **Contacts (read and write) and Lists (write)** — only if you switch on the opt-in box for registrations, comments or WooCommerce checkout.
* **Send site email** (`transactional:send`) — only if you switch on Site email.

Placing a form or a pop-up needs no key at all. Make a separate key for each site under Settings → API keys in SendBeam, and you can define `SENDBEAM_API_KEY` in `wp-config.php` instead of saving it in the database.

= What about emails with attachments? =

They are left to the server's own mailer for now, and the recent-email table on the settings page says so.

= Does it work with caching plugins? =

Yes. The form is an iframe and the pop-up is a script tag, both cache-safe.

== Screenshots ==

1. The SendBeam Form block in the editor.
2. Settings → SendBeam.
3. The pop-up on a post.

== Changelog ==

= 1.6.1 =
* Hardening: messages from an embedded form are only accepted from the origin serving it, so another frame on the page cannot resize an embed or fire a false signup event.

= 1.6.0 =
* A Docs tab in the settings screen: shortcodes to copy, what each API key permission is for, and links to the full documentation.

= 1.5.2 =
* Uninstalling now removes every option, transient and setting the plugin stored, on multisite too.
* The comment opt-in box sits directly above the Post Comment button on any theme.
* Documentation: a fuller description of what the plugin sends to SendBeam and which API key permissions each feature needs.

= 1.5.1 =
* Fixed: a pop-up set to stay hidden for a day or a week reappeared on every visit when it used the scroll or exit-intent trigger.
* Fixed: the Overview and Audience tabs reported the wrong subscriber and per-list numbers.

= 1.5.0 =
* Give each pop-up its own headline and supporting line, so it says what it is for.
* Pop-ups size themselves to their form; the close button no longer overlaps the first field.
* Appearance changes reach returning visitors straight away instead of waiting out the loader's browser cache.

= 1.4.0 =
* Appearance settings: give embedded forms and pop-ups your own button, text, field and border colours, corner radius, text size and typeface.
* Embedded forms size themselves to their content — no more scrollbar or empty space under the button.

= 1.3.0 =
* Multiple pop-ups, each with its own targeting and trigger. Show on every page, the home page, single posts, pages, or any address containing your text. First matching rule wins, so two pop-ups can never fight over one visitor.
* New triggers: scroll depth and exit intent, alongside the delay and floating button.
* Collect subscribers from account registration, comments and WooCommerce checkout with an opt-in tick box, adding them to any number of your lists. Never pre-ticked, and existing users are never bulk-imported.
* A log of recent subscriptions so a failure is visible rather than silent.

= 1.2.0 =
* Redesigned admin in SendBeam's own look, split into Overview, Forms, Audience, Pop-ups and Site email tabs.
* Forms library: every form in your workspace with a copy-ready shortcode and preview link. Place any form as often as you like.
* Audience tab: your lists with subscriber counts and opt-in mode.
* The SendBeam Form block now offers a dropdown of your forms instead of asking for an ID.
* Guided setup: a three-step checklist, connect-first layout, and a live connection status that tells apart a rejected key, a key missing the Forms (read) permission, and an unreachable API.
* Signup, contact and pop-up forms are now chosen from a dropdown of your own forms rather than by pasting an ID, with a fallback to the ID field when the key cannot list them.
* Pop-up and site-email fields stay hidden until those features are switched on.

= 1.1.0 =
* Site email: send everything WordPress sends with wp_mail() through your verified SendBeam domain (switch, API key or SENDBEAM_API_KEY constant, From overrides, fallback to the server mailer, test button, recent-email log).

= 1.0.0 =
* First release: form block, `[sendbeam_form]`, `[sendbeam_contact]`, `[sendbeam_popup_button]`, pop-up settings.
