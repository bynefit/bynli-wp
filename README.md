# Bynli Connect for WordPress

Hook a WordPress site up to your Bynli team. One key, and the rest of Bynli shows up where you already are.

This is the plugin that runs on customer WordPress installs. Small surface, no nonsense:

- **Your support tickets, in wp-admin.** Read the thread, reply, mark resolved, open a new one — without leaving WordPress. Replies and resolutions land instantly with no page reload.
- **Daily usage reports to Bynli.** Storage bytes and WP/PHP versions. No user data, no post content, no visitor info. Bytes and version strings, that's it.
- **Bynli features inside posts.** Drop a shortcode in any page and it renders live from your Bynli team's data.
- **Auto-updates from Bynli.** New version lands on Bynli, WordPress sees it on Plugins → Updates. No more swapping zips by hand.

## Install

1. Grab the latest `bynli-connect.zip` from [Releases](https://github.com/bynefit/bynli-wp/releases).
2. WP admin → **Plugins → Add New → Upload Plugin** → pick the zip → **Activate**.
3. Open [bynli.com/dash/sites/host-keys](https://bynli.com/dash/sites/host-keys) (you'll need to be signed in as a team admin), generate a key for this site, and copy it once — Bynli won't show it again.
4. WP admin → **Settings → Bynli Connect** → paste the key → **Save** → **Send heartbeat** to confirm Bynli is hearing you.

That's it. From here you can add any shortcode to any post or page.

## Shortcodes — drop in, render live

```text
[bynli-form id="frm_abc123"]
[bynli-events team="your-team" limit="5" style="cards"]
[bynli-donate team="your-team" amounts="10,25,50,100" default_amount="25" cause="general"]
[bynli-modal label="Read more" title="Welcome" body="Thanks for stopping by."]
[bynli-confirm label="Sign out" message="Sign out now?" href="/logout"]
[bynli-toast message="Welcome back!" kind="success"]
[bynli-widget team="your-team"]
```

The `bynli.js` loader only enqueues on pages that actually use a shortcode — empty pages stay untouched.

Full reference: [bynli.com/guides/wordpress](https://bynli.com/guides/wordpress).

## Tickets surface

Once you're connected, **Settings → Bynli Tickets** lights up:

- See every open, in-progress, or resolved ticket for your team.
- Click a row to read the full thread, see attachments, and answer in place.
- Mark a ticket resolved with an optional closing note.
- Open a brand new ticket without bouncing to bynli.com.

Replies and resolves are attributed to the WordPress user who clicked send — Bynli labels the thread accordingly and routes any follow-up email to the same address.

## What this plugin does NOT do

- It does **not** modify your site's content, change permalinks, or override other plugins.
- It does **not** send anything visitor-related to Bynli. Storage and version numbers only.
- It does **not** load `bynli.js` on pages without a shortcode.
- It does **not** store the API key in plaintext anywhere visible — the settings page input is password-typed, with a deliberate one-tap reveal.

## How it talks to Bynli

Every call is a signed HTTP request to `bynli.com`. Two pieces:

```text
HMAC_SHA256( plaintext_key, timestamp + "\n" + body )
```

```text
Authorization:      Bearer <bynli_sh_...>
X-Bynli-Timestamp:  <unix-seconds>
X-Bynli-Signature:  sha256=<hex>
Content-Type:       application/json
```

The Bynli server rejects timestamps outside a 5-minute window, so replay attacks need a tight clock.

## Repo layout

```text
bynli-connect.php             plugin entry — version, constants, bootstraps
includes/
  class-plugin.php            singleton + cron schedule
  class-settings.php          settings page + heartbeat AJAX
  class-signer.php            HMAC-SHA256
  class-api.php               signed GET + POST helper
  class-reporter.php          daily report + heartbeat
  class-shortcodes.php        all seven shortcodes
  class-tickets.php           Bynli Tickets submenu (list / detail / reply / resolve / new)
  class-updater.php           self-update from /api/site-host/version
assets/
  admin.css                   bcn-* styles
  admin.js                    wireAjaxForms + heartbeat + reveal toggle
readme.txt                    WordPress.org-formatted readme
```

## Working on the plugin

No build step. Plain PHP + vanilla JS.

```bash
git clone https://github.com/bynefit/bynli-wp.git
cd bynli-wp
# symlink into a local WP install
ln -s "$(pwd)" /path/to/wordpress/wp-content/plugins/bynli-connect
```

Then activate via WP admin and point its API base at your dev Bynli (Settings → Bynli Connect → API base).

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
