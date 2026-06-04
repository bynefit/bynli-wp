=== Bynli Connect ===
Contributors:      bynefit
Tags:              bynli, integration, hosting, metering
Requires at least: 6.0
Tested up to:      6.6
Requires PHP:      7.4
Stable tag:        0.1.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Connect a WordPress site to a Bynli team — usage reporting, with shortcodes for forms, events, and donations coming soon.

== Description ==

Bynli Connect is the official WordPress plugin for [Bynli](https://bynli.com). Install it on any WordPress site that Bynli manages on behalf of your team, paste your per-site API key from `/dash/sites/host-keys`, and the plugin will:

* Report daily storage usage to Bynli (no personal data leaves your site — just byte counts and version info).
* Send a heartbeat from the admin so you can verify the connection is alive.
* (Coming soon) Expose shortcodes for embedding Bynli forms, events lists, and donate flows.

This plugin does **not** alter your site's content, change permalinks, or modify any other plugin's behaviour. It is server-to-server only.

== Installation ==

1. Upload `bynli-connect.zip` via Plugins → Add New → Upload, or copy the folder into `wp-content/plugins/`.
2. Activate the plugin.
3. In your Bynli dashboard, open `/dash/sites/host-keys`, pick your `wp_managed` site, and click **Generate key**. Copy the plaintext key once — it isn't shown again.
4. In WordPress: Settings → Bynli Connect. Paste the key. Save.
5. Click **Send heartbeat** to verify the connection.

== Frequently Asked Questions ==

= Does this send any personal data? =

No. The plugin reports only byte counts, request counts, and version strings (WP version, PHP version, plugin version, home URL). No user data, post content, or visitor information is transmitted.

= What if my key is compromised? =

Revoke it from `/dash/sites/host-keys` on Bynli. Generate a new key, paste it in Settings → Bynli Connect, save. The old key stops working immediately.

= Why does the plugin need to know my site slug? =

It doesn't strictly need to — the API key is already tied to a specific site on the Bynli side. The slug field exists for clarity and for future shortcode features.

== Changelog ==

= 0.1.0 =
* Initial release: settings page, daily usage reporting, heartbeat test, HMAC-signed reports.
