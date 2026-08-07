<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Scene-graph -> WordPress block markup for the bynli/v1 upsert_page verb.
 *
 * The layout substrate (grid + media) becomes registered wp:bynefit/* blocks so
 * it survives REST KSES sanitization; static text stays core blocks so the
 * owner can still edit natively in wp-admin and removing the plugin degrades to
 * placeholders, never corruption. Token references resolve to the theme's
 * preset slugs here — this class is the one place that mapping lives, so the
 * publish-contract validator resolves through the same methods.
 */
class Bynli_Connect_Emitter {

    /**
     * Resolve a scene-graph token reference (e.g. "color.brand", "space.6") to
     * the theme preset slug the block layer expects. Returns null for a
     * non-reference or an out-of-range value. Structural only — whether the slug
     * is actually defined by the live theme is the validator's check.
     */
    public static function resolve_token(string $group, $ref): ?string {
        if (!is_string($ref) || $ref === '' || strpos($ref, '.') === false) {
            return null;
        }
        [$ns, $name] = explode('.', $ref, 2);
        if ($name === '' || !preg_match('/^[A-Za-z0-9\-]+$/', $name)) {
            return null;
        }
        switch ($group) {
            case 'color':
                return $ns === 'color' ? $name : null;
            case 'type':
                return $ns === 'type' ? $name : null;
            case 'radius':
                return $ns === 'radius' ? $name : null;
            case 'shadow':
                return $ns === 'shadow' ? $name : null;
            case 'space':
                if ($ns !== 'space' && $ns !== 'spacing') {
                    return null;
                }
                // Scene graph carries the spacing-scale index (1-10); the theme
                // preset slugs run 10-100 in step with that index.
                if (!ctype_digit($name)) {
                    return null;
                }
                $i = (int) $name;
                return ($i >= 1 && $i <= 10) ? (string) ($i * 10) : null;
            default:
                return null;
        }
    }

    /** @param array $media Resolved media descriptors keyed by media id. */
    public static function emit_page(array $page, array $media): string {
        $sections = is_array($page['sections'] ?? null) ? $page['sections'] : [];
        $out = '';
        foreach ($sections as $section) {
            if (is_array($section)) {
                $out .= self::emit_section($section, $media);
            }
        }
        return $out;
    }

    private static function emit_section(array $section, array $media): string {
        $grid = is_array($section['grid'] ?? null) ? $section['grid'] : [];
        $cols = is_array($grid['cols'] ?? null) ? $grid['cols'] : [];

        $attrs = [
            'cols' => [
                'sm' => Bynli_Connect_Blocks::grid_int($cols['sm'] ?? null, 1, 12, 4),
                'lg' => Bynli_Connect_Blocks::grid_int($cols['lg'] ?? null, 1, 12, 12),
            ],
        ];
        $gap = self::resolve_token('space', $grid['gap'] ?? null);
        if ($gap !== null) {
            $attrs['gap'] = $gap;
        }
        $padding = is_array($section['padding'] ?? null) ? $section['padding'] : [];
        $pad = [];
        foreach (['sm', 'lg'] as $bp) {
            $slug = self::resolve_token('space', $padding[$bp] ?? null);
            if ($slug !== null) {
                $pad[$bp] = $slug;
            }
        }
        if ($pad) {
            $attrs['padding'] = $pad;
        }
        $bg = self::resolve_token('color', self::deep($section, ['background', 'token']));
        if ($bg !== null) {
            $attrs['bg'] = $bg;
        }

        $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
        $inner = '';
        $places = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $markup = self::emit_block($block, $media);
            if ($markup === null) {
                continue;
            }
            $inner .= $markup;
            $places[] = is_array($block['place'] ?? null) ? $block['place'] : new stdClass();
        }

        if ($inner === '') {
            return '';
        }
        $attrs['places'] = $places;

