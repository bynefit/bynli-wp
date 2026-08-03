=== Bynefit Connect ===
Contributors:      bynefit
Tags:              bynefit, tickets, support, shortcodes, integration
Requires at least: 6.0
Tested up to:      6.6
Requires PHP:      7.4
Stable tag:        0.9.6
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Bring your Bynefit team into WordPress — answer support tickets, drop in live forms, events, and donate cards, and let Bynefit auto-update the plugin for you.

== Description ==

Hook this site up to your Bynli team. One key, and Bynli shows up where you already are.

Here's what lights up the moment you save the key:

* **Your support tickets, in wp-admin.** Open Settings → Bynli Tickets. See every open thread, read the full conversation, reply in place, mark resolved with a closing note, or open a brand new ticket — no bouncing to bynli.com.
* **Bynli features inside posts.** Drop a single shortcode and a Bynli form, events list, donate card, modal, confirm, toast, or floating widget renders live from your team's data. The runtime only loads on pages that actually use one.
* **Daily usage reports.** Bytes and version strings, that's it — no user data, no post content, no visitor info ever leaves your site.
* **Auto-updates from Bynli.** New version drops on Bynli, WordPress sees it on **Plugins → Updates**. No swapping zips, no FTP.

This plugin does **not** change your content, modify permalinks, or override any other plugin. The shortcodes are inert until you place them in a post or page.

Connect your site once, and the rest of Bynli quietly follows you into WordPress.

== Shortcodes ==

= [bynli-form] =

A Bynli form, in one line.

`[bynli-form id="frm_abc123"]`

Optional:

* `style="default"` — `default`, `bootstrap`, or `bare`
* `success="Thanks — we'll be in touch."` — message shown after submit
* `success_mode="toast"` — `toast`, `replace`, or `hide`

= [bynli-events] =

Live upcoming events from your team. Pulls from Bynli the moment the page renders.

`[bynli-events team="your-team" limit="5" style="cards"]`

* `style` — `cards` (default), `list`, or `bare`
* `scope` — `upcoming` (default) or `past`
* `limit` — 1 to 50

= [bynli-donate] =

A donation card with preset amounts + a custom amount input. Routes straight to Bynli's existing donation flow.

`[bynli-donate team="your-team" amounts="10,25,50,100" default_amount="25" cause="general"]`

* `style` — `card` (default) or `button`
* `cause_label` — friendly label shown to donors
* `modal="1"` — open the donation form in an iframe modal instead of navigating

= [bynli-modal] =

Click-to-open modal.

`[bynli-modal label="Read more" title="Welcome" body="Thanks for stopping by."]`

* `confirm`, `cancel` — button labels
* `href` — where to go when the user confirms

= [bynli-confirm] =

Confirm-before-navigate.

`[bynli-confirm label="Sign out" message="Sign out now?" yes="Sign out" href="/logout" danger="1"]`

= [bynli-toast] =

A toast notification.

`[bynli-toast message="Welcome back!" kind="success"]`
`[bynli-toast message="Heads up" kind="warning" on="click" label="Show note"]`

Kinds: `info`, `success`, `error`, `warning`.

= [bynli-widget] =

The floating Bynli widget bubble.

`[bynli-widget team="your-team"]`

Optional: `position`, `label`.

== Installation ==

1. Upload `bynli-connect.zip` via **Plugins → Add New → Upload**, or copy the folder into `wp-content/plugins/`.
2. Activate the plugin.
3. In your Bynli dashboard, open `/dash/sites/host-keys`, pick this site, and click **Generate key**. Copy the plaintext key once — Bynli won't show it again.
4. WordPress: **Settings → Bynli Connect**. Paste the key. Save.
5. Click **Send heartbeat** to confirm Bynli is hearing you.
6. Now you're connected. Add a shortcode to any post — or hop over to **Settings → Bynli Tickets** if you have open support threads.

== Frequently Asked Questions ==

= Does this send any personal data? =

No. The plugin reports byte counts, request counts, and version strings. No user data, no post content, no visitor information ever leaves your site.

= What if my API key is compromised? =

Revoke it from `/dash/sites/host-keys` on Bynli. Generate a new key, paste it in **Settings → Bynli Connect**, save. The old key stops working the instant Bynli marks it revoked.

= Does the Bynli runtime load on every page? =

No. The `bynli.js` loader only enqueues on pages that actually use a Bynli shortcode. Empty pages stay untouched.

= Can I reply to tickets from WordPress, or do I still need to go to bynli.com? =

