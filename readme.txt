=== Bynli Connect ===
Contributors:      bynefit
Tags:              bynli, integration, hosting, metering, shortcodes
Requires at least: 6.0
Tested up to:      6.6
Requires PHP:      7.4
Stable tag:        0.6.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Connect a WordPress site to a Bynli team — daily usage reporting and inline Bynli shortcodes for forms, modals, toasts, confirms, and the floating widget.

== Description ==

Bynli Connect is the official WordPress plugin for [Bynli](https://bynli.com). Install it on any WordPress site that Bynli manages on behalf of your team, paste your per-site API key from `/dash/sites/host-keys`, and the plugin will:

* Report daily storage usage to Bynli (no personal data leaves your site — just byte counts and version info).
* Send a heartbeat from the admin so you can verify the connection is alive.
* Expose shortcodes for embedding Bynli features inline anywhere in WordPress.

This plugin does **not** alter your site's content, change permalinks, or modify any other plugin's behaviour. The shortcodes are inert until placed in a post or page.

== Shortcodes (v0.2) ==

= [bynli-form] =

One-line embed of a Bynli form. The form ID comes from `/dash/forms` on Bynli.

`[bynli-form id="frm_abc123"]`

Optional attributes:
* `style="default"` — `default`, `bootstrap`, or `bare`
* `success="Thanks — we'll be in touch."` — message shown after submit
* `success_mode="toast"` — `toast`, `replace`, or `hide`

= [bynli-modal] =

Click-to-open modal trigger.

`[bynli-modal label="Read more" title="Welcome" body="Thanks for stopping by."]`

Optional attributes:
* `confirm="Got it"` — primary button label
* `cancel="Cancel"` — cancel button label
* `href="/somewhere"` — URL to follow when confirm is clicked

= [bynli-confirm] =

Confirm-before-navigation prompt.

`[bynli-confirm label="Sign out" message="Sign out now?" yes="Sign out" href="/logout" danger="1"]`

= [bynli-toast] =

A toast notification. Fires on page load by default, or on click.

`[bynli-toast message="Welcome back!" kind="success"]`
`[bynli-toast message="Heads up" kind="warning" on="click" label="Show note"]`

Kinds: `info`, `success`, `error`, `warning`.

= [bynli-widget] =

The floating Bynli widget bubble.

`[bynli-widget team="your-team"]`

Optional attributes: `position`, `label`.

== Installation ==

1. Upload `bynli-connect.zip` via Plugins → Add New → Upload, or copy the folder into `wp-content/plugins/`.
2. Activate the plugin.
3. In your Bynli dashboard, open `/dash/sites/host-keys`, pick your `wp_managed` site, and click **Generate key**. Copy the plaintext key once — it isn't shown again.
4. In WordPress: Settings → Bynli Connect. Paste the key. Save.
5. Click **Send heartbeat** to verify the connection.
6. Add any shortcode (e.g. `[bynli-form id="frm_xxx"]`) to a post or page.

== Frequently Asked Questions ==

= Does this send any personal data? =

No. The plugin reports only byte counts, request counts, and version strings. No user data, post content, or visitor information is transmitted.

= What if my key is compromised? =

Revoke it from `/dash/sites/host-keys` on Bynli. Generate a new key, paste it in Settings → Bynli Connect, save. The old key stops working immediately.

= Does Bynli's runtime load on every page? =

No. The `bynli.js` loader is only enqueued on pages where at least one shortcode is present.

== Changelog ==

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