        return self::wrap('bynefit/section', $attrs, $inner);
    }

    private static function emit_block(array $block, array $media): ?string {
        switch ((string) ($block['type'] ?? '')) {
            case 'heading':
                return self::emit_heading($block);
            case 'text':
                return self::emit_text($block);
            case 'image':
                return self::emit_image($block, $media);
            case 'button':
                return self::emit_button($block);
            case 'spacer':
                return self::emit_spacer($block);
            case 'divider':
                return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
            default:
                return null;
        }
    }

    private static function emit_heading(array $block): string {
        $level = (int) ($block['level'] ?? 2);
        if ($level < 1 || $level > 6) {
            $level = 2;
        }
        $attrs = ['level' => $level];
        $classes = ['wp-block-heading'];

        $color = self::resolve_token('color', self::deep($block, ['style', 'color']));
        if ($color !== null) {
            $attrs['textColor'] = $color;
            $classes[] = 'has-text-color';
            $classes[] = "has-$color-color";
        }

        $text = esc_html((string) ($block['text'] ?? ''));
        $class_attr = ' class="' . implode(' ', $classes) . '"';
        $tag = 'h' . $level;

        return sprintf(
            "<!-- wp:heading %s -->\n<%s%s>%s</%s>\n<!-- /wp:heading -->",
            wp_json_encode($attrs),
            $tag,
            $class_attr,
            $text,
            $tag
        );
    }

    private static function emit_text(array $block): string {
        $attrs = [];
        $classes = [];

        $color = self::resolve_token('color', self::deep($block, ['style', 'color']));
        if ($color !== null) {
            $attrs['textColor'] = $color;
            $classes[] = 'has-text-color';
            $classes[] = "has-$color-color";
        }
        $size = self::resolve_token('type', self::deep($block, ['style', 'typography']));
        if ($size !== null) {
            $attrs['fontSize'] = $size;
            $classes[] = "has-$size-font-size";
        }

        $text = esc_html((string) ($block['text'] ?? ''));
        $class_attr = $classes ? ' class="' . implode(' ', $classes) . '"' : '';
        $json = $attrs ? ' ' . wp_json_encode($attrs) : '';

        return sprintf("<!-- wp:paragraph%s -->\n<p%s>%s</p>\n<!-- /wp:paragraph -->", $json, $class_attr, $text);
    }

    private static function emit_image(array $block, array $media): ?string {
        $mid = (string) ($block['media'] ?? '');
        $desc = $mid !== '' && isset($media[$mid]) && is_array($media[$mid]) ? $media[$mid] : null;
        if ($desc === null || trim((string) ($desc['url'] ?? '')) === '') {
            return null;
        }

        $attrs = [
            'kind' => ($block['kind'] ?? $desc['kind'] ?? 'image') === 'video' ? 'video' : 'image',
            'url'  => (string) $desc['url'],
            'alt'  => (string) ($block['alt'] ?? ($desc['alt'] ?? '')),
        ];
        $w = (int) ($desc['width'] ?? 0);
        $h = (int) ($desc['height'] ?? 0);
        if ($w > 0) {
            $attrs['width'] = $w;
        }
        if ($h > 0) {
            $attrs['height'] = $h;
        }

        $focal = is_array($block['focal'] ?? null) ? $block['focal'] : (is_array($desc['focal'] ?? null) ? $desc['focal'] : null);
        if ($focal !== null) {
            $fx = isset($focal['x']) && is_numeric($focal['x']) ? min(1.0, max(0.0, (float) $focal['x'])) : 0.5;
            $fy = isset($focal['y']) && is_numeric($focal['y']) ? min(1.0, max(0.0, (float) $focal['y'])) : 0.5;
            $attrs['focal'] = ['x' => $fx, 'y' => $fy];
        }

        $sources = is_array($desc['sources'] ?? null) ? array_intersect_key($desc['sources'], ['avif' => 1, 'webp' => 1]) : [];
        if ($sources) {
            $attrs['sources'] = $sources;
        }
        if ($attrs['kind'] === 'video' && !empty($desc['poster'])) {
            $attrs['poster'] = (string) $desc['poster'];
        }
        if (!empty($block['priority'])) {
            $attrs['priority'] = true;
        }
        $radius = self::resolve_token('radius', self::deep($block, ['style', 'radius']));
        if ($radius !== null) {
            $attrs['radius'] = $radius;
        }

        return self::wrap('bynefit/media', $attrs, null);
    }

    private static function emit_button(array $block): ?string {
        $label = esc_html((string) ($block['text'] ?? ''));
        $href = (string) ($block['href'] ?? '');
        if ($label === '' || $href === '') {
            return null;
        }
        $url = esc_url($href);

        return "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n"
            . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . $url . '">' . $label . '</a></div>'
            . "\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->";
    }

    private static function emit_spacer(array $block): string {
        $slug = self::resolve_token('space', $block['size'] ?? null) ?? '50';
        $attrs = ['height' => 'var:preset|spacing|' . $slug];
        $var = 'var(--wp--preset--spacing--' . $slug . ')';

        return sprintf(
            "<!-- wp:spacer %s -->\n<div style=\"height:%s\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->",
            wp_json_encode($attrs),
            esc_attr($var)
        );
    }

    /** Serialize a dynamic (save:null) block: void form when there is no inner content. */
    private static function wrap(string $name, array $attrs, ?string $inner): string {
        $json = $attrs ? ' ' . wp_json_encode($attrs) : '';
        if ($inner === null) {
            return "<!-- wp:$name$json /-->";
        }
        return "<!-- wp:$name$json -->" . $inner . "<!-- /wp:$name -->";
    }

    private static function deep(array $node, array $keys) {
        $cur = $node;
        foreach ($keys as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) {
                return null;
            }
            $cur = $cur[$k];
        }
        return $cur;
    }
}
