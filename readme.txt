=== Bynli Connect ===
Contributors:      bynefit
Tags:              bynli, integration, hosting, metering, shortcodes
Requires at least: 6.0
Tested up to:      6.6
Requires PHP:      7.4
Stable tag:        0.2.0
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

= 0.2.0 =
* Add `[bynli-form]`, `[bynli-modal]`, `[bynli-confirm]`, `[bynli-toast]`, `[bynli-widget]` shortcodes.
* Loader (`bynli.js`) is enqueued only when a shortcode is present on the page.

= 0.1.0 =
* Initial release: settings page, daily usage reporting, heartbeat test, HMAC-signed reports.
