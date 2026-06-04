# Bynli Connect (WordPress plugin)

Official WordPress plugin for [Bynli](https://bynli.com). Connects a WordPress site to a Bynli team for daily usage reporting and (soon) inline shortcodes for forms, events, and donations.

## What it does today (0.1.0)

- **Daily usage report** to `POST https://bynli.com/api/site-host/report` — storage bytes, WP/PHP versions, home URL. No user data.
- **Heartbeat test** from Settings → Bynli Connect to verify the connection.
- **HMAC-signed** payloads with a per-site key issued by `/dash/sites/host-keys` on Bynli.

## What's coming

- Shortcodes: `[bynli-form id="frm_xxx"]`, `[bynli-events]`, `[bynli-donate]`
- Bandwidth counting from server logs (when available)
- Auto-update from WordPress.org plugin directory

## Install

1. Download `bynli-connect.zip` from the [Releases page](https://github.com/bynefit/bynli-wp/releases).
2. WP admin → Plugins → Add New → Upload Plugin → choose the zip → Install Now → Activate.
3. Settings → Bynli Connect → paste your API key (generated at `https://bynli.com/dash/sites/host-keys`).
4. Click **Send heartbeat** to verify.

## Repo layout

```
bynli-connect.php          plugin entry, header, requires + bootstraps
includes/
  class-plugin.php         lifecycle (activation, cron schedule)
  class-settings.php       admin settings page + test-heartbeat handler
  class-reporter.php       wp_remote_post wrapper, daily cron handler
  class-signer.php         HMAC-SHA256 signer
readme.txt                 WordPress.org-formatted readme
```

## Signing scheme (must match Bynli server)

```
HMAC_SHA256( plaintext_key, timestamp + "\n" + body )
```

Headers on every request:
```
Authorization:      Bearer <bynli_sh_...>
X-Bynli-Timestamp:  <unix-seconds>
X-Bynli-Signature:  sha256=<hex>
Content-Type:       application/json
```

Server rejects timestamps outside a 300-second window (replay protection).

## Development

This plugin has no build step — plain PHP. To work on it:

```bash
git clone https://github.com/bynefit/bynli-wp.git
cd bynli-wp
# symlink into a local WP install's wp-content/plugins/
ln -s "$(pwd)" /path/to/wordpress/wp-content/plugins/bynli-connect
```

## License

GPL-2.0-or-later. See `LICENSE`.
