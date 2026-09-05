<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Updater {
    const TRANSIENT_KEY    = 'bynli_connect_update_check';
    const TRANSIENT_TTL    = 12 * HOUR_IN_SECONDS;
    const VERSION_ENDPOINT = '/api/site-host/version';
    const PLUGIN_SLUG      = 'bynli-connect';

    private $plugin_basename;

    /**
     * Is this install running as a must-use plugin?
     *
     * WordPress cannot update an mu-plugin: get_plugins() scans WP_PLUGIN_DIR only,
     * the Must-Use tab renders no update action, and Plugin_Upgrader targets
     * WP_PLUGIN_DIR. So on a Bynefit-managed site this plugin's own updater is inert
     * by design and Bynefit pushes the update server-side on the call-home instead.
     *
     * The precise test is WHERE THE FILE LIVES, not whether BYNLI_CONNECT_KEY is
     * defined. That constant is a proxy for "managed" and the two are not the same
     * question: a managed site could in principle be a normal plugin install, and
     * what decides whether WordPress can apply an update is the directory.
     *
     * realpath() on both sides so a symlinked mu-plugins directory — which is how
     * several managed stacks lay this out — still compares equal.
     */
    public static function is_mu_install(): bool {
        // Per-request constant: nothing can move the plugin mid-request, and this is
        // read at least twice per Settings load plus once per update-transient fire.
        // Without this the DEFAULT install recomputes a constant false on every read:
        // two realpath calls where there is no mu-plugins directory, and a scandir
        // plus a realpath per entry where there is one.
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        if (!defined('WPMU_PLUGIN_DIR') || !defined('BYNLI_CONNECT_PLUGIN_FILE')) {
            return $cached = false;
        }
        $mu   = realpath(WPMU_PLUGIN_DIR);
        $self = realpath(BYNLI_CONNECT_PLUGIN_FILE);
        if ($mu === false || $self === false) {
            return $cached = false;
        }
        $mu_n   = rtrim(str_replace('\\', '/', $mu), '/') . '/';
        $self_n = str_replace('\\', '/', $self);
        if (strpos($self_n, $mu_n) === 0) {
            return $cached = true;
        }

        // The prefix test alone is not enough. __FILE__ is ALREADY
        // symlink-resolved by PHP, so realpath() on it is a no-op — which means a
        // mu-plugins directory holding a SYMLINK to a plugin that lives elsewhere
        // (a common managed-hosting layout) reports the real out-of-tree path, fails
        // the prefix, and is judged not-managed. That hands the site straight back the
        // un-clearable update badge this detection exists to remove.
        //
        // So when the fast path misses, ask the directory instead of the file: does any
        // entry in mu-plugins RESOLVE to us? Only reached when the answer would
        // otherwise be false — which IS the ordinary install, so it is the ordinary
        // install that pays for it. That is exactly why the result is memoised above;
        // an earlier version of this line claimed the opposite and sat directly below
        // the cache comment that contradicts it.
        $entries = @scandir($mu);
        if ($entries === false) {
            return $cached = false;
        }
        // Our bootstrap file, or our own plugin directory — NOTHING higher.
        //
        // This was `strpos($self_n, $candidate . '/') === 0`, which accepts any
        // ANCESTOR of our file. A mu-plugins symlink pointing at a shared
        // application root, a vendor tree or a deploy-releases directory would then
        // resolve to an ancestor and mark the site managed. That fails in the
        // dangerous direction: a genuinely self-hosted site, which CAN apply the
        // update, is told Bynefit applies it — every affordance goes quiet and
        // inject_update() files the entry under no_update, so WordPress stops
        // offering it too. The admin sits on an old version, reassured.
        //
        // The fast path above never had this problem: it appends a trailing slash to
        // the mu-plugins ROOT, which is the correct containment test. This is the
        // equivalent, and it still covers the symlink-to-plugin-folder case, since a symlink
        // normally points at the plugin folder.
        $self_dir = rtrim(str_replace('\\', '/', dirname($self)), '/');
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $candidate = realpath($mu . DIRECTORY_SEPARATOR . $entry);
            if ($candidate === false) {
                continue;
            }
            $candidate = rtrim(str_replace('\\', '/', $candidate), '/');
            if ($candidate === $self_n || $candidate === $self_dir) {
                return $cached = true;
            }
        }
        return $cached = false;
    }

    public function __construct() {
        $this->plugin_basename = plugin_basename(BYNLI_CONNECT_PLUGIN_FILE);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api',                           [$this, 'plugins_api'], 10, 3);
        // BEFORE the unzip. upgrader_source_selection runs after WordPress has already
        // unpacked the archive, so it is too late to refuse a tampered one — this is
        // the only hook that sees the package while it is still a file.
        add_filter('upgrader_pre_download',                 [$this, 'verify_download'], 10, 4);
        add_filter('upgrader_source_selection',             [$this, 'rename_source'], 10, 4);
        add_action('upgrader_process_complete',             [$this, 'clear_cache'], 10, 2);
        add_action('admin_post_bynli_connect_clear_update_cache', [$this, 'handle_clear_cache']);
        // wp_plugin_update_row() does NOT read $entry->upgrade_notice — that is the theme
        // path. For a plugin it fires this action after the update message, which is the
        // documented way to put text inline under the row. 0.23.0 starts refusing designs
        // that publish today, so the warning has to reach an admin who bulk-updates and
        // never opens the lightbox.
        add_action('in_plugin_update_message-' . $this->plugin_basename, [$this, 'update_message'], 10, 2);
    }

    public function inject_update($transient) {
        if (!is_object($transient)) { $transient = new stdClass(); }
        if (!isset($transient->response))    { $transient->response    = []; }
        if (!isset($transient->no_update))   { $transient->no_update   = []; }

        $remote = $this->get_remote_manifest();
        if (!$remote || empty($remote['version'])) {
            return $transient;
        }

        $entry = $this->build_update_entry($remote);

        // An mu-plugin install goes in no_update even when a newer version exists.
        // Putting it in ->response is what increments WordPress's update badge, and on
        // an mu install NOTHING in WordPress can ever decrement it again: there is no
        // update action on the Must-Use tab, and Plugin_Upgrader would target the wrong
        // directory. The admin would carry a permanent "1 update" dot for a plugin they
        // are not able to update and that Bynefit is already keeping current.
        //
        // The entry itself is still registered so plugins_api and the version readout
        // keep working — the panel below reports the real installed-vs-latest state and
        // says who applies it.
        if (self::is_mu_install()) {
            $transient->no_update[$this->plugin_basename] = $entry;
            unset($transient->response[$this->plugin_basename]);
            return $transient;
        }

        if (version_compare($remote['version'], BYNLI_CONNECT_VERSION, '>')) {
            $transient->response[$this->plugin_basename] = $entry;
            unset($transient->no_update[$this->plugin_basename]);
        } else {
            $transient->no_update[$this->plugin_basename] = $entry;
            unset($transient->response[$this->plugin_basename]);
        }

        return $transient;
    }

    /**
     * The name to show a human, from the most authoritative source available.
     *
     * Order: the server manifest (which the release process already updates), then
     * the plugin header via get_plugin_data, then a literal as the last resort. A
     * hardcoded literal here is what produced the two-names defect — it drifted from the
     * header, the readme, the admin menu and the manifest, all four of which were
     * already correct.
     *
     * get_plugin_data lives in wp-admin/includes/plugin.php, which is loaded on
     * admin requests but not guaranteed on every code path that can reach the
     * updater, so it is guarded rather than assumed.
     *
     * @param array $remote Decoded manifest.
     */
    private static function plugin_display_name(array $remote): string {
        $from_manifest = isset($remote['name']) ? trim((string) $remote['name']) : '';
        if ($from_manifest !== '') {
            return $from_manifest;
        }
        if (function_exists('get_plugin_data') && defined('BYNLI_CONNECT_PLUGIN_FILE')) {
            $data = get_plugin_data(BYNLI_CONNECT_PLUGIN_FILE, false, false);
            $name = isset($data['Name']) ? trim((string) $data['Name']) : '';
            if ($name !== '') {
                return $name;
            }
        }
        return 'Bynefit Connect';
    }
    public function plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information') return $result;
        if (empty($args->slug) || $args->slug !== self::PLUGIN_SLUG) return $result;

        $remote = $this->get_remote_manifest();
        if (!$remote || empty($remote['version'])) return $result;

        $info = new stdClass();
        // From the manifest, falling back to the plugin header — never a literal.
        // This was hardcoded 'Bynli Connect', which is the title WordPress renders
        // at the top of the "View version details" lightbox, immediately above the
        // release notes. The 0.22.1 notes tell the reader to open "Settings →
        // Bynefit Connect" under a heading that said the old name. Reading it from
        // the source that already carries the right value is what stops it drifting
        // again.
        $info->name          = self::plugin_display_name($remote);
        $info->slug          = self::PLUGIN_SLUG;
        $info->version       = (string)$remote['version'];
        $info->author        = '<a href="https://bynefit.org">Bynefit</a>';
        $info->homepage      = 'https://bynefit.com/help/wordpress';
        $info->requires      = $remote['requires']      ?? '6.1';
        $info->tested        = $remote['tested']        ?? '6.6';
        $info->requires_php  = $remote['requires_php']  ?? '7.4';
        $info->last_updated  = $remote['last_updated']  ?? '';
        $info->download_link = (string)($remote['download_url'] ?? '');
        $info->trunk         = $info->download_link;
        $info->sections = [
            'description' => $remote['description'] ?? 'Connect a WordPress site to Bynefit — daily usage reporting and Bynefit shortcodes.',
            'changelog'   => $remote['changelog']   ?? '',
        ];
        // The lightbox renders any sections key as a tab, and this is the .org-shaped
        // place a plugin's upgrade notice lives. Only added when there is one, so an
        // ordinary release does not grow an empty tab.
        if (!empty($remote['upgrade_notice'])) {
            $info->sections['upgrade_notice'] = (string) $remote['upgrade_notice'];
        }
        if (!empty($remote['banners']) && is_array($remote['banners'])) {
            $info->banners = $remote['banners'];
        }
        return $info;
    }

    /**
     * Download our own package and refuse it if it does not match the manifest hash.
     *
     * WordPress checks nothing about an update archive: no signature, no checksum. It
     * fetches whatever `package` says and unzips it over the live plugin directory. The
     * manifest has carried `download_sha256` since the server side of this landed, and
     * nothing has ever read it, so the field has been decorative.
     *
     * Runs on `upgrader_pre_download` because it is the only hook that sees the package
     * while it is still a FILE — `upgrader_source_selection`, which this class already
     * uses, fires after the unpack, by which point refusing is pointless.
     *
     * Contract, deliberately asymmetric:
     *   hash present and MATCHES   -> proceed with the file we already downloaded
     *   hash present and MISMATCHES -> hard stop, WP_Error, nothing is unpacked
     *   package on a host our manifest does not publish from -> hard stop, WP_Error
     *   package on OUR host that the manifest does not describe (a release landed between
     *     WordPress's transient and ours) -> unverifiable, logged, allowed
     *   hash ABSENT or empty        -> proceed, unverified
     *
     * The absent case is permissive on purpose. Older manifests do not carry the field,
     * and treating "unverifiable" as "refuse" would stop updates fleet-wide the moment
     * a server rolled back — turning a security improvement into an availability
     * incident on sites that cannot self-heal.
     *
     * Only OUR package: $hook_extra carries the plugin basename, and anything else
     * returns $reply untouched so every other updater on the site is unaffected.
     *
     * @param bool|WP_Error $reply      false to let WP download normally.
     * @param string        $package    The URL being fetched.
     * @param object        $upgrader
     * @param array         $hook_extra
     * @return bool|string|WP_Error
     */
    /**
     * Inline breaking-change warning under the plugin row on the Plugins screen.
     *
     * Reads the same manifest field the lightbox tab does, so the two cannot drift.
     */
    public function update_message($plugin_data, $response): void
    {
        $notice = is_object($response) ? (string) ($response->upgrade_notice ?? '') : '';
        if ($notice === '') {
            return;
        }
        echo '<br><strong>' . esc_html__('Please read before updating:', 'bynli-connect') . '</strong> '
            . esc_html($notice);
    }

    public function verify_download($reply, $package, $upgrader = null, $hook_extra = []) {
        // Someone earlier in the chain already handled it.
        if ($reply !== false) {
            return $reply;
        }
        $plugin = is_array($hook_extra) && isset($hook_extra['plugin']) ? (string) $hook_extra['plugin'] : '';
        if ($plugin !== '' && $plugin !== $this->plugin_basename) {
            return $reply;
        }
        if (!is_string($package) || $package === '') {
            error_log('[Bynli Connect] update: no package URL to verify, so this install is'
                . ' proceeding WITHOUT checksum verification');
            return $reply;
        }

        // OWNERSHIP FIRST, and this ordering is load-bearing. WordPress sets
        // $hook_extra['plugin'] for plugin updates only — a theme, core, or language-pack
        // download arrives with it empty, so the guard above does not fire and control
        // reaches here. Fetching the manifest before deciding whether the package is even
        // ours meant that, with the error cache bypassed, EVERY such download paid a fresh
        // 8-second blocking request whenever the version endpoint was cold. A bulk update
        // of core plus five themes would have added roughly 48 seconds to a run that is
        // already near max_execution_time, and reset the error window each time so it
        // never settled.
        //
        // So: resolve the manifest through the cache to establish ownership, and only
        // bypass once we know the package is ours — which is the only case the bypass was
        // added for. Note this call is read-or-fetch, not a pure cache read: on a cold
        // transient it does go to the network, once, bounded thereafter by the hour-long
        // error entry.
        $cached_manifest = $this->get_remote_manifest();
        $our_url = is_array($cached_manifest) ? (string) ($cached_manifest['download_url'] ?? '') : '';

        // Plugin_Upgrader::install() passes no 'plugin' key, so a fresh install or a
        // repair of OUR OWN package arrives here with $plugin === '' and is identified
        // only by its URL. Resolving that URL from a cached ERROR gives '', the ownership
        // test below then declines the package, and it installs unverified with nothing
        // written down — the only silent skip on this path, created by the reordering
        // that stopped theme downloads paying for a fetch.
        //
        // The host check is what keeps the perf win: a wordpress.org theme still
        // short-circuits without touching the network. Only a package served from our own
        // API host, during a cached failure, is worth one fetch to identify.
        if ($plugin === '' && $our_url === ''
            && is_array($cached_manifest) && !empty($cached_manifest['error'])
            && self::url_host($package) !== ''
            && self::url_host($package) === self::url_host(Bynli_Connect_Settings::api_base())) {
            $cached_manifest = $this->get_remote_manifest(true);
            $our_url = is_array($cached_manifest) ? (string) ($cached_manifest['download_url'] ?? '') : '';
        }

        if ($plugin === '' && ($our_url === '' || $package !== $our_url)) {
            // Gated on the HOST, not on whether the manifest resolved. The previous
            // version tested $our_url === '' as well, so the commoner case — manifest
            // fine, package URL simply different, e.g. an admin reinstalling a pinned
            // 0.22.1 zip while the manifest names 0.23.0 — was declined and installed
            // unverified with nothing written down. That is the same shape as the defect
            // this log was added for.
            if (self::url_host($package) !== ''
                && self::url_host($package) === self::url_host(Bynli_Connect_Settings::api_base())) {
                error_log('[Bynli Connect] update: a package from our own host could not be'
                    . ' identified against the release manifest, so it is being installed'
                    . ' WITHOUT checksum verification');
            }
            return $reply;
        }

        // Only bypass a cached error this request did NOT write. Bypassing one we just
        // wrote means two 8-second fetches inside download_package for a single answer —
        // which is worse than what the bypass was added to fix.
        $remote = $this->fetched_this_request
            ? $cached_manifest
            : $this->get_remote_manifest(true);
        // The hash and the package come from two DIFFERENT transients with independent
        // lifetimes: $package was built from WordPress's update_plugins entry at inject
        // time, $expected from whatever our manifest says now. A release landing between
        // those two refreshes gives the hash of vN+1 against the bytes of vN — and the
        // failure the admin sees is 'the download was corrupted or tampered with', on a
        // perfectly good package, with no self-service recovery.
        //
        // That tolerance is scoped to OUR OWN HOST, and the scoping is the whole control.
        // An earlier version of this branch skipped verification whenever the URLs merely
        // differed, which meant anything able to filter site_transient_update_plugins —
        // another plugin, a compromised one, a DB write — could point $package at its own
        // ZIP, leave ->plugin correct, and have this function DOWNGRADE a checksum
        // mismatch into a logged skip. WordPress would then unpack that archive over the
        // live plugin directory. On main the same input was a hard stop, and this function
        // exists precisely because WordPress checks nothing about an update archive.
        //
        // So: same host, different URL is the stale-release race and is unverifiable.
        // Different host is a foreign package and is refused outright.
        $describes_this_package = is_array($remote)
            && (string) ($remote['download_url'] ?? '') === $package;
        $manifest_url  = is_array($remote) ? (string) ($remote['download_url'] ?? '') : '';
        $trusted_host  = self::url_host($manifest_url) !== ''
            ? self::url_host($manifest_url)
            : self::url_host(Bynli_Connect_Settings::api_base());

        if (!$describes_this_package && $trusted_host !== ''
            && self::url_host($package) !== $trusted_host) {
            error_log('[Bynli Connect] update REFUSED: the package URL is not on the host'
                . ' our release manifest publishes from, so it was not installed');
            return new WP_Error(
                'bynli_connect_foreign_package',
                __('This update was going to be downloaded from somewhere other than Bynefit, so it was not installed. Contact Bynefit support if you keep seeing this.', 'bynli-connect')
            );
        }

        $expected = $describes_this_package && isset($remote['download_sha256'])
            ? strtolower(trim((string) $remote['download_sha256']))
            : '';
        if (is_array($remote) && !$describes_this_package && $manifest_url !== '') {
            error_log('[Bynli Connect] update: the release manifest describes a different'
                . ' package on the same host, so this package is being installed WITHOUT'
                . ' checksum verification');
        }

        // Nothing to check against — proceed exactly as before. The permissive default
        // is deliberate: a manifest published before this field existed must still be
        // installable. But the two ways of getting here are not the same event, and
        // only one of them is expected, so they are not recorded the same way.
        if ($expected === '') {
            // Two ways to get here and they are not the same event, but BOTH of them
            // install a package without verifying it, so both are recorded. The release
            // note promises a skip is always recorded, and for one release that was
            // false: a manifest that simply predates the field skipped SILENTLY, which is
            // the commoner of the two paths and the one an auditor looks for first.
            //
            // The unavailable test is for a USABLE manifest, not merely a present one.
            // Caching a failed check made this return an array — ['version' => '',
            // 'error' => …] — so an is_array() test stopped firing and the skip went
            // unrecorded for the hour that failure is cached. Two fixes on this branch,
            // each right alone, cancelling each other on the log whose only job is to
            // prove the control did not run.
            if (!is_array($remote) || empty($remote['version'])) {
                // The reason comes from the CACHED entry, not from $remote. With the error
                // cache bypassed, $remote is null on every failure path, so reading the
                // detail off it printed nothing — a log promising a reason and never
                // carrying one.
                $why = is_array($cached_manifest) && !empty($cached_manifest['error'])
                    ? ' (' . preg_replace('/[^\x20-\x7E]/', '', substr((string) $cached_manifest['error'], 0, 60)) . ')'
                    : '';
                error_log('[Bynli Connect] update: release manifest unavailable' . $why
                    . ', so this package is being installed WITHOUT checksum verification');
            } else {
                error_log('[Bynli Connect] update: release manifest carries no'
                    . ' download_sha256 for v'
                    . preg_replace('/[^\x20-\x7E]/', '', substr((string) $remote['version'], 0, 32))
                    . ', so this package is being installed WITHOUT checksum verification');
            }
            return $reply;
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $expected)) {
            // A field that is present and unusable is a release-process defect, and it
            // silently downgrades every install to unverified.
            //
            // The value reached this line BECAUSE it failed the hex check, so it is
            // arbitrary bytes by definition. Truncating it was not enough: a newline
            // inside it forges a second line in the site's error log, and the line an
            // attacker would forge is one that reads like a successful verification —
            // in the log whose only purpose here is to record that verification did not
            // happen. Hex-encoded, so whatever it contains lands as one field on one
            // line and is still identifiable to whoever is debugging the release.
            error_log('[Bynli Connect] update: manifest carries an unusable'
                . ' download_sha256, so this package is being installed WITHOUT checksum'
                . ' verification — got 0x' . bin2hex(substr($expected, 0, 16))
                . ' (' . strlen($expected) . ' chars)');
            return $reply;
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp = download_url($package);
        if (is_wp_error($tmp)) {
            // Returning $reply hands the package back to WordPress, which fetches it
            // itself and may well succeed — so this is not "WordPress reports the
            // failure", it is an install that proceeds with no checksum check and, until
            // now, no record. The readme makes a Security-headed promise that a skip is
            // always recorded; this was the path that made it false.
            error_log('[Bynli Connect] update: could not fetch the package for'
                . ' verification (' . preg_replace('/[^\x20-\x7E]/', '', (string) $tmp->get_error_code())
                . '), so this package is being installed WITHOUT checksum verification');
            // Let WordPress report its own download failure.
            return $reply;
        }

        $actual = @hash_file('sha256', $tmp);
        if ($actual === false) {
            @unlink($tmp);
            error_log('[Bynli Connect] update: could not hash the downloaded package');
            return new WP_Error(
                'bynli_connect_hash_failed',
                __('Could not verify the downloaded update. The update was not installed.', 'bynli-connect')
            );
        }

        if (!hash_equals($expected, strtolower($actual))) {
            @unlink($tmp);
            error_log('[Bynli Connect] update REFUSED: package checksum mismatch — expected '
                . $expected . ', got ' . $actual);
            return new WP_Error(
                'bynli_connect_checksum_mismatch',
                __('The downloaded update did not match the checksum published for it, so it was not installed. This can mean the download was corrupted or tampered with. Try again, and contact Bynefit support if it keeps happening.', 'bynli-connect')
            );
        }

        // Verified. Hand WordPress the file we already have rather than making it
        // fetch a second copy — which would also mean unpacking bytes we never hashed.
        return $tmp;
    }

    public function rename_source($source, $remote_source, $upgrader, $hook_extra) {
        global $wp_filesystem;

        if (!is_object($upgrader) || !isset($upgrader->skin)) return $source;
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $source;
        }
        if (!$wp_filesystem) return $source;

        $source_norm = untrailingslashit((string)$source);
        $remote_norm = untrailingslashit((string)$remote_source);

        if (basename($source_norm) === self::PLUGIN_SLUG) {
            return $source;
        }

        $desired = $remote_norm . '/' . self::PLUGIN_SLUG;

        if ($source_norm === $desired) {
            return $source;
        }

        if ($wp_filesystem->exists($desired)) {
            $wp_filesystem->delete($desired, true);
        }

        if ($wp_filesystem->move($source_norm, $desired, true)) {
            return trailingslashit($desired);
        }

        return $source;
    }

    public function clear_cache($upgrader, $hook_extra) {
        if (empty($hook_extra['action']) || $hook_extra['action'] !== 'update') return;
        if (empty($hook_extra['type'])   || $hook_extra['type']   !== 'plugin') return;
        delete_transient(self::TRANSIENT_KEY);
    }

    public function handle_clear_cache(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden.', 403);
        check_admin_referer('bynli_connect_clear_update_cache');
        delete_transient(self::TRANSIENT_KEY);
        delete_site_transient('update_plugins');
        // Re-read before redirecting. Clearing alone left the panel with no readout,
        // and its no-readout branch printed a green verdict — so the button whose hint
        // promises a fresh version was the fastest way to replace a real one with an
        // unearned reassurance. A failed fetch is fine: it caches the error and the
        // panel says so.
        $this->get_remote_manifest();
        wp_safe_redirect(add_query_arg([
            'page'    => Bynli_Connect_Settings::MENU_SLUG,
            'cleared' => 'updates',
        ], admin_url('options-general.php')));
        exit;
    }

    private function build_update_entry(array $remote): stdClass {
        $entry = new stdClass();
        $entry->id            = 'bynli-connect/' . $this->plugin_basename;
        $entry->slug          = self::PLUGIN_SLUG;
        $entry->plugin        = $this->plugin_basename;
        $entry->new_version   = (string)$remote['version'];
        $entry->url           = 'https://bynefit.com/help/wordpress';
        $entry->package       = (string)($remote['download_url'] ?? '');
        $entry->tested        = $remote['tested']       ?? '6.6';
        $entry->requires_php  = $remote['requires_php'] ?? '7.4';
        $entry->requires      = $remote['requires']     ?? '6.1';
        // WordPress renders this inline under the plugin row for ANY plugin that sets it —
        // it is not a wordpress.org-only field. This release deliberately starts refusing
        // designs that publish today, and without this the warning is reachable only by
        // clicking through to View version details, which a bulk update never does.
        // Kept because WordPress carries it through the update object, but it is NOT what
        // renders the notice for a plugin — wp_theme_update_row() reads this field;
        // wp_plugin_update_row() fires in_plugin_update_message-{file} instead, and the
        // .org lightbox reads sections['upgrade_notice']. Both of those are wired below,
        // because a warning that renders nowhere is the same as no warning.
        $entry->upgrade_notice = (string) ($remote['upgrade_notice'] ?? '');
        $entry->icons         = $remote['icons']        ?? [];
        $entry->banners       = $remote['banners']      ?? [];
        $entry->compatibility = new stdClass();
        return $entry;
    }

    /**
     * Lowercased host of a URL, or '' when it has none.
     *
     * Host comparison was written inline and case-SENSITIVE. sanitize_api_base() rebuilds
     * the URL from the host as typed and never lowercases it, so an api_base saved as
     * https://Staging.Bynefit.com compared unequal to its own package URLs — which
     * silently reinstated the very defect the comparison was added to fix, on that install
     * only, with nothing failing and nothing logged.
     */
    private static function url_host(string $url): string
    {
        $host = wp_parse_url($url, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }

    /** True once this request has actually gone to the network for the manifest. */
    private $fetched_this_request = false;

    /**
     * @param bool $bypass_error_cache Re-fetch when the cached answer is an error with no
     *   version. The cache exists to stop the SETTINGS PAGE making two blocking fetches
     *   per refresh; letting it govern checksum verification means one blip widens into
     *   an hour in which every install skips the check. The UI can wait an hour for a
     *   version number. The control cannot.
     */
    private function get_remote_manifest(bool $bypass_error_cache = false): ?array {
        $cached = get_transient(self::TRANSIENT_KEY);
        if ($bypass_error_cache && is_array($cached)
            && empty($cached['version']) && !empty($cached['error'])) {
            $cached = false;
        }
        // A cached ERROR counts as an answer. Short-circuiting only on a version meant
        // a failed check was re-attempted on the very next read, so one press of Refresh
        // against a dead endpoint cost two blocking 8s fetches — the handler's, then the
        // redirected page's. The transient is what bounds that, and it only bounds it if
        // the failure is allowed to occupy it.
        if (is_array($cached) && (!empty($cached['version']) || !empty($cached['error']))) {
            return $cached;
        }

        $url = trailingslashit(Bynli_Connect_Settings::api_base()) . ltrim(self::VERSION_ENDPOINT, '/');
        $url = add_query_arg([
            'installed' => BYNLI_CONNECT_VERSION,
            'slug'      => Bynli_Connect_Settings::site_slug(),
            'site'      => wp_parse_url(home_url(), PHP_URL_HOST),
        ], $url);

        // Recorded so verify_download() can tell a STALE cached error from one this
        // request just wrote. The bypass exists to defeat the former; re-fetching after
        // the latter is two 8-second stalls inside download_package for one answer.
        $this->fetched_this_request = true;
        $res = wp_remote_get($url, [
            'timeout'    => 8,
            'user-agent' => 'Bynli-Connect/' . BYNLI_CONNECT_VERSION . ' WP/' . get_bloginfo('version'),
            'headers'    => ['Accept' => 'application/json'],
        ]);
        // Each failure path RETURNS the entry it just cached rather than null. The
        // asymmetry — array on success, null on failure — has now produced the same
        // defect twice: a caller reads the reason off the return value, the failure
        // shape is not an array, and the log that exists to record the failure carries
        // no reason. Both existing callers test empty($remote['version']), which absorbs
        // this with no behaviour change.
        if (is_wp_error($res)) {
            $entry = ['version' => '', 'error' => $res->get_error_message()];
            set_transient(self::TRANSIENT_KEY, $entry, HOUR_IN_SECONDS);
            return $entry;
        }
        $code = (int)wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            $entry = ['version' => '', 'error' => "HTTP $code"];
            set_transient(self::TRANSIENT_KEY, $entry, HOUR_IN_SECONDS);
            return $entry;
        }
        $body = (string)wp_remote_retrieve_body($res);
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['version']) || empty($data['download_url'])) {
            $entry = ['version' => '', 'error' => 'bad manifest'];
            set_transient(self::TRANSIENT_KEY, $entry, HOUR_IN_SECONDS);
            return $entry;
        }

        set_transient(self::TRANSIENT_KEY, $data, self::TRANSIENT_TTL);
        return $data;
    }

    public static function last_check_meta(): array {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (!is_array($cached)) return ['has' => false];
        return [
            'has'          => true,
            'version'      => (string)($cached['version']      ?? ''),
            'download_url' => (string)($cached['download_url'] ?? ''),
            'error'        => (string)($cached['error']        ?? ''),
            'last_updated' => (string)($cached['last_updated'] ?? ''),
            'changelog'    => (string)($cached['changelog']    ?? ''),
            'description'  => (string)($cached['description']  ?? ''),
        ];
    }
}
