<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the wp:bynefit/* dynamic-block set (the Bynefit Sites emitter
 * substrate). All blocks are server-rendered (save:null) so a WP/plugin
 * version bump can never throw the "invalid content" recovery error that
 * corrupts a customer page — there is no saved markup to validate.
 *
 * The scene-graph emitter (bynli/v1 upsert_page) writes these blocks; here we
 * only register + render them. Front-end CSS is one small stylesheet
 * (assets/blocks.css) that consumes per-instance CSS custom properties, so
 * there is no per-block inline rule and nothing render-blocking.
 */
class Bynli_Connect_Blocks {

    const VERSION = '0.12.0';

    public function __construct() {
        add_action('init', [$this, 'register_blocks']);
    }

    public function register_blocks(): void {
        // Register the shared front-end stylesheet first: each block.json
        // references it by handle (`style`), and WP enqueues it on render only
        // if the handle already exists.
        wp_register_style(
            'bynefit-blocks',
            plugins_url('assets/blocks.css', BYNLI_CONNECT_PLUGIN_FILE),
            [],
            self::VERSION
        );

        $dir = BYNLI_CONNECT_PLUGIN_DIR . 'blocks';
        foreach (['section', 'media', 'gallery', 'quote', 'stat', 'accordion', 'embed'] as $name) {
            $path = "$dir/$name";
            if (is_file("$path/block.json")) {
                register_block_type($path);
            }
        }
    }

    /**
     * Resolve a design token reference to a CSS value.
     * Accepts a preset slug (safe charset only) and maps it to the theme's
     * generated custom property; rejects anything else so block attributes
     * can never inject arbitrary CSS.
     */
    public static function token(string $group, ?string $slug): ?string {
        if ($slug === null || $slug === '' || !preg_match('/^[a-z0-9\-]{1,40}$/', $slug)) {
            return null;
        }
        $map = [
            'color'   => '--wp--preset--color--',
            'spacing' => '--wp--preset--spacing--',
            'shadow'  => '--wp--preset--shadow--',
            'radius'  => '--wp--custom--radius--',
        ];
        if (!isset($map[$group])) {
            return null;
        }
        return 'var(' . $map[$group] . $slug . ')';
    }

    /** Clamp a numeric grid coordinate to a sane bounded integer. */
    public static function grid_int($value, int $min, int $max, int $default): int {
        if (!is_numeric($value)) {
            return $default;
        }
        $n = (int) $value;
        if ($n < $min) {
            return $min;
        }
        if ($n > $max) {
            return $max;
        }
        return $n;
    }

    /**
     * Build the CSS-custom-property style string for one section cell from its
     * place map. Only the phone (sm) breakpoint is required; lg falls back to
     * sm in CSS. Kept on the class so render.php can't redeclare it when a page
     * holds more than one section.
     */
    public static function cell_vars(array $place): string {
        $out = [];
        foreach (['sm', 'lg'] as $bp) {
            $p = isset($place[$bp]) && is_array($place[$bp]) ? $place[$bp] : null;
            if ($p === null) {
                continue;
            }
            $out["--bynefit-col-$bp"]     = (string) self::grid_int($p['col'] ?? null, 1, 12, 1);
            $out["--bynefit-colspan-$bp"] = (string) self::grid_int($p['colSpan'] ?? null, 1, 12, ($bp === 'sm' ? 4 : 12));
            $out["--bynefit-row-$bp"]     = (string) self::grid_int($p['row'] ?? null, 1, 999, 1);
            $out["--bynefit-rowspan-$bp"] = (string) self::grid_int($p['rowSpan'] ?? null, 1, 999, 1);
            if (isset($p['order']) && is_numeric($p['order'])) {
                $out["--bynefit-order-$bp"] = (string) self::grid_int($p['order'], 0, 999, 0);
            }
        }
        $s = '';
        foreach ($out as $prop => $val) {
            $s .= $prop . ':' . $val . ';';
        }
        return $s;
    }
}
