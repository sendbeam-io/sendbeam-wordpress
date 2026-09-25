=== SendBeam ===
Contributors: sendbeam, sendbeamsupport
Tags: newsletter, email marketing, signup form, popup, contact form
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.8.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Newsletter signup forms, pop-ups, opt-ins at registration and checkout, and your site's own email, from your SendBeam account.

== Description ==

[SendBeam](https://sendbeam.io) is email marketing for people who run more than one website: one account, a workspace per site, and a plan that counts subscribers across all of them. Every signup this plugin collects joins a list in SendBeam, where campaigns and automations pick it up — the forms and pop-ups are the front door, not the whole house.

This plugin puts your SendBeam forms on a WordPress site without copying embed code around:

* **SendBeam Form block** — pick a form, set a height, done. Works in posts, pages, templates and the site editor.
* **Shortcodes** — `[sendbeam_form id="…"]`, `[sendbeam_contact id="…"]` and `[sendbeam_popup_button]` for widgets, page builders and the classic editor.
* **Pop-up** — show a signup form in a modal after a delay or from a floating button, on every page, posts only, pages only or the home page; hidden for a day, a week or for good once a visitor closes it.
* **Pop-up styles** — four looks for the modal: split with image, editorial, bold colour and slide-in, each with its own image, eyebrow, button label and optional subscriber count, chosen per pop-up.
* **A default form** — set it once under SendBeam → Forms and every block and shortcode without an ID uses it.
* **Form plugins** — send the people who fill in forms built with Contact Form 7, Elementor Pro, WPForms, Gravity Forms or Fluent Forms to SendBeam, onto the list and with the tag you choose. Switched on form by form, and only for people who ticked your consent field or filled in a form you marked as a signup form.
* **Site email** — send everything WordPress sends with `wp_mail()` (WooCommerce order confirmations, password resets, form and comment notifications, plugin alerts) through your verified SendBeam domain. No SMTP host, port or password: one API key, one switch, a test button, and a searchable log of every message with what became of it and which plugin asked for it.
* **E-commerce events** (WooCommerce) — feed SendBeam's native Cart Abandoned, Product Viewed and Order Placed automation triggers. Order placed fires once, when the order is paid, and keeps a running lifetime-value total on the contact. Each event has its own switch, off by default. Works with both the classic checkout and the block checkout WooCommerce installs.

It has its own menu in wp-admin, with a page per section — Overview, Forms, Pop-ups, Audience, Site email, E-commerce, Settings and Help — and walks a new site through connecting, verifying its sending domain, switching on site email and placing a form the first time it is activated. That guide can be skipped a step at a time or left entirely; every step is also on the Overview.

**Connecting** is one button: tick what this site may do, sign in or create your account in the window that opens, and the key arrives on its own. You choose the domain you send from — this site's, or a brand domain you own — and the plugin shows you its DNS records, checks them again on its own for a day afterwards, and switches your site email on once they are live. If you did not give a permission, the step that needs it offers to reconnect; disconnecting revokes this site's key and leaves everything in your workspace where it is.

Forms are shown exactly as configured in SendBeam (fields, double opt-in, the thank-you message, the list they join), so changing a form there changes it on your site straight away. Displaying a form makes no request from your server — the visitor's browser fetches it. Site email, when you switch it on, is one HTTPS call per message to SendBeam's API.

Your forms, your lists and the site's email log are proper WordPress list tables: search, sort out how many rows you want per screen, and delete log entries in bulk, the same way the Posts screen works. **SendBeam → Help** carries the documentation links, the shortcodes, what each API key permission is for, and a system-status block that copies in one press for a support email; the same information is in Tools → Site Health, along with three checks — your key works, your sending domain is verified, and your site's email is not being sent from a domain nothing has verified.

A site can listen for `sendbeam:submitted` on `document` to track a signup as an analytics goal or redirect to a thank-you page.

= External service =

This plugin talks to SendBeam (sendbeam.io) in six distinct ways. Nothing is sent anywhere else.

**1. Showing a form or pop-up.** The visitor's browser loads the form from `https://sendbeam.io/f/<form id>` and, for the pop-up, the script `https://sendbeam.io/f/<form id>/popup.js`. The URL carries the appearance you chose (colours, corner radius, text size, typeface) so the form matches your theme, and for a pop-up its headline, line underneath, style, image address, eyebrow, button label and whether to show the subscriber count; these are your settings, not anything about the visitor. Your server makes no request to show a form.

**2. A visitor submitting a form.** What they typed — their email address and any other fields on that form — is sent to SendBeam to create the subscriber or deliver the message.

**3. The plugin's settings screens.** While a site administrator has the SendBeam settings page open, your server calls SendBeam with your API key to list your forms (`/api/v1/forms`), your lists (`/api/v1/lists`), the number of people on each list (`/api/v1/lists/<id>/contacts`) and your subscriber total (`/api/v1/contacts`). This is what lets the plugin offer your forms in a dropdown instead of asking you to paste an ID. The results are cached for five minutes. No visitor data is involved and nothing is sent on front-end page loads.

**4. Subscribing someone who ticked the opt-in box** (off by default). When a visitor registers an account, leaves a comment or checks out in WooCommerce (the classic or the block checkout) *and* ticks the subscribe box, their email address, first and last name and which of those three things they were doing are sent to `/api/v1/contacts` and added to the lists you chose. Nobody who has not ticked the box is ever sent, and the box is never pre-ticked.

**5. Forms built with another form plugin** (off by default, and off for every form until you switch it on for that form). When someone submits a Contact Form 7, Elementor Pro, WPForms, Gravity Forms or Fluent Forms form you have connected, and either ticked the consent field you named or filled in a form you marked as a signup form, their email address, first and last name and the name of the form plugin are sent from your server to `/api/v1/contacts`, added to the list you chose (`/api/v1/lists/<id>/contacts`) and, if you set one, given a tag (`/api/v1/tags` and `/api/v1/contacts/<id>/tags`). Nothing else from the form is sent.

**6. Connecting this site to SendBeam** (only when you press the Connect SendBeam button). A window opens on `https://sendbeam.io/connect/wordpress` carrying this site's address, its title, your WordPress administrator email address — used only to fill in the signup or sign-in form, so you do not type it again — and the list of permissions this site is asking for. If you do not already have a SendBeam account you create one there, on sendbeam.io; account creation never happens on your site and your SendBeam password is never seen by WordPress. When you approve, your server makes one call to `https://sendbeam.io/api/v1/connect/exchange` and receives an API key for this site with exactly the permissions you ticked. The key is stored in this site's options table, the same place a pasted key goes. Press nothing and nothing is sent.

What SendBeam does at that moment, in your workspace, is decided by the boxes you tick: if you leave the sending-domain box ticked it adds this site's domain as a sending domain so its DNS records exist for you to copy, and if the workspace has no signup form yet it creates a list called Subscribers and a form called Newsletter signup on it. Afterwards, while a site administrator has the SendBeam settings page open, your server reads `https://sendbeam.io/api/v1/connect/status` with this site's key to show the domain's records and whether it is verified; the answer is cached for a minute. Pressing **Check now** posts to the same address to ask for the DNS to be looked up again. Pressing **Disconnect** posts to `https://sendbeam.io/api/v1/connect/disconnect`, which revokes this site's key in SendBeam, and then the key is deleted from this site.

**Site email** (off by default) posts each email your site sends from your server to `https://sendbeam.io/api/v1/transactional` with your API key: the recipient addresses, subject, body and reply-to, so SendBeam can deliver it from your verified domain. Messages with attachments are left to the server's own mailer.

**E-commerce events** (off by default, each of the three switched on separately, WooCommerce only) posts to `https://sendbeam.io/api/v1/ecommerce/events` with your API key: an email address, and depending on the event a name, an order total and its currency. Order placed sends once an order is paid — when it reaches processing or completed — never for a checkout that ends unpaid, and once per order. Product viewed sends only when the visitor is a known contact (logged in, or an email already given this visit) — never for an anonymous visitor. Cart abandoned is a best-effort heuristic: adding an item to the cart is timestamped locally on your server, and if no order has followed within a window you set (60 minutes by default) and an email became known at some point, one event is sent; nothing is sent for a cart nobody's email was ever known for.

Full setup documentation: [sendbeam.io/docs/wordpress](https://sendbeam.io/docs/wordpress). See also the [SendBeam privacy policy](https://sendbeam.io/legal/privacy) and [terms](https://sendbeam.io/legal/terms).

= Requirements =

A SendBeam account (the Free plan is enough) with at least one form. Form IDs are shown under Forms → your form → Embed.

== Installation ==

1. Install and activate the plugin. Activating it once takes you to **SendBeam → Set up**, a four-step guide. Every step can be skipped, and **Go back to the Dashboard** leaves it — nothing has to be done now, and everything on it is also on the Overview.
2. **Connect.** Press **Connect SendBeam**. Create your account or sign in in the window that opens, tick what this site may do, and the key arrives on its own — there is nothing to copy. If you already have an API key, "I already have an API key" on the same screen still takes one, and so does **SendBeam → Settings → Advanced**.
3. **Verify your sending domain.** The plugin shows the records as a table of Type, Host and Value, each value a field that copies when you click it, with a column saying which are live yet — or it sets them up for you where your registrar supports that, and brings you back here when it has. DNS changes can take up to 24 hours to spread, so the site checks again every hour for a day and you can close the tab. Nothing SendBeam sends for you leaves your own domain until this is done.
4. **Site email**, if you want it: one switch, and a test email that tells you in the page whether it worked — and, if it did not, what went wrong, what it means, what to do about it, and a block of detail you can copy into a support message.
5. **Put a form on the site.** Add the **SendBeam Form** block to a post or page, or use `[sendbeam_form]` anywhere shortcodes work. Pick which form is the default under **SendBeam → Forms**.

Hosts and agencies who set the plugin up themselves can switch the first-run guide off entirely with `add_filter( 'sendbeam_setup_wizard', '__return_false' );`.

== Frequently Asked Questions ==

= Where did Settings → SendBeam go? =

SendBeam has its own menu now, below Settings, with a page for each section instead of seven tabs nothing in the menu mentioned. Every old address still works: `options-general.php?page=sendbeam` and each of its tabs redirect permanently to the page that replaced it, so bookmarks and links in old support replies still land in the right place. The Docs tab became the **Help** page.

= I do not want the setup guide on my clients' sites =

`add_filter( 'sendbeam_setup_wizard', '__return_false' );` switches off the one-time redirect. The guide is still reachable at **SendBeam → Set up** if anybody wants it, and the Overview's checklist is unaffected. The guide never appears when you activate several plugins at once, and never on a site that already has a key.

= My site says the domain is verified but it is still sending as post.sendbeam.io =

Those are two different settings, and until 1.8.3 the Overview let them contradict each other. Verifying a domain proves the domain is yours; the From address decides which address the site actually uses, and it starts as SendBeam's shared one. The site-email step on the Overview now says so plainly and has a **Send from yourdomain.com** button that changes it in one press. You can also set it by hand under **SendBeam → Site email → From address**.

= Can I run the setup guide again? =

Yes — **SendBeam → Help → Run the setup guide again**. It puts you back at step 1 and changes nothing: it walks through what is already set up and shows you what is not.

= How long is the email log kept? =

Thirty days by default; **SendBeam → Site email → Keep log entries for** offers 7, 30 or 90 days, or for ever. Older entries are deleted once a day by a scheduled job, and whatever you choose the log is capped at 20,000 messages with the oldest going first — "for ever" is a promise about time, not about disk space. **Clear the log** empties it now. Only the recipient, the subject, the result and which plugin asked for the message are stored; the message itself never is.

= The test email says it failed. What now? =

The failure card on **SendBeam → Site email** says what went wrong, what it means and what to do, and has a **Details for support** block underneath it — the WordPress and PHP versions, where this site's key is kept (never the key itself), the sending domain and its state, the HTTP status and the raw error, all captured at the moment the send failed rather than whenever you get round to writing. Press **Copy these details** and paste them into an email to hello@sendbeam.io.

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

= Does the plugin tell me if something is wrong? =

Tools → Site Health carries three SendBeam checks: whether this site's key works, whether the sending domain is verified, and whether site email is switched on behind a verified domain. That last one reports as critical when it is not — a site quietly sending its password resets from a domain no mailbox provider can check is worse off than one not using the feature at all. Site Health → Info has a SendBeam section with every state this site holds, and **SendBeam → Help** shows the same thing with a **Copy for support** button.

= Which API key permissions does the plugin need? =

Only what you actually use, and the plugin works with less:

* **Forms (read)** — so the settings page and the block can list your forms instead of asking for an ID. Without it you can still type IDs by hand.
* **Lists (read)** — for the Audience screen and its subscriber counts.
* **Contacts (read and write) and Lists (write)** — only if you switch on the opt-in box for registrations, comments or WooCommerce checkout, or connect a form plugin.
* **Tags (read and write)** — only if a connected form adds a tag.
* **Send site email** (`transactional:send`) — only if you switch on Site email.

Placing a form or a pop-up needs no key at all. Make a separate key for each site under Settings → API keys in SendBeam, and you can define `SENDBEAM_API_KEY` in `wp-config.php` instead of saving it in the database.

= Can I send from a domain that is not this site's? =

Yes. The consent page suggests this site's own domain and lets you type another — a brand domain, or a `mail.` subdomain — and that is what SendBeam sets up and what the plugin shows from then on. If this site runs on a hosting company's own domain (something.wordpress.com and the like) you cannot send from it at all, and the consent page says so and asks for one you own.

= What happens to my list and my forms if I disconnect? =

They stay in SendBeam. Disconnecting revokes this site's key and nothing else: your list, your form, your sending domain and your sender are all still there, and any other site sending from that domain carries on. On this site it puts back the settings Connect filled in for you, and leaves anything you set yourself alone.

= The form step will not tick, but the form is on my site =

It looks in pages and posts, block templates, widgets and the layouts page builders store alongside a page — but not in a theme that prints the shortcode from PHP, or a page built somewhere it cannot read. Press "I've placed it elsewhere" on that step and it will take your word for it.

= Does it work with Contact Form 7, Elementor Pro, WPForms, Gravity Forms or Fluent Forms? =

Yes. Each is set up where that plugin keeps its own settings, and SendBeam → Audience lists which are active:

* **Contact Form 7** — open a form and use its **SendBeam** tab. Name the email, name and consent fields as they appear in the form, without the square brackets.
* **Elementor Pro** — in the Form widget, add **SendBeam** under Actions After Submit, then fill in the SendBeam section with the field IDs from each field's Advanced tab.
* **WPForms** — in the form builder, open **Settings → SendBeam** and pick the fields from the dropdowns.
* **Gravity Forms** — open **Settings → SendBeam** on a form and add a feed. Map the fields, and use conditional logic if you like.
* **Fluent Forms** — choose the forms on **SendBeam → Audience**.

A form sends someone only when they ticked the consent field you named, or when you have marked it as a signup form that people fill in to subscribe. The subscription happens straight after the form is submitted, so the visitor's form is never slowed down, and each attempt appears under Recent subscriptions.

= What about emails with attachments? =

They are left to the server's own mailer for now, and the email log on SendBeam → Site email says so.

= Does it work with caching plugins? =

Yes. The form is an iframe and the pop-up is a script tag, both cache-safe.

== Screenshots ==

1. The SendBeam Form block on a page: the real form, as visitors see it.
2. SendBeam → Overview: what this site is connected to, how far through set-up it is, and what the workspace holds.
3. SendBeam → Set up, the four-step guide a new site sees once. Every step can be skipped and the whole thing can be left.
4. SendBeam → Forms: the default forms and their colours; every form in the workspace is a searchable list further down.
5. SendBeam → Settings → Sending domain: the DNS records, each value one click from the clipboard, with a column saying which are live yet.
6. SendBeam → Site email: the relay, a test send that reports in the page, and the log of what went out.
7. SendBeam → Pop-ups. Rules are matched top to bottom and the first one wins.
8. A pop-up on the site, using the site's own colours.
9. SendBeam → Help: a system-status block that copies for support, and where to get help.

== Changelog ==

= 1.8.4 =
* WooCommerce orders now carry the order number and the time it was placed, so SendBeam stores each order once, attributes it to the email that led to it, and counts it in revenue. Orders reported by earlier versions fired automations and lifetime value but cannot be attributed after the fact.

= 1.8.3 =
* Connect SendBeam: one button connects the site and hands it a key with exactly the permissions you tick, and can add your sending domain at the same time. Pasting a key by hand still works.
* SendBeam has its own menu, with a page for each section: Overview, Forms, Pop-ups, Audience, Site email, E-commerce, Settings and Help. Old Settings → SendBeam links redirect.
* A four-step setup guide on first activation: connect, verify your sending domain, switch on site email, place a form. Every step can be skipped; hosts can turn the guide off with the `sendbeam_setup_wizard` filter.
* Sending domain: DNS records in a Type, Host and Value table, a copy field for every value and a live or not-found mark on each record, automatic setup where the registrar supports it, and an hourly recheck for a day that switches site email on once the domain verifies.
* Forms, lists and the email log are standard WordPress list tables with search, Screen Options and bulk delete.
* The email log is now a database table: search, filters, sorting, retention of 7, 30 or 90 days or for ever, daily pruning and a 20,000-entry cap. Existing entries migrate on the first page load.
* The test email reports the From address, the signing domain and whether your sending domain is in use, with the result shown in the page and copyable detail when it fails.
* Three checks in Tools → Site Health: the key works, the sending domain is verified, and site email is not going out from an unverified domain.
* A Help page with the documentation, shortcodes, permissions and a system-status block that copies for a support email.
* WooCommerce: the opt-in box and the order event work on the block checkout as well as the classic one; Order placed fires once, when the order is paid; pop-ups never open on the cart, checkout or account pages.
* The SendBeam Form block shows a card naming the form in the editor, with a Preview link, in place of a live preview that could not load.
* Admin design: sentence-case labels, the admin colour scheme's own buttons, switches for on/off settings, core-style notices, and a Pop-ups screen that matches the other pages.
* Phone: the DNS records, the email log, the workspace figures and the test email all fit a 393px screen.
* Fixed: Disconnect did not disconnect; a red box flashed on the Overview on every refresh; the Overview and the test email could disagree about which address the site sends as; the DNS records were hidden when a second domain was connected; the connected card offered to connect an already-connected site; the sending-domain step opened the SendBeam app instead of the plugin's own screen.
* Disconnecting revokes a key made by Connect in SendBeam, and leaves a pasted key alone, since another site may be using it.

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
