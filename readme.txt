=== Bynefit Connect ===
Contributors:      bynefit
Tags:              bynefit, tickets, support, shortcodes, integration
Requires at least: 6.1
Tested up to:      6.6
Requires PHP:      7.4
Stable tag:        0.22.1
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Bring your Bynefit team into WordPress — answer support tickets, drop in live forms, events, and donate cards, and let Bynefit auto-update the plugin for you.

== Description ==

Hook this site up to your Bynefit team. One key, and Bynefit shows up where you already are.

Here's what lights up the moment you save the key:

* **Your support tickets, in wp-admin.** Open Settings → Bynefit Tickets. See every open thread, read the full conversation, reply in place, mark resolved with a closing note, or open a brand new ticket — no bouncing to bynefit.com.
* **Bynefit features inside posts.** Drop a single shortcode and a Bynefit form, events list, donate card, modal, confirm, toast, or floating widget renders live from your team's data. The runtime only loads on pages that actually use one.
* **Daily usage reports.** Bytes and version strings, that's it — no user data, no post content, no visitor info ever leaves your site.
* **Auto-updates from Bynefit.** New version drops on Bynefit, WordPress sees it on **Plugins → Updates**. No swapping zips, no FTP.

This plugin does **not** change your content, modify permalinks, or override any other plugin. The shortcodes are inert until you place them in a post or page.

Connect your site once, and the rest of Bynefit quietly follows you into WordPress.

== Shortcodes ==

= [bynli-form] =

A Bynefit form, in one line.

`[bynli-form id="frm_abc123"]`

Optional:

* `style="default"` — `default`, `bootstrap`, or `bare`
* `success="Thanks — we'll be in touch."` — message shown after submit
* `success_mode="toast"` — `toast`, `replace`, or `hide`

= [bynli-events] =

Live upcoming events from your team. Pulls from Bynefit the moment the page renders.

`[bynli-events team="your-team" limit="5" style="cards"]`

* `style` — `cards` (default), `list`, or `bare`
* `scope` — `upcoming` (default) or `past`
* `limit` — 1 to 50

= [bynli-donate] =

A donation card with preset amounts + a custom amount input. Routes straight to Bynefit's existing donation flow.

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

The floating Bynefit widget bubble.

`[bynli-widget team="your-team"]`

Optional: `position`, `label`.

== Installation ==

1. Upload `bynli-connect.zip` via **Plugins → Add New → Upload**, or copy the folder into `wp-content/plugins/`.
2. Activate the plugin.
3. In your Bynefit dashboard, open `/dash/sites/host-keys`, pick this site, and click **Generate key**. Copy the plaintext key once — Bynefit won't show it again.
4. WordPress: **Settings → Bynefit Connect**. Paste the key. Save.
5. Click **Send heartbeat** to confirm Bynefit is hearing you.
6. Now you're connected. Add a shortcode to any post — or hop over to **Settings → Bynefit Tickets** if you have open support threads.

== Frequently Asked Questions ==

= Does this send any personal data? =

**Site reporting: no.** The daily report sends byte counts, request counts, and version strings. No user data, no post content, and no visitor information leaves your site.

**Bynefit payments: only for orders paid through Bynefit.** If you enable the "Pay with Bynefit" payment method and a shopper chooses it at checkout, that order's details are sent to bynefit.com so the payment can be completed: the buyer's name, email address and phone number, the billing and shipping addresses, and the order's items, quantities, totals and SKUs. This is what lets the Bynefit payment page show the shopper your store and their own order, and pre-fills PayPal so they aren't retyping what you already have.

Nothing is sent for shoppers who browse your site, for orders paid by any other method, or for orders where the Bynefit method was never selected. If you don't enable the Bynefit payment method, no customer data is sent at all.

When you take payments through Bynefit, Bynefit processes that customer data on your behalf — you remain the data controller for your customers.

= What if my API key is compromised? =

Revoke it from `/dash/sites/host-keys` on Bynefit. Generate a new key, paste it in **Settings → Bynefit Connect**, save. The old key stops working the instant Bynefit marks it revoked.

= Does the Bynefit runtime load on every page? =

No. The `bynli.js` loader only enqueues on pages that actually use a Bynefit shortcode. Empty pages stay untouched.

= Can I reply to tickets from WordPress, or do I still need to go to bynefit.com? =

You can do both. Replies, resolves, and new tickets all work from WordPress — and they're attributed to the WordPress user who clicked send, so Bynefit staff see who answered and can email that person back. The full ticket history (attachments, payment refs, transaction-tied tickets) still lives on bynefit.com when you need the long view.

= How do updates work? =

