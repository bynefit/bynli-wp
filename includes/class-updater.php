<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Updater {
    const TRANSIENT_KEY    = 'bynli_connect_update_check';
    const TRANSIENT_TTL    = 12 * HOUR_IN_SECONDS;
    const VERSION_ENDPOINT = '/api/site-host/version';
    const PLUGIN_SLUG      = 'bynli-connect';

    private $plugin_basename;

    public function __construct() {
        $this->plugin_basename = plugin_basename(BYNLI_CONNECT_PLUGIN_FILE);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api',                           [$this, 'plugins_api'], 10, 3);
        add_filter('upgrader_source_selection',             [$this, 'rename_source'], 10, 4);
        add_action('upgrader_process_complete',             [$this, 'clear_cache'], 10, 2);
        add_action('admin_post_bynli_connect_clear_update_cache', [$this, 'handle_clear_cache']);
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

        if (version_compare($remote['version'], BYNLI_CONNECT_VERSION, '>')) {
            $transient->response[$this->plugin_basename] = $entry;
            unset($transient->no_update[$this->plugin_basename]);
        } else {
            $transient->no_update[$this->plugin_basename] = $entry;
            unset($transient->response[$this->plugin_basename]);
        }

        return $transient;
    }

    public function plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information') return $result;
        if (empty($args->slug) || $args->slug !== self::PLUGIN_SLUG) return $result;

        $remote = $this->get_remote_manifest();
        if (!$remote || empty($remote['version'])) return $result;

        $info = new stdClass();
        $info->name          = 'Bynli Connect';
        $info->slug          = self::PLUGIN_SLUG;
        $info->version       = (string)$remote['version'];
        $info->author        = '<a href="https://bynefit.org">Bynefit</a>';
        $info->homepage      = 'https://bynli.com/guides/wordpress';
        $info->requires      = $remote['requires']      ?? '6.0';
        $info->tested        = $remote['tested']        ?? '6.6';
        $info->requires_php  = $remote['requires_php']  ?? '7.4';
        $info->last_updated  = $remote['last_updated']  ?? '';
        $info->download_link = (string)($remote['download_url'] ?? '');
        $info->trunk         = $info->download_link;
        $info->sections = [
            'description' => $remote['description'] ?? 'Connect a WordPress site to Bynli — daily usage reporting and Bynli shortcodes.',
            'changelog'   => $remote['changelog']   ?? '',
        ];
        if (!empty($remote['banners']) && is_array($remote['banners'])) {
            $info->banners = $remote['banners'];
        }
        return $info;
    }

    public function rename_source($source, $remote_source, $upgrader, $hook_extra) {
        global $wp_filesystem;

        if (!is_object($upgrader) || !isset($upgrader->skin)) return $source;
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $source;
        }

        $desired = trailingslashit($remote_source) . self::PLUGIN_SLUG;
        if ($source === $desired) return $source;

        if ($wp_filesystem && $wp_filesystem->move($source, $desired, true)) {
            return $desired;
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
        $entry->url           = 'https://bynli.com/guides/wordpress';
        $entry->package       = (string)($remote['download_url'] ?? '');
        $entry->tested        = $remote['tested']       ?? '6.6';
        $entry->requires_php  = $remote['requires_php'] ?? '7.4';
        $entry->requires      = $remote['requires']     ?? '6.0';
        $entry->icons         = $remote['icons']        ?? [];
        $entry->banners       = $remote['banners']      ?? [];
        $entry->compatibility = new stdClass();
        return $entry;
    }

    private function get_remote_manifest(): ?array {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached) && !empty($cached['version'])) {
            return $cached;
        }

        $url = trailingslashit(Bynli_Connect_Settings::api_base()) . ltrim(self::VERSION_ENDPOINT, '/');
        $url = add_query_arg([
            'installed' => BYNLI_CONNECT_VERSION,
            'slug'      => Bynli_Connect_Settings::site_slug(),
            'site'      => wp_parse_url(home_url(), PHP_URL_HOST),
        ], $url);

        $res = wp_remote_get($url, [
            'timeout'    => 8,
            'user-agent' => 'BynliConnect/' . BYNLI_CONNECT_VERSION . ' (WP ' . get_bloginfo('version') . ')',
            'headers'    => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($res)) {
            set_transient(self::TRANSIENT_KEY, ['version' => '', 'error' => $res->get_error_message()], HOUR_IN_SECONDS);
            return null;
        }
        $code = (int)wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            set_transient(self::TRANSIENT_KEY, ['version' => '', 'error' => "HTTP $code"], HOUR_IN_SECONDS);
            return null;
        }
        $body = (string)wp_remote_retrieve_body($res);
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['version']) || empty($data['download_url'])) {
            set_transient(self::TRANSIENT_KEY, ['version' => '', 'error' => 'bad manifest'], HOUR_IN_SECONDS);
            return null;
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
        ];
    }
}
