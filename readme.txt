=== SendBeam ===
Contributors: sendbeam
Tags: newsletter, email marketing, signup form, popup, contact form
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Newsletter signup forms, pop-ups, opt-ins at registration and checkout, and your site's own email, from your SendBeam account.

== Description ==

[SendBeam](https://sendbeam.io) is email marketing for people who run more than one website: one account, a workspace per site, and a plan that counts subscribers across all of them. Every signup this plugin collects joins a list in SendBeam, where campaigns and automations pick it up — the forms and pop-ups are the front door, not the whole house.

This plugin puts your SendBeam forms on a WordPress site without copying embed code around:

* **SendBeam Form block** — pick a form, set a height, done. Works in posts, pages, templates and the site editor.
* **Shortcodes** — `[sendbeam_form id="…"]`, `[sendbeam_contact]` and `[sendbeam_popup_button]` for widgets, page builders and the classic editor.
* **Pop-up** — show a signup form in a modal after a delay or from a floating button, on every page, posts only, pages only or the home page; hidden for a day, a week or for good once a visitor closes it.
* **Pop-up styles** — four looks for the modal: split with image, editorial, bold colour and slide-in, each with its own image, eyebrow, button label and optional subscriber count, chosen per pop-up.
* **A default form** — set it once under Settings → SendBeam and every block and shortcode without an ID uses it.
* **Form plugins** — send the people who fill in forms built with Contact Form 7, Elementor Pro, WPForms, Gravity Forms or Fluent Forms to SendBeam, onto the list and with the tag you choose. Switched on form by form, and only for people who ticked your consent field or filled in a form you marked as a signup form.
* **Site email** — send everything WordPress sends with `wp_mail()` (WooCommerce order confirmations, password resets, form and comment notifications, plugin alerts) through your verified SendBeam domain. No SMTP host, port or password: one API key, one switch, a test button and a log of recent results.
* **E-commerce events** (WooCommerce) — feed SendBeam's native Cart Abandoned, Product Viewed and Order Placed automation triggers. Order placed also keeps a running lifetime-value total on the contact. Each event has its own switch, off by default.

Forms are shown exactly as configured in SendBeam (fields, double opt-in, the thank-you message, the list they join), so changing a form there changes it on your site straight away. Displaying a form makes no request from your server — the visitor's browser fetches it. Site email, when you switch it on, is one HTTPS call per message to SendBeam's API.

A site can listen for `sendbeam:submitted` on `document` to track a signup as an analytics goal or redirect to a thank-you page.

= External service =

This plugin talks to SendBeam (sendbeam.io) in five distinct ways. Nothing is sent anywhere else.

**1. Showing a form or pop-up.** The visitor's browser loads the form from `https://sendbeam.io/f/<form id>` and, for the pop-up, the script `https://sendbeam.io/f/<form id>/popup.js`. The URL carries the appearance you chose (colours, corner radius, text size, typeface) so the form matches your theme, and for a pop-up its headline, line underneath, style, image address, eyebrow, button label and whether to show the subscriber count; these are your settings, not anything about the visitor. Your server makes no request to show a form.

**2. A visitor submitting a form.** What they typed — their email address and any other fields on that form — is sent to SendBeam to create the subscriber or deliver the message.

**3. The plugin's settings screens.** While a site administrator has the SendBeam settings page open, your server calls SendBeam with your API key to list your forms (`/api/v1/forms`), your lists (`/api/v1/lists`), the number of people on each list (`/api/v1/lists/<id>/contacts`) and your subscriber total (`/api/v1/contacts`). This is what lets the plugin offer your forms in a dropdown instead of asking you to paste an ID. The results are cached for five minutes. No visitor data is involved and nothing is sent on front-end page loads.

**4. Subscribing someone who ticked the opt-in box** (off by default). When a visitor registers an account, leaves a comment or checks out in WooCommerce *and* ticks the subscribe box, their email address, first and last name and which of those three things they were doing are sent to `/api/v1/contacts` and added to the lists you chose. Nobody who has not ticked the box is ever sent, and the box is never pre-ticked.

**5. Forms built with another form plugin** (off by default, and off for every form until you switch it on for that form). When someone submits a Contact Form 7, Elementor Pro, WPForms, Gravity Forms or Fluent Forms form you have connected, and either ticked the consent field you named or filled in a form you marked as a signup form, their email address, first and last name and the name of the form plugin are sent from your server to `/api/v1/contacts`, added to the list you chose (`/api/v1/lists/<id>/contacts`) and, if you set one, given a tag (`/api/v1/tags` and `/api/v1/contacts/<id>/tags`). Nothing else from the form is sent.

**Site email** (off by default) posts each email your site sends from your server to `https://sendbeam.io/api/v1/transactional` with your API key: the recipient addresses, subject, body and reply-to, so SendBeam can deliver it from your verified domain. Messages with attachments are left to the server's own mailer.

**E-commerce events** (off by default, each of the three switched on separately, WooCommerce only) posts to `https://sendbeam.io/api/v1/ecommerce/events` with your API key: an email address, and depending on the event a name, an order total and its currency. Order placed sends when an order is completed. Product viewed sends only when the visitor is a known contact (logged in, or an email already given this visit) — never for an anonymous visitor. Cart abandoned is a best-effort heuristic: adding an item to the cart is timestamped locally on your server, and if no order has followed within a window you set (60 minutes by default) and an email became known at some point, one event is sent; nothing is sent for a cart nobody's email was ever known for.

Full setup documentation: [sendbeam.io/docs/wordpress](https://sendbeam.io/docs/wordpress). See also the [SendBeam privacy policy](https://sendbeam.io/legal/privacy) and [terms](https://sendbeam.io/legal/terms).

= Requirements =

A SendBeam account (the Free plan is enough) with at least one form. Form IDs are shown under Forms → your form → Embed.

== Installation ==

Five minutes, no code. You need a SendBeam account first: it is free to start at [sendbeam.io/signup](https://sendbeam.io/signup), and the workspace it creates is where your forms, lists, campaigns and automations live.

1. Install and activate the plugin, then open **Settings → SendBeam**. The Overview tab shows a three-step checklist and ticks each step off as you go.
2. **Verify your sending domain** in SendBeam under Settings → Sending: add the DNS records it shows and press verify. Forms work without this, but nothing is sent from your own address, whether a campaign, an automation or your site's email, until the domain is verified.
3. **Connect your account.** In SendBeam, go to Settings → API keys and create a key with the **Forms (read)** permission (add **Send site email** if you want WordPress email to go through SendBeam). Paste it on the Overview tab and save. The page confirms the connection and lists your workspace's forms.
4. **Choose a default form** on the Forms tab. Every SendBeam Form block and `[sendbeam_form]` shortcode without an ID uses it.
5. **Put it on the site.** Add the **SendBeam Form** block to a post, page or template, or drop `[sendbeam_form]` anywhere shortcodes work. The form appears exactly as it is set up in SendBeam.
6. **Optional, when you want them:** a pop-up on the Pop-ups tab (pick a form, where it shows, when it opens, and one of four styles), opt-in boxes at registration, comments and checkout on the Audience tab, and your site's own email on the Site email tab, each with a test button.

Stuck? The Docs tab inside the plugin opens the guide for whichever tab you are on.

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
* **Contacts (read and write) and Lists (write)** — only if you switch on the opt-in box for registrations, comments or WooCommerce checkout, or connect a form plugin.
* **Tags (read and write)** — only if a connected form adds a tag.
* **Send site email** (`transactional:send`) — only if you switch on Site email.

Placing a form or a pop-up needs no key at all. Make a separate key for each site under Settings → API keys in SendBeam, and you can define `SENDBEAM_API_KEY` in `wp-config.php` instead of saving it in the database.

= Does it work with Contact Form 7, Elementor Pro, WPForms, Gravity Forms or Fluent Forms? =

Yes. Each is set up where that plugin keeps its own settings, and the Audience tab lists which are active:

* **Contact Form 7** — open a form and use its **SendBeam** tab. Name the email, name and consent fields as they appear in the form, without the square brackets.
* **Elementor Pro** — in the Form widget, add **SendBeam** under Actions After Submit, then fill in the SendBeam section with the field IDs from each field's Advanced tab.
* **WPForms** — in the form builder, open **Settings → SendBeam** and pick the fields from the dropdowns.
* **Gravity Forms** — open **Settings → SendBeam** on a form and add a feed. Map the fields, and use conditional logic if you like.
* **Fluent Forms** — choose the forms on the **Audience** tab of Settings → SendBeam.

A form sends someone only when they ticked the consent field you named, or when you have marked it as a signup form that people fill in to subscribe. The subscription happens straight after the form is submitted, so the visitor's form is never slowed down, and each attempt appears under Recent subscriptions.

= What about emails with attachments? =

They are left to the server's own mailer for now, and the recent-email table on the settings page says so.

= Does it work with caching plugins? =

Yes. The form is an iframe and the pop-up is a script tag, both cache-safe.

== Screenshots ==

1. The SendBeam Form block, showing the real form as it will appear on the page.
2. Settings → SendBeam: what is set up, and what the workspace holds.
3. The Forms tab, where the default forms and their colours are set.
4. The Audience tab: the workspace's lists, and where to ask people to subscribe.
5. The Pop-ups tab. Rules are matched top to bottom and the first one wins.
6. A pop-up on the site, using the site's own colours.
7. The Site email tab, which routes wp_mail() through SendBeam.

== Changelog ==

= 1.8.2 =
* Pop-ups come in four styles — split with image, editorial, bold colour and slide-in — with an image, an eyebrow, a button label and an optional subscriber count, set per pop-up.

= 1.8.1 =
* Corrected the privacy policy and terms links in this readme, which pointed at pages that no longer exist.

= 1.8.0 =
* E-commerce events tab (WooCommerce): Order placed, Product viewed and Cart abandoned, each its own switch, feeding SendBeam's native automation triggers of the same names.
* Order placed also keeps a running lifetime-value total on the SendBeam contact.
* Cart abandoned is a documented best-effort heuristic built on wp-cron: WooCommerce has no native "abandoned cart" event, so this times out a window (60 minutes by default, configurable) after an item is added and checks whether an order followed, and only ever fires for a cart an email became known for.

= 1.7.0 =
* Form plugins: send the people who fill in Contact Form 7, Elementor Pro, WPForms, Gravity Forms and Fluent Forms forms to SendBeam, with the list and tag you choose. Set up form by form, where each plugin keeps its settings, and only with the person's consent.
* The Audience tab lists which form plugins are active and where each is set up.

= 1.6.3 =
* "Visit plugin site" on the Plugins screen now opens the WordPress documentation rather than the marketing page.
* The block's asset version had been left behind at 1.6.1, so the editor could serve a stale copy of the block script after an update.

= 1.6.2 =
* Housekeeping before submission to the WordPress Plugin Directory: the whole plugin now passes the WordPress Coding Standards and Plugin Check with nothing reported, and development files are kept out of the distributed zip.

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