The plugin polls Bynefit every 12 hours for a version manifest. When a new version is available, WordPress shows it on **Plugins → Updates** like any other plugin. Hit **Update now** — that's it. No WordPress.org account, no FTP, no zip-swapping.

= Can I disconnect a site without revoking the key? =

Yes. **Settings → Bynefit Connect → Disconnect** clears the saved key from this install. Bynefit's server-side key stays valid — visit `/dash/sites/host-keys` to revoke it there if you also want to kill it server-side.

= Where do I get support for the plugin itself? =

Open a ticket from **Settings → Bynefit Tickets → Open new ticket**. It lands in front of Bynefit support the same moment it's filed.

== Changelog ==

= 0.22.1 =
* **New — App editing:** Settings → Bynefit Connect → Connection now shows an **App editing** card. Turning it on lets you design this site from the Bynefit app, and while it is on Bynefit can change this site's pages and design on your behalf. On a site you host yourself it is **off until an administrator turns it on**, nothing on your site changes until someone does, and the same card turns it back off at any time. On a site **Bynefit hosts for you** it is on by design — that is part of the hosting rather than something new, and the card now says so on the screen. We are calling this out rather than listing it as a feature because it grants us write access to your site: you should know the control appeared even if you never use it.
* **Fixed:** Logo strips no longer stack down the page on phones. A row of twelve marks took up 736px of scrolling and now takes 224px, and marks are sized for the screen instead of holding their desktop size all the way down.
* **Fixed:** Controls on published pages are now big enough to tap reliably. The carousel dots, arrows and pause button, the background-video button, call-to-action buttons and tab headings were all under the 44px minimum for touch. The carousel dots keep their small look on a mouse-driven screen and only grow their tap area on touch devices.
* **Fixed:** Block styles were being cached under an older version number, so recent styling improvements were not reaching published sites even after updating. Style and script updates now arrive with every release. **Recommended update for any site using Bynefit Sites blocks** — it is what makes the two fixes above visible.

= 0.22.0 =
* **Added (WooCommerce):** Orders paid with Bynefit now arrive at the payment page as *your* checkout — your store name and logo, the shopper's own name, their order number and where it's shipping — instead of a bare total on an unfamiliar page. PayPal is pre-filled from the details they already gave you, so there's less to retype and fewer abandoned carts, and providing the real shipping address is also what qualifies an order for PayPal Seller Protection on physical goods.
* **Privacy — please read:** to do the above, an order paid through Bynefit now sends that order's buyer name, email, phone and billing/shipping addresses (plus item SKUs) to bynefit.com. This happens **only** when you have enabled the Bynefit payment method **and** a shopper actively chooses it at checkout — never for visitors browsing your site, and never for orders paid by any other method. The "Does this send any personal data?" FAQ has been rewritten to describe this accurately. You remain the data controller for your customers; Bynefit processes this data on your behalf to take the payment.

= 0.21.2 =
* **Fixed (WooCommerce):** "Pay with Bynefit" did not appear on stores using the block-based Checkout — shoppers saw "no payment methods available" even with the gateway enabled. The gateway now registers with the Cart/Checkout blocks as well as the classic checkout. **Recommended update for any store taking Bynefit payments.** (After updating, confirm the gateway is enabled under WooCommerce → Settings → Payments and your site-host key is saved under Settings → Bynefit Connect.)
* **Added:** Site visibility now also closes the XML-RPC endpoint while a site is set to Coming soon or Members only. Note this pauses app access that signs in over XML-RPC (e.g. Jetpack, the WordPress mobile apps) until the site is set back to Live.
* **Improved:** Images published without dimensions now reserve their space while loading, so pages no longer shift as they render.
* **Improved:** Section layouts keep items inside the section's own column count, so a wide item can no longer spill outside the grid.
* **Security:** Added request throttling to the Bynefit control-plane endpoints and a safeguard that keeps the control-plane secret out of the settings screen and REST API.

= 0.21.1 =
* **Fixed:** A PHP fatal error ("'continue' not in the 'loop' or 'switch' context") that could occur when validating a page containing a Tabs or Carousel block. Restructured the validation so no page publish can trigger it. Recommended update for all sites.

= 0.21.0 =
* **Added (WooCommerce):** If your site runs WooCommerce, the daily report now includes a light store snapshot — product count, orders awaiting action (processing + on-hold), completed-order count, and out-of-stock count — so the Bynefit app can show your store's status at a glance. Order counts only; no customer details, order contents, or revenue figures are sent.

= 0.20.0 =
* **Added:** Your daily site report now includes a richer health snapshot — available updates (WordPress core, plugins, themes), content counts (posts, pages, media), pending/spam comments, active theme, user and administrator counts, and safety flags (HTTPS, search-engine visibility, debug mode). This powers a real "site health" view in the Bynefit app. All read from data WordPress already has — no extra load on your site, and nothing about your visitors or content is sent.

