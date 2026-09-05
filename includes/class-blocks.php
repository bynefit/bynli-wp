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
            BYNLI_CONNECT_VERSION
        );

        wp_register_script(
            'bynefit-blocks',
            plugins_url('assets/blocks.js', BYNLI_CONNECT_PLUGIN_FILE),
            [],
            BYNLI_CONNECT_VERSION,
            true
        );

        $dir = BYNLI_CONNECT_PLUGIN_DIR . 'blocks';
        foreach (['section', 'media', 'gallery', 'quote', 'stat', 'accordion', 'embed', 'icon', 'list', 'cta', 'callout', 'card', 'logos', 'form', 'events', 'tabs', 'carousel'] as $name) {
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
    public static function token(string $group, ?string $slug, ?string $fallback = null): ?string {
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
        $tail = ($fallback !== null && preg_match('/^[a-zA-Z0-9(),#%.\- ]{1,120}$/', $fallback))
            ? ', ' . $fallback
            : '';
        return 'var(' . $map[$group] . $slug . $tail . ')';
    }

    /**
     * Grid bounds, declared once. The publish gate reads these rather than restating
     * them: the gate exists to refuse precisely the values this layer would otherwise
     * clamp in silence, so two numbers that agree by convention is the one arrangement
     * that cannot hold — changing a literal here is a one-token edit that nothing
     * detects, and its effect is to re-admit the clamp the gate was added to prevent.
     */
    const GRID_COLS_MIN    = 1;
    const GRID_COLS_MAX    = 12;
    const GALLERY_COLS_MAX = 6;
    const PLACE_MIN        = 1;
    const PLACE_MAX        = 999;
    const ORDER_MIN        = 0;
    const ORDER_MAX        = 999;

    /**
     * The track count a section renders on when it declares none. Named for the same
     * reason as the bounds above: the publish gate has to resolve a section's tracks
     * exactly as the renderer does, and a default that drifts between them is a false
     * rejection in one direction and a silent clamp in the other.
     */
    const GRID_COLS_SM_DEFAULT = 4;
    const GRID_COLS_LG_DEFAULT = 12;
    const GALLERY_COLS_SM_DEFAULT = 2;
    const GALLERY_COLS_LG_DEFAULT = 3;

    /** The bounds icon_svg() clamps to. Named for the same reason as the grid bounds. */
    const ICON_SIZE_MIN = 8;
    const ICON_SIZE_MAX = 96;

    /**
     * Does this value reach grid_int() as the integer it is written as?
     *
     * ONE rule, and it is derived from grid_int() rather than restated alongside it:
     * that function accepts anything is_numeric() accepts and then casts with (int).
     * So the question this predicate answers is exactly "does the (int) cast lose
     * anything" — and the way to answer it is to perform the cast and compare, not to
     * enumerate the shapes that survive it.
     *
     * Enumerating is what went wrong twice. A hand-written character class disagreed
     * with the renderer by PHP version, because trailing whitespace is numeric from 8.0
     * and not on 7.4, which this plugin still declares as its floor. Then a type-by-type
     * test accepted the float 24.0 and refused the string '24.0', though grid_int()
     * reproduces both as 24 exactly. Deferring to is_numeric() and comparing the cast
     * cannot disagree with the renderer on any version or any type.
     *
     * What is still refused is what the renderer would genuinely REWRITE: 24.5 and '2.9'
     * truncate, so publishing them lays out a page the author did not describe. That is
     * the whole purpose of the gate, and the only thing it should refuse.
     */
    public static function isIntLike($value): bool
    {
        if (is_int($value)) { return true; }
        if (!is_numeric($value)) { return false; }
        $f = (float) $value;
        // NAN and INF are numeric and survive nothing; and a magnitude past the integer
        // range casts to a wrapped value, so the comparison below would be comparing
        // against nonsense rather than rejecting it.
        if (!is_finite($f) || $f < (float) PHP_INT_MIN || $f > (float) PHP_INT_MAX) {
            return false;
        }
        return $f === (float) (int) $value;
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
     *
     * $cols carries the section's real per-breakpoint track counts: col and colSpan
     * are clamped against them so a span wider than the section cannot overflow into
     * implicit tracks. The section renderer is the only caller and always passes real
     * counts, so the defaults are a guard rather than a compatibility shim — they are
     * the same per-breakpoint defaults the renderer and the publish gate resolve to,
     * because a fourth answer to "how many tracks" is the drift the constants exist
     * to end.
     */
    public static function cell_vars(
        array $place,
        array $cols = [
            'sm' => self::GRID_COLS_SM_DEFAULT,
            'lg' => self::GRID_COLS_LG_DEFAULT,
        ]
    ): string {
        $out = [];
        foreach (['sm', 'lg'] as $bp) {
            $p = isset($place[$bp]) && is_array($place[$bp]) ? $place[$bp] : null;
            if ($p === null) {
                continue;
            }
            $track_default = $bp === 'sm' ? self::GRID_COLS_SM_DEFAULT : self::GRID_COLS_LG_DEFAULT;
            $track_max = self::grid_int($cols[$bp] ?? null, self::GRID_COLS_MIN, self::GRID_COLS_MAX, $track_default);
            $col       = self::grid_int($p['col'] ?? null, self::GRID_COLS_MIN, $track_max, 1);
            $span_max  = max(1, $track_max - $col + 1);
            $out["--bynefit-col-$bp"]     = (string) $col;
            $colspan_default = $bp === 'sm' ? self::GRID_COLS_SM_DEFAULT : self::GRID_COLS_LG_DEFAULT;
            $out["--bynefit-colspan-$bp"] = (string) self::grid_int($p['colSpan'] ?? null, self::GRID_COLS_MIN, $span_max, min($colspan_default, $span_max));
            $out["--bynefit-row-$bp"]     = (string) self::grid_int($p['row'] ?? null, self::PLACE_MIN, self::PLACE_MAX, 1);
            $out["--bynefit-rowspan-$bp"] = (string) self::grid_int($p['rowSpan'] ?? null, self::PLACE_MIN, self::PLACE_MAX, 1);
            if (isset($p['order']) && is_numeric($p['order'])) {
                $out["--bynefit-order-$bp"] = (string) self::grid_int($p['order'], self::ORDER_MIN, self::ORDER_MAX, 0);
            }
        }
        $s = '';
        foreach ($out as $prop => $val) {
            $s .= $prop . ':' . $val . ';';
        }
        return $s;
    }

    /**
     * Inner path markup for a curated, stroke-based icon set. The set is a
     * fixed server-side allow-list — a caller can only ever pick a name, never
     * supply SVG — so no untrusted markup reaches the page. currentColor lets a
     * token colour on the wrapper drive the stroke.
     */
    private static function icon_paths(): array {
        return [
            'arrow-right'    => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
            'arrow-up-right' => '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
            'chevron-right'  => '<path d="m9 18 6-6-6-6"/>',
            'check'          => '<path d="M20 6 9 17l-5-5"/>',
            'check-circle'   => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
            'star'           => '<path d="M12 2 15.09 8.26 22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14l-5-4.87 6.91-1.01L12 2z"/>',
            'heart'          => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.51 4.04 3 5.5l7 7Z"/>',
            'mail'           => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
            'phone'          => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
            'map-pin'        => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
            'calendar'       => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/>',
            'clock'          => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'users'          => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'shield'         => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
            'sparkles'       => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3z"/>',
            'zap'            => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
            'globe'          => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
            'play'           => '<path d="m6 3 14 9-14 9V3z"/>',
            'info'           => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
            'alert-triangle' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
            'lightbulb'      => '<path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1V17h6v-.2c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2z"/>',
        ];
    }

    /** Whitelisted icon names (for validation). */
    public static function icon_names(): array {
        return array_keys(self::icon_paths());
    }

    /** A curated stroke icon as an inline <svg>, or null for an unknown name. */
    public static function icon_svg(string $name, int $size = 24, string $label = ''): ?string {
        $paths = self::icon_paths();
        if (!isset($paths[$name])) {
            return null;
        }
        $size = max(self::ICON_SIZE_MIN, min(self::ICON_SIZE_MAX, $size));
        $a11y = $label !== ''
            ? 'role="img" aria-label="' . esc_attr($label) . '"'
            : 'aria-hidden="true" focusable="false"';
        return '<svg class="bynefit-icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24"'
            . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ' . $a11y . '>'
            . $paths[$name] . '</svg>';
    }
}