You can do both. Replies, resolves, and new tickets all work from WordPress — and they're attributed to the WordPress user who clicked send, so Bynli staff see who answered and can email that person back. The full ticket history (attachments, payment refs, transaction-tied tickets) still lives on bynli.com when you need the long view.

= How do updates work? =

The plugin polls Bynli every 12 hours for a version manifest. When a new version is available, WordPress shows it on **Plugins → Updates** like any other plugin. Hit **Update now** — that's it. No WordPress.org account, no FTP, no zip-swapping.

= Can I disconnect a site without revoking the key? =

Yes. **Settings → Bynli Connect → Disconnect** clears the saved key from this install. Bynli's server-side key stays valid — visit `/dash/sites/host-keys` to revoke it there if you also want to kill it server-side.

= Where do I get support for the plugin itself? =

Open a ticket from **Settings → Bynli Tickets → Open new ticket**. It lands in front of Bynli support the same moment it's filed.

== Changelog ==

= 0.9.6 =
* **Fixed:** Critical — sites using the site-owner (Client) role could hit a fatal error on wp-admin after 0.9.4. The owner-protection capability check re-entered WordPress' permission resolver in a loop; it now reads the resolved capability map directly. Sites on 0.9.4/0.9.5 with client mode enabled should update immediately.

= 0.9.5 =
* **New:** Site owners get a **Site settings** card in the Bynefit portal — set the site title, tagline, timezone, homepage, and search-engine visibility without touching WordPress' full Settings screens.

= 0.9.4 =
* **Improved:** Client mode is now a real "site owner" role — the client can manage their own appearance (Customizer: logo, colors, menus, widgets), moderate comments, and add/manage their own non-admin team members, on top of pages, posts, and media. Plugins, themes, core settings, updates, and security stay with your site manager.

= 0.9.3 =
* **Changed:** The plugin now talks to **bynefit.com** (the new primary domain) instead of bynli.com.
* **Improved:** Bynefit-managed sites can connect automatically — the site-host key is injected at install time (as a must-use plugin), so there's nothing to paste. Manually-installed sites are unchanged.

= 0.9.2 =
* **New:** Client mode lets you add clients right from the console — assign an existing user or invite the site owner by email (no more digging through Users → Edit). The site owner gets a locked account and an email to set their password.
* **New:** The client Portal is a real home base — Pages, Posts, and Media with drafts and counts, plus a **Contact Bynefit** card to reach support without leaving the portal.
* **New:** WooCommerce stores — when WooCommerce is active, a client can manage Orders and Products from the Portal, while the technical store settings stay with the site manager.
* **New:** WooCommerce payments — accept payments through your connected Bynefit account. Add "Pay with Bynefit" under WooCommerce → Settings → Payments; buyers pay on a secure Bynefit-hosted page and no card data ever touches your site.

= 0.9.1 =
* **New:** Site visibility modes — set your site to Live, Coming soon, or Members-only from the Connection panel, with a branded holding page and a `[bynli-gate]` shortcode for gating individual content.
* **New:** Client mode — give a client a locked-down WordPress admin with a branded Bynefit portal, so they see their site status and support without the full dashboard.
* **New:** Shortcode form picker — on the Shortcodes panel, click "Load my forms" and insert a real form by clicking it, instead of copying an id from Bynefit.
* **Changed:** The admin is now light-only. The dark theme and per-user toggle from 0.9.0 were removed for a cleaner, more consistent WordPress-admin look.

= 0.9.0 =
* **Redesigned:** The whole admin is now a single **Bynefit Connect** console — Overview, Connection, Shortcodes, Tickets, Activity, and Updates under one page with a left nav rail.
* **New:** Light and dark themes with a per-user toggle (defaults to your system preference, no reload).
* **New:** Overview dashboard — live uplink status, a 7-day heartbeat sparkline, storage / WordPress / PHP at a glance, and health tiles.
* **New:** Shortcodes previewer — pick a shortcode, copy it, and see a live preview alongside its full attribute list.
* **Improved:** Tickets now live inside the console, and the status tabs show per-status counts.
* **Improved:** Faster admin load — dropped an external font request and slimmed the stylesheet.
* **Rebranded:** Bynli is now **Bynefit**. Your site-host key, shortcodes, and settings are unchanged.

= 0.8.1 =
* **Improved:** Submit buttons on the Tickets pages (reply, mark resolved, open new ticket) now show a spinner while sending — no more wondering whether your reply went through on a slow connection.
* **Improved:** The empty-ticket states are now a proper designed card with an icon and headline instead of a bare line of text, and the ticket list table is cleaner and more consistent with the rest of the page.
* Under the hood: unified the button and color system across the Tickets surface and tidied the stylesheet (no behavior change).

