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
            case 'gallery':
                return self::emit_gallery($block, $media);
            case 'quote':
                return self::emit_quote($block, $media);
            case 'stat':
                return self::emit_stat($block);
            case 'accordion':
                return self::emit_accordion($block);
            case 'embed':
                return self::emit_embed($block);
            case 'icon':
                return self::emit_icon($block);
            case 'list':
                return self::emit_list($block);
            case 'cta':
                return self::emit_cta($block);
            case 'callout':
                return self::emit_callout($block);
            case 'card':
                return self::emit_card($block, $media);
            case 'logos':
                return self::emit_logos($block, $media);
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
            serialize_block_attributes($attrs),
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
        $json = $attrs ? ' ' . serialize_block_attributes($attrs) : '';

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
            'url'  => esc_url_raw((string) $desc['url']),
            'alt'  => (string) ($block['alt'] ?? ($desc['alt'] ?? '')),
        ];
        if ($attrs['url'] === '') {
            return null;
        }
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
            $attrs['poster'] = esc_url_raw((string) $desc['poster']);
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

        return "<!-- wp:buttons -->\n<div class=\"wp-block-buttons is-layout-flex wp-block-buttons-is-layout-flex\"><!-- wp:button -->\n"
            . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . $url . '">' . $label . '</a></div>'
            . "\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->";
    }

    private static function emit_spacer(array $block): string {
        $slug = self::resolve_token('space', $block['size'] ?? null) ?? '50';
        $attrs = ['height' => 'var:preset|spacing|' . $slug];
        $var = 'var(--wp--preset--spacing--' . $slug . ')';

        return sprintf(
            "<!-- wp:spacer %s -->\n<div style=\"height:%s\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->",
            serialize_block_attributes($attrs),
            esc_attr($var)
        );
    }

    private static function emit_gallery(array $block, array $media): ?string {
        $items = [];
        foreach ((is_array($block['items'] ?? null) ? $block['items'] : []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $entry = self::media_entry($it, $media);
            if ($entry !== null) {
                $items[] = $entry;
            }
        }
        if (!$items) {
            return null;
        }

        $cols = is_array($block['columns'] ?? null) ? $block['columns'] : [];
        $attrs = [
            'items'   => $items,
            'columns' => [
                'sm' => Bynli_Connect_Blocks::grid_int($cols['sm'] ?? null, 1, 6, 2),
                'lg' => Bynli_Connect_Blocks::grid_int($cols['lg'] ?? null, 1, 6, 3),
            ],
        ];
        $gap = self::resolve_token('space', $block['gap'] ?? null);
        if ($gap !== null) {
            $attrs['gap'] = $gap;
        }
        $radius = self::resolve_token('radius', self::deep($block, ['style', 'radius']));
        if ($radius !== null) {
            $attrs['radius'] = $radius;
        }

        return self::wrap('bynefit/gallery', $attrs, null);
    }

    private static function emit_quote(array $block, array $media): ?string {
        $text = (string) ($block['text'] ?? '');
        if (trim($text) === '') {
            return null;
        }
        $attrs = ['text' => $text];
        $cite = (string) ($block['cite'] ?? '');
        if ($cite !== '') {
            $attrs['cite'] = $cite;
        }
        $role = (string) ($block['role'] ?? '');
        if ($role !== '') {
            $attrs['role'] = $role;
        }
        $attrs['align'] = ($block['align'] ?? '') === 'center' ? 'center' : 'start';

        if (is_array($block['avatar'] ?? null)) {
            $av = self::media_entry($block['avatar'], $media);
            if ($av !== null) {
                $avatar = ['url' => $av['url']];
                if (isset($av['width'])) {
                    $avatar['width'] = $av['width'];
                }
                if (isset($av['height'])) {
                    $avatar['height'] = $av['height'];
                }
                $attrs['avatar'] = $avatar;
            }
        }

        return self::wrap('bynefit/quote', $attrs, null);
    }

    private static function emit_stat(array $block): ?string {
        $value = (string) ($block['value'] ?? '');
        if (trim($value) === '') {
            return null;
        }
        $attrs = ['value' => $value];
        $label = (string) ($block['label'] ?? '');
        if ($label !== '') {
            $attrs['label'] = $label;
        }
        $caption = (string) ($block['caption'] ?? '');
        if ($caption !== '') {
            $attrs['caption'] = $caption;
        }
        $attrs['align'] = ($block['align'] ?? '') === 'center' ? 'center' : 'start';

        return self::wrap('bynefit/stat', $attrs, null);
    }

    private static function emit_accordion(array $block): ?string {
        $items = [];
        foreach ((is_array($block['items'] ?? null) ? $block['items'] : []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $q = (string) ($it['q'] ?? '');
            $a = (string) ($it['a'] ?? '');
            if (trim($q) === '' || trim($a) === '') {
                continue;
            }
            $items[] = ['q' => $q, 'a' => $a];
        }
        if (!$items) {
            return null;
        }

        return self::wrap('bynefit/accordion', ['items' => $items], null);
    }

    private static function emit_embed(array $block): ?string {
        $provider = (string) ($block['provider'] ?? '');
        if (!in_array($provider, ['youtube', 'vimeo', 'map'], true)) {
            return null;
        }
        $id = (string) ($block['id'] ?? '');
        if (trim($id) === '') {
            return null;
        }
        $attrs = [
            'provider' => $provider,
            'id'       => $id,
            'title'    => (string) ($block['title'] ?? ''),
        ];
        $ratio = (string) ($block['ratio'] ?? '16-9');
        if (in_array($ratio, ['16-9', '4-3', '1-1', '21-9'], true)) {
            $attrs['ratio'] = $ratio;
        }

        return self::wrap('bynefit/embed', $attrs, null);
    }

    private static function emit_icon(array $block): ?string {
        $name = (string) ($block['name'] ?? '');
        if (trim($name) === '') {
            return null;
        }
        $attrs = ['name' => $name];
        if (isset($block['size']) && is_numeric($block['size'])) {
            $attrs['size'] = (int) $block['size'];
        }
        $color = self::resolve_token('color', $block['color'] ?? null);
        if ($color !== null) {
            $attrs['color'] = $color;
        }
        $label = (string) ($block['label'] ?? '');
        if ($label !== '') {
            $attrs['label'] = $label;
        }

        return self::wrap('bynefit/icon', $attrs, null);
    }

    private static function emit_list(array $block): ?string {
        $items = [];
        foreach ((is_array($block['items'] ?? null) ? $block['items'] : []) as $it) {
            if (is_array($it)) {
                $text = (string) ($it['text'] ?? '');
                $icon = (string) ($it['icon'] ?? '');
            } else {
                $text = (string) $it;
                $icon = '';
            }
            if (trim($text) === '') {
                continue;
            }
            $entry = ['text' => $text];
            if ($icon !== '') {
                $entry['icon'] = $icon;
            }
            $items[] = $entry;
            if (count($items) >= Bynli_Connect_Publish_Contract::MAX_LIST_ITEMS) {
                break;
            }
        }
        if (!$items) {
            return null;
        }

        $attrs = ['items' => $items];
        $marker = (string) ($block['marker'] ?? '');
        if (in_array($marker, ['check', 'arrow', 'dot', 'none'], true)) {
            $attrs['marker'] = $marker;
        }
        $color = self::resolve_token('color', $block['color'] ?? null);
        if ($color !== null) {
            $attrs['color'] = $color;
        }

        return self::wrap('bynefit/list', $attrs, null);
    }

    private static function emit_cta(array $block): ?string {
        $buttons = [];
        foreach ((is_array($block['buttons'] ?? null) ? $block['buttons'] : []) as $btn) {
            if (!is_array($btn) || count($buttons) >= 2) {
                continue;
            }
            $label = (string) ($btn['label'] ?? '');
            $href  = (string) ($btn['href'] ?? '');
            if (trim($label) === '' || trim($href) === '') {
                continue;
            }
            $entry = ['label' => $label, 'href' => $href];
            if (($btn['variant'] ?? '') === 'secondary') {
                $entry['variant'] = 'secondary';
            }
            $buttons[] = $entry;
        }

        $title = (string) ($block['title'] ?? '');
        if (trim($title) === '' && !$buttons) {
            return null;
        }

        $attrs = ['title' => $title];
        $text = (string) ($block['text'] ?? '');
        if ($text !== '') {
            $attrs['text'] = $text;
        }
        $attrs['align'] = ($block['align'] ?? '') === 'center' ? 'center' : 'start';
        if ($buttons) {
            $attrs['buttons'] = $buttons;
        }
        $bg = self::resolve_token('color', $block['bg'] ?? null);
        if ($bg !== null) {
            $attrs['bg'] = $bg;
        }

        return self::wrap('bynefit/cta', $attrs, null);
    }

    private static function emit_callout(array $block): ?string {
        $variant = (string) ($block['variant'] ?? 'info');
        if (!in_array($variant, ['info', 'success', 'warn', 'tip'], true)) {
            $variant = 'info';
        }
        $title = (string) ($block['title'] ?? '');
        $text  = (string) ($block['text'] ?? '');
        if (trim($title) === '' && trim($text) === '') {
            return null;
        }
        $attrs = ['variant' => $variant];
        if ($title !== '') {
            $attrs['title'] = $title;
        }
        if ($text !== '') {
            $attrs['text'] = $text;
        }

        return self::wrap('bynefit/callout', $attrs, null);
    }

    private static function emit_card(array $block, array $media): ?string {
        $children = is_array($block['blocks'] ?? null) ? $block['blocks'] : [];
        $inner = '';
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            // A card is a leaf-content surface: containers (section/card) can't
            // nest inside it, so depth stays bounded at section -> card -> leaf.
            if (in_array((string) ($child['type'] ?? ''), ['section', 'card'], true)) {
                continue;
            }
            $markup = self::emit_block($child, $media);
            if ($markup !== null) {
                $inner .= $markup;
            }
        }
        if ($inner === '') {
            return null;
        }

        $attrs = [];
        $bg = self::resolve_token('color', self::deep($block, ['style', 'background']));
        if ($bg !== null) {
            $attrs['bg'] = $bg;
        }
        $radius = self::resolve_token('radius', self::deep($block, ['style', 'radius']));
        if ($radius !== null) {
            $attrs['radius'] = $radius;
        }
        $shadow = self::resolve_token('shadow', self::deep($block, ['style', 'shadow']));
        if ($shadow !== null) {
            $attrs['shadow'] = $shadow;
        }
        $pad = self::resolve_token('space', $block['padding'] ?? null);
        if ($pad !== null) {
            $attrs['padding'] = $pad;
        }

        return self::wrap('bynefit/card', $attrs, $inner);
    }

    private static function emit_logos(array $block, array $media): ?string {
        $items = [];
        foreach ((is_array($block['items'] ?? null) ? $block['items'] : []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $entry = self::media_entry($it, $media);
            if ($entry !== null) {
                $items[] = $entry;
            }
        }
        if (!$items) {
            return null;
        }
        $attrs = ['items' => $items];
        if (array_key_exists('muted', $block)) {
            $attrs['muted'] = (bool) $block['muted'];
        }

        return self::wrap('bynefit/logos', $attrs, null);
    }

    /**
     * Normalize a scene-graph media reference ({media:"<id>", alt?, focal?}) to a
     * resolved descriptor for a block attribute, or null if the id doesn't
     * resolve. url is run through esc_url_raw before it enters the stored doc.
     */
    private static function media_entry(array $ref, array $media): ?array {
        $mid  = (string) ($ref['media'] ?? '');
        $desc = $mid !== '' && isset($media[$mid]) && is_array($media[$mid]) ? $media[$mid] : null;
        if ($desc === null) {
            return null;
        }
        $url = esc_url_raw((string) ($desc['url'] ?? ''));
        if ($url === '') {
            return null;
        }
        $entry = ['url' => $url, 'alt' => (string) ($ref['alt'] ?? ($desc['alt'] ?? ''))];
        if (isset($desc['width']) && is_numeric($desc['width'])) {
            $entry['width'] = (int) $desc['width'];
        }
        if (isset($desc['height']) && is_numeric($desc['height'])) {
            $entry['height'] = (int) $desc['height'];
        }
        $focal = is_array($ref['focal'] ?? null) ? $ref['focal'] : (is_array($desc['focal'] ?? null) ? $desc['focal'] : null);
        if ($focal !== null) {
            $fx = isset($focal['x']) && is_numeric($focal['x']) ? min(1.0, max(0.0, (float) $focal['x'])) : 0.5;
            $fy = isset($focal['y']) && is_numeric($focal['y']) ? min(1.0, max(0.0, (float) $focal['y'])) : 0.5;
            $entry['focal'] = ['x' => $fx, 'y' => $fy];
        }
        $sources = is_array($desc['sources'] ?? null) ? array_intersect_key($desc['sources'], ['avif' => 1, 'webp' => 1]) : [];
        if ($sources) {
            $entry['sources'] = $sources;
        }
        return $entry;
    }

    /** Serialize a dynamic (save:null) block: void form when there is no inner content. */
    private static function wrap(string $name, array $attrs, ?string $inner): string {
        $json = $attrs ? ' ' . serialize_block_attributes($attrs) : '';
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