= 0.19.0 =
* **Added:** Bynefit Sites can now set a site's primary navigation menu when it publishes — the pages you publish are linked into the site's menu automatically, in order, with the home page first. Control-plane only; no effect on manually connected sites.

= 0.18.0 =
* **Added:** Two interactive Bynefit Sites blocks — **Tabs** (accessible tabbed panels, keyboard-operable) and **Carousel** (a testimonial carousel with previous/next and dots; autoplay honors reduced-motion). Both degrade gracefully: with JavaScript off, all the content shows stacked so nothing is hidden.

= 0.17.0 =
* **Changed (internal):** The Bynefit Sites control-plane endpoints now authorize on the signed-request integrity check alone (a per-site secret only Bynefit holds), acting as the site administrator to make the requested change. No effect on manually connected sites — the control plane stays inert until Bynefit provisions the secret, and an unsigned request is still refused.

= 0.16.0 =
* **Added:** Two data-driven Bynefit Sites blocks — **Form** (embeds a form you built in the Bynli form builder by id, inside a clean token-styled card; the form renders and submits securely to Bynli, so no submission data touches this site) and **Events** (shows a team's upcoming or past Bynli events live). Both hydrate from Bynli at page load and only load the runtime on pages that use them.

= 0.15.0 =
* **Added:** Bynefit Sites **hero sections and full-width background video** — a section can now carry a background image or looping video with an overlay scrim, a chosen height, and vertically-centred content, so a full-bleed hero reads edge-to-edge with legible text over the image. Publishing requires the scrim when a background image/video is used, so overlaid text always stays readable.

= 0.14.0 =
* **Added:** Two more Bynefit Sites building blocks — **Card** (a surface container that stacks inner blocks with background, radius, shadow, and padding from your theme; compose feature cells, pricing tiers, and callout cards from the primitive blocks) and **Logo Cloud** (a row of partner/press logos, contained and never cropped, optionally muted to monochrome until hover). Styled from your theme's tokens and gated by the publish-quality contract.

= 0.13.0 =
* **Added:** More Bynefit Sites building blocks — **Icon** (from a curated stroke icon set, coloured and sized from your theme), **List** (feature/benefit lists with check, arrow, or dot markers and optional per-item icons), **Call to Action** (a title, supporting line, and up to two buttons on an optional token surface), and **Callout** (info / success / warning / tip notes with a leading icon). All styled from your theme's tokens and gated by the publish-quality contract.

= 0.12.0 =
* **Added:** Bynefit Sites premium block set — five more server-rendered blocks the builder can publish onto: **Gallery** (responsive focal-point image grid with AVIF/WebP + explicit dimensions), **Quote** (pull quote / testimonial with attribution + avatar), **Stat** (headline metric), **Accordion** (accessible FAQ on native details/summary, no JavaScript), and **Embed** (YouTube / Vimeo / Google Maps, with the source URL built server-side from an allow-listed provider + id — arbitrary embed markup is never accepted). All styled from your theme's tokens and refused at publish time if they don't meet the quality contract.

= 0.11.0 =
* **Added:** Bynefit Sites page publishing (`bynli/v1/page`) — Bynefit turns an in-app design into a native WordPress page, laid out on the Section and Media blocks with your theme's colours, type, and spacing. A published page is refused unless it meets the quality contract (readable contrast, one main heading, described images, no off-system styling) so a managed site can't ship a substandard page. Requires the Bynefit Sites control plane; no effect on manually connected sites.

= 0.10.1 =
* **Added:** Bynefit Sites control plane (`bynli/v1`) — an authenticated REST namespace Bynefit uses to build and update a managed site (starting with applying a design/global styles). Every route is dual-authenticated (a scoped Application Password plus a signed-request integrity check) and stays inert until Bynefit provisions the credential. No effect on manually connected sites.

= 0.10.0 =
* **Added:** Bynefit Sites block substrate — the first server-rendered `bynefit/*` blocks (Section and Media) that Bynefit Sites publishes onto. Section renders a phone-first CSS grid that expands to desktop; Media emits focal-point images/clips with AVIF/WebP sources and explicit dimensions. Server-rendered so a WordPress or plugin update can never corrupt a published page.

= 0.9.7 =
* **Changed:** Client mode now gives the site owner **full control of their own site** — plugins, themes and appearance, all settings, users, and the complete WooCommerce store (Settings, Orders, Products, Analytics, Extensions). It's their site; the earlier lockdown is removed. Two safeguards remain so Bynefit can keep the site managed: the owner can't remove the Bynefit account and can't deactivate Bynefit Connect. Bynefit still handles managed updates and support in the background.

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
