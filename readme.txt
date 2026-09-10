=== SendBeam ===
Contributors: sendbeam
Tags: newsletter, email marketing, signup form, popup, contact form
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Signup forms, a pop-up, a contact form — and your site's own email sent from your verified domain — from one SendBeam settings page.

== Description ==

[SendBeam](https://sendbeam.io) is email marketing for people who run more than one website: one account, a workspace per site, and a plan that counts subscribers across all of them.

This plugin puts your SendBeam forms on a WordPress site without copying embed code around:

* **SendBeam Form block** — pick a form, set a height, done. Works in posts, pages, templates and the site editor.
* **Shortcodes** — `[sendbeam_form id="…"]`, `[sendbeam_contact]` and `[sendbeam_popup_button]` for widgets, page builders and the classic editor.
* **Pop-up** — show a signup form in a modal after a delay or from a floating button, on every page, posts only, pages only or the home page; hidden for a day, a week or for good once a visitor closes it.
* **A default form** — set it once under Settings → SendBeam and every block and shortcode without an ID uses it.
* **Site email** — send everything WordPress sends with `wp_mail()` (WooCommerce order confirmations, password resets, form and comment notifications, plugin alerts) through your verified SendBeam domain. No SMTP host, port or password: one API key, one switch, a test button and a log of recent results.

Forms are shown exactly as configured in SendBeam (fields, double opt-in, the thank-you message, the list they join), so changing a form there changes it on your site straight away. Forms make no requests from your server. Site email, when you switch it on, is one HTTPS call per message to SendBeam's API.

A site can listen for `sendbeam:submitted` on `document` to track a signup as an analytics goal or redirect to a thank-you page.

= External service =

This plugin displays forms served by SendBeam (sendbeam.io). When a page containing a form or the pop-up is viewed, the visitor's browser loads the form from `https://sendbeam.io/f/<form id>` and, for the pop-up, the script `https://sendbeam.io/f/<form id>/popup.js`. Nothing is sent to SendBeam until the visitor submits a form, at which point what they typed (their email address and any other fields on the form) is sent to SendBeam to create the subscriber or deliver the message.

If you turn on **Site email** (off by default), each email your site sends is posted from your server to `https://sendbeam.io/api/v1/transactional` with your API key: the recipient addresses, subject, body and reply-to, so SendBeam can deliver it from your verified domain. Messages with attachments are not sent to SendBeam. See the [SendBeam privacy policy](https://sendbeam.io/privacy) and [terms](https://sendbeam.io/terms).

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

= Which API key permission does Site email need? =

Only **Send site email** (`transactional:send`). Make a separate key for each site under Settings → API keys in SendBeam; nothing else on the key is needed. You can define `SENDBEAM_API_KEY` in `wp-config.php` instead of saving the key in the database.

= What about emails with attachments? =

They are left to the server's own mailer for now, and the recent-email table on the settings page says so.

= Does it work with caching plugins? =

Yes. The form is an iframe and the pop-up is a script tag, both cache-safe.

== Screenshots ==

1. The SendBeam Form block in the editor.
2. Settings → SendBeam.
3. The pop-up on a post.

== Changelog ==

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