= 0.8.0 =
* **Improved:** Replying and marking resolved on a ticket no longer reloads the page. Replies appear inline at the bottom of the thread the moment Bynli accepts them; marking resolved swaps the form area for the closed-thread banner in place. Validation and server errors show in the form, not as URL flash codes.
* **Fix:** The "Open on Bynli" button on the ticket detail view now opens the Bynli support center landing instead of an unrelated form route. A direct deep-link to the specific ticket is coming once the server exposes it.

= 0.7.0 =
* **New:** Open a support ticket from WordPress. The **Bynli Tickets** page has an "Open new ticket" panel (subject + category + message); submissions are site-attributed and immediately visible to Bynli staff. No more bouncing to bynli.com to file a ticket.
* Categories supported from WordPress: Technical, Billing, Account, General. Payment + dispute tickets still need to be opened from bynli.com (they require a transaction reference).
* The form names the WordPress user the ticket will be filed as, with the email Bynli staff will reply to.
* Submission is AJAX — validation errors render inline; on success the new ticket's detail view opens automatically.

= 0.6.1 =
* **Improved:** Replies + Mark resolved now send the active WordPress user's display name and email to Bynli. Threads show the actual person who replied (instead of just the site host), and Bynli staff can email that person back even if they have no Bynli account.
* The reply form now shows which WP user the reply will be posted as, with the email Bynli will use for follow-ups.

= 0.6.0 =
* **New:** Reply to support tickets directly from the **Bynli Tickets** page. Posts as your connected WordPress site — no specific Bynli user attribution (that's coming in a later release).
* **New:** "Mark resolved" action with an optional final note. Idempotent — double-clicking is safe.
* **New:** `Bynli_Connect_Api::post()` helper for signed POSTs (sibling to `::get()`). Reused by reply and resolve; available for future write surfaces.

= 0.5.0 =
* **New:** **Bynli Tickets** page in Settings — see your team's open support tickets and read the full thread without leaving WordPress. Auth uses the existing site-host key; no new credentials. Reply / resolve from WordPress come in a later release; for now those still happen on Bynli.
* **New:** `Bynli_Connect_Api::get()` helper — signed-GET path for any future read endpoint to reuse.

= 0.4.0 =
* **New:** `[bynli-events]` shortcode — drop an upcoming-events list anywhere on your WordPress site, sourced live from your Bynli team. Three render modes: `cards` (default), `list`, `bare`.
* **New:** `[bynli-donate]` shortcode — preset amount picker + custom amount input, routed to Bynli's existing donation flow with `source=embed` attribution.

= 0.3.2 =
* **Fix:** the auto-updater's source-rename filter was comparing a trailing-slashed path against a non-trailing-slashed one, then trying to "rename" the unzipped folder to itself. On some hosts this left the temp directory unreadable, producing `Filesystem error. A directory could not be read.` during the install step. The filter now short-circuits when the source basename is already correct and skips the move when paths are equivalent.
* Settings page rebuilt to match the Bynli ecosystem aesthetic — Bricolage Grotesque wordmark with the gold period accent, warm-dark header card on ivory background, restrained editorial typography.
* Heartbeat is now AJAX — no page reload, the status pill flips live without losing your scroll position.
* New "Disconnect" action clears the saved key from this install (does not revoke it server-side).
* Defensive CSS containment so WordPress color schemes, RTL, mobile, and reduced-motion preferences don't break the layout.

= 0.3.1 =
* Settings page redesign — Bynli-branded header with connection status pill, separate cards for Connection / Activity / Shortcodes / Updates, copy-to-clipboard for shortcode examples, reveal/hide toggle for the API key, inline format validation.
* Onboarding card shown when no API key is set, with a direct link to the Bynli host-keys page.
* Activity card surfaces last report time, kind, HTTP, and next scheduled daily run.

= 0.3.0 =
* Auto-updates from Bynli — WordPress now sees Bynli Connect updates in Plugins → Updates and on the Updates page. No more manual zip swaps.
* Settings → Bynli Connect now shows the installed vs latest version and a "Check for updates now" button.

= 0.2.0 =
* Add `[bynli-form]`, `[bynli-modal]`, `[bynli-confirm]`, `[bynli-toast]`, `[bynli-widget]` shortcodes.
* Loader (`bynli.js`) is enqueued only when a shortcode is present on the page.

= 0.1.0 =
* Initial release: settings page, daily usage reporting, heartbeat test, HMAC-signed reports.
