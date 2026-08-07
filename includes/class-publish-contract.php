<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Emit-time publish-contract gate for a Bynefit Sites scene-graph page.
 *
 * Runs the static, document-level half of the contract (bynefit-sites#3 §6):
 * things provable from the doc + the resolved token vocabulary before any WP
 * write. Runtime budgets (CWV timings, critical CSS) are measured post-publish
 * and are not this gate's job. A failing page is refused and every violation is
 * returned so the app can surface the exact fix — never a silent partial write.
 */
class Bynli_Connect_Publish_Contract {

    const SUPPORTED_BLOCKS = ['heading', 'text', 'image', 'button', 'spacer', 'divider', 'gallery', 'quote', 'stat', 'accordion', 'embed'];

    const EMBED_PROVIDERS = ['youtube', 'vimeo', 'map'];

    const MAX_SECTIONS       = 200;
    const MAX_BLOCKS_SECTION = 200;

    const CONTRAST_NORMAL = 4.5;
    const CONTRAST_LARGE  = 3.0;

    /**
     * @param array $page  A single scene-graph page node.
     * @param array $media Resolved media descriptors keyed by media id.
     * @return array{ok:bool,violations:array<int,array{code:string,path:string,message:string}>}
     */
    public static function validate(array $page, array $media): array {
        $v = [];

        $slug = isset($page['slug']) ? (string) $page['slug'] : '';
        if ($slug === '') {
            $v[] = self::vio('page_slug_missing', 'slug', 'Page is missing a slug.');
        }
        if (trim((string) ($page['title'] ?? '')) === '') {
            $v[] = self::vio('page_title_missing', 'title', 'Page is missing a title.');
        }

        $seo = is_array($page['seo'] ?? null) ? $page['seo'] : [];
        if (trim((string) ($seo['description'] ?? '')) === '') {
            $v[] = self::vio('seo_description_missing', 'seo.description', 'Page needs an SEO description.');
        }

        $vocab = self::theme_vocab();

        $sections = is_array($page['sections'] ?? null) ? $page['sections'] : [];
        if (count($sections) === 0) {
            $v[] = self::vio('page_empty', 'sections', 'Page has no sections.');
        } elseif (count($sections) > self::MAX_SECTIONS) {
            $v[] = self::vio('page_too_large', 'sections', 'Page has more than ' . self::MAX_SECTIONS . ' sections.');
            return ['ok' => false, 'violations' => $v];
        }

        $heading_levels = [];
        $priority_images = 0;

        foreach ($sections as $si => $section) {
            $spath = "sections[$si]";
            if (!is_array($section) || ($section['type'] ?? '') !== 'section') {
                $v[] = self::vio('section_type', $spath, 'Top-level nodes must be sections.');
                continue;
            }

            $bg_slug = self::check_token_ref(
                $v, "$spath.background.token",
                self::deep($section, ['background', 'token']),
                'color', $vocab, false
            );
            self::check_token_ref($v, "$spath.grid.gap", self::deep($section, ['grid', 'gap']), 'space', $vocab, false);
            foreach (['sm', 'lg'] as $bp) {
                self::check_token_ref($v, "$spath.padding.$bp", self::deep($section, ['padding', $bp]), 'space', $vocab, false);
            }

            $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
            if (count($blocks) > self::MAX_BLOCKS_SECTION) {
                $v[] = self::vio('section_too_large', "$spath.blocks", 'Section has more than ' . self::MAX_BLOCKS_SECTION . ' blocks.');
                continue;
            }
            foreach ($blocks as $bi => $block) {
                $bpath = "$spath.blocks[$bi]";
                if (!is_array($block)) {
                    $v[] = self::vio('block_shape', $bpath, 'Block is not an object.');
                    continue;
                }
                $type = (string) ($block['type'] ?? '');
                if (!in_array($type, self::SUPPORTED_BLOCKS, true)) {
                    $v[] = self::vio('block_unsupported', $bpath, "Block type '$type' is not supported by the emitter yet.");
                    continue;
                }

                $style = is_array($block['style'] ?? null) ? $block['style'] : [];
                $style_groups = ['color' => 'color', 'typography' => 'type', 'radius' => 'radius'];
                foreach ($style_groups as $key => $group) {
                    if (array_key_exists($key, $style)) {
                        self::check_token_ref($v, "$bpath.style.$key", $style[$key], $group, $vocab, true);
                    }
                }

                if ($type === 'heading') {
                    $level = (int) ($block['level'] ?? 0);
                    if ($level < 1 || $level > 6) {
                        $v[] = self::vio('heading_level', "$bpath.level", 'Heading level must be 1–6.');
                    } else {
                        $heading_levels[] = $level;
                    }
                    if (trim((string) ($block['text'] ?? '')) === '') {
                        $v[] = self::vio('heading_empty', "$bpath.text", 'Heading has no text.');
                    }
                    self::check_contrast($v, $bpath, $style, $bg_slug, $vocab, $level <= 2);
                } elseif ($type === 'text') {
                    if (trim((string) ($block['text'] ?? '')) === '') {
                        $v[] = self::vio('text_empty', "$bpath.text", 'Text block has no content.');
                    }
                    self::check_contrast($v, $bpath, $style, $bg_slug, $vocab, false);
                } elseif ($type === 'image') {
                    $mid = (string) ($block['media'] ?? '');
                    $desc = $mid !== '' && isset($media[$mid]) && is_array($media[$mid]) ? $media[$mid] : null;
                    if ($desc === null) {
                        $v[] = self::vio('media_unresolved', "$bpath.media", "Image references media '$mid' not present in the media map.");
                    } else {
                        $murl = trim((string) ($desc['url'] ?? ''));
                        if ($murl === '') {
                            $v[] = self::vio('media_no_url', "$bpath.media", 'Resolved media has no URL.');
                        } elseif (!self::href_ok($murl)) {
                            $v[] = self::vio('media_bad_url', "$bpath.media", 'Media URL must be http(s) or site-relative.');
                        }
                        $w = (int) ($desc['width'] ?? 0);
                        $h = (int) ($desc['height'] ?? 0);
                        if ($w <= 0 || $h <= 0) {
                            $v[] = self::vio('media_no_dimensions', "$bpath.media", 'Image needs explicit width and height (CLS).');
                        }
                    }
                    $alt = (string) ($block['alt'] ?? ($desc['alt'] ?? ''));
                    if (trim($alt) === '') {
                        $v[] = self::vio('image_alt_missing', "$bpath.alt", 'Image needs alt text.');
                    }
                    if (!empty($block['priority'])) {
                        $priority_images++;
                    }
                } elseif ($type === 'button') {
                    if (trim((string) ($block['text'] ?? '')) === '') {
                        $v[] = self::vio('button_empty', "$bpath.text", 'Button has no label.');
                    }
                    $href = (string) ($block['href'] ?? '');
                    if ($href === '' || !self::href_ok($href)) {
                        $v[] = self::vio('button_href', "$bpath.href", 'Button needs a valid http(s) or relative URL.');
                    }
                } elseif ($type === 'spacer') {
                    self::check_token_ref($v, "$bpath.size", $block['size'] ?? null, 'space', $vocab, false);
                } elseif ($type === 'gallery') {
                    $gitems = is_array($block['items'] ?? null) ? $block['items'] : [];
                    if (count($gitems) === 0) {
                        $v[] = self::vio('gallery_empty', "$bpath.items", 'Gallery has no images.');
                    }
                    foreach ($gitems as $gi => $git) {
                        $gp = "$bpath.items[$gi]";
                        $gmid = is_array($git) ? (string) ($git['media'] ?? '') : '';
                        $gdesc = $gmid !== '' && isset($media[$gmid]) && is_array($media[$gmid]) ? $media[$gmid] : null;
                        if ($gdesc === null) {
                            $v[] = self::vio('media_unresolved', "$gp.media", "Gallery image references media '$gmid' not in the media map.");
                            continue;
                        }
                        $gurl = trim((string) ($gdesc['url'] ?? ''));
                        if ($gurl === '' || !self::href_ok($gurl)) {
                            $v[] = self::vio('media_bad_url', "$gp.media", 'Gallery image URL is missing or not http(s)/relative.');
                        }
                        if ((int) ($gdesc['width'] ?? 0) <= 0 || (int) ($gdesc['height'] ?? 0) <= 0) {
                            $v[] = self::vio('media_no_dimensions', "$gp.media", 'Gallery image needs explicit width and height (CLS).');
                        }
                        if (trim((string) ($git['alt'] ?? ($gdesc['alt'] ?? ''))) === '') {
                            $v[] = self::vio('image_alt_missing', "$gp.alt", 'Gallery image needs alt text.');
                        }
                    }
                } elseif ($type === 'quote') {
                    if (trim((string) ($block['text'] ?? '')) === '') {
                        $v[] = self::vio('quote_empty', "$bpath.text", 'Quote has no text.');
                    }
                    if (is_array($block['avatar'] ?? null)) {
                        $amid = (string) ($block['avatar']['media'] ?? '');
                        if ($amid !== '' && !(isset($media[$amid]) && is_array($media[$amid]))) {
                            $v[] = self::vio('media_unresolved', "$bpath.avatar.media", "Quote avatar references media '$amid' not in the media map.");
                        }
                    }
                    self::check_contrast($v, $bpath, $style, $bg_slug, $vocab, true);
                } elseif ($type === 'stat') {
                    if (trim((string) ($block['value'] ?? '')) === '') {
                        $v[] = self::vio('stat_empty', "$bpath.value", 'Stat has no value.');
                    }
                    if (trim((string) ($block['label'] ?? '')) === '') {
                        $v[] = self::vio('stat_no_label', "$bpath.label", 'Stat needs a label.');
                    }
                    self::check_contrast($v, $bpath, $style, $bg_slug, $vocab, true);
                } elseif ($type === 'accordion') {
                    $aitems = is_array($block['items'] ?? null) ? $block['items'] : [];
                    if (count($aitems) === 0) {
                        $v[] = self::vio('accordion_empty', "$bpath.items", 'Accordion has no items.');
                    }
                    foreach ($aitems as $ai => $ait) {
                        if (!is_array($ait) || trim((string) ($ait['q'] ?? '')) === '' || trim((string) ($ait['a'] ?? '')) === '') {
                            $v[] = self::vio('accordion_item', "$bpath.items[$ai]", 'Accordion item needs both a question and an answer.');
                        }
                    }
                } elseif ($type === 'embed') {
                    $provider = (string) ($block['provider'] ?? '');
                    $eid = (string) ($block['id'] ?? '');
                    if (!in_array($provider, self::EMBED_PROVIDERS, true)) {
                        $v[] = self::vio('embed_provider', "$bpath.provider", 'Embed provider must be youtube, vimeo, or map.');
                    } elseif ($provider === 'youtube' && !preg_match('/^[A-Za-z0-9_-]{6,20}$/', $eid)) {
                        $v[] = self::vio('embed_id', "$bpath.id", 'YouTube embed id is missing or malformed.');
                    } elseif ($provider === 'vimeo' && !ctype_digit($eid)) {
                        $v[] = self::vio('embed_id', "$bpath.id", 'Vimeo embed id must be numeric.');
                    } elseif (trim($eid) === '') {
                        $v[] = self::vio('embed_id', "$bpath.id", 'Embed id is required.');
                    }
                    if (trim((string) ($block['title'] ?? '')) === '') {
                        $v[] = self::vio('embed_title', "$bpath.title", 'Embed needs a title for accessibility.');
                    }
                }
            }
        }

        $h1 = count(array_filter($heading_levels, static fn($l) => $l === 1));
        if ($h1 === 0) {
            $v[] = self::vio('h1_missing', 'sections', 'Page must have exactly one H1; found none.');
        } elseif ($h1 > 1) {
            $v[] = self::vio('h1_multiple', 'sections', "Page must have exactly one H1; found $h1.");
        }
        $prev = 0;
        foreach ($heading_levels as $lvl) {
            if ($prev !== 0 && $lvl > $prev + 1) {
                $v[] = self::vio('heading_order', 'sections', "Heading level jumps from h$prev to h$lvl (no skipping levels).");
            }
            $prev = $lvl;
        }

        if ($priority_images > 1) {
            $v[] = self::vio('lcp_multiple', 'sections', "Only one image may be priority (LCP); found $priority_images.");
        }

        return ['ok' => count($v) === 0, 'violations' => $v];
    }

    private static function vio(string $code, string $path, string $message): array {
        return ['code' => $code, 'path' => $path, 'message' => $message];
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

    /**
     * Reject a style value that is a raw literal (hex/px/rem/number) rather than
     * a token reference, and — when the value is a ref — confirm it resolves to a
     * slug the live theme actually defines. Returns the resolved slug or null.
     */
    private static function check_token_ref(array &$v, string $path, $value, string $group, array $vocab, bool $required): ?string {
        if ($value === null || $value === '') {
            if ($required) {
                $v[] = self::vio('style_missing', $path, 'Style value is empty.');
            }
            return null;
        }
        if (!is_string($value) || !preg_match('/^(color|type|space|spacing|radius|shadow)\.[A-Za-z0-9\-]+$/', $value)) {
            $v[] = self::vio('style_literal', $path, 'Style must reference a design token, not a literal value.');
            return null;
        }
        $slug = Bynli_Connect_Emitter::resolve_token($group, $value);
        if ($slug === null) {
            $v[] = self::vio('token_unknown', $path, "Token '$value' does not map to a $group preset.");
            return null;
        }
        $pool = $vocab[$group] ?? null;
        if (is_array($pool) && !in_array($slug, $pool, true)) {
            $v[] = self::vio('token_undefined', $path, "Token '$value' is not defined in this site's theme.");
            return null;
        }
        return $slug;
    }

    private static function check_contrast(array &$v, string $path, array $style, ?string $bg_slug, array $vocab, bool $large): void {
        $palette = $vocab['_color_hex'] ?? null;
        if (!is_array($palette)) {
            return;
        }
        $fg_slug = Bynli_Connect_Emitter::resolve_token('color', $style['color'] ?? null) ?? 'text';
        $bg = $bg_slug ?? 'surface';
        if (!isset($palette[$fg_slug], $palette[$bg])) {
            return;
        }
        $ratio = self::contrast_ratio($palette[$fg_slug], $palette[$bg]);
        $min = $large ? self::CONTRAST_LARGE : self::CONTRAST_NORMAL;
        if ($ratio !== null && $ratio + 0.005 < $min) {
            $v[] = self::vio(
                'contrast_low',
                "$path.style.color",
                sprintf('Contrast %.2f:1 between %s and %s is below the %.1f:1 minimum.', $ratio, $fg_slug, $bg, $min)
            );
        }
    }

    private static function href_ok(string $href): bool {
        if ($href[0] === '/' || $href[0] === '#') {
            return true;
        }
        return (bool) preg_match('#^https?://#i', $href);
    }

    /** Live theme preset vocabulary: slug pools per group + a slug→hex color map. */
    private static function theme_vocab(): array {
        $out = [];
        if (!function_exists('wp_get_global_settings')) {
            return $out;
        }
        $s = wp_get_global_settings();

        $colors = [];
        $hex = [];
        foreach (['default', 'theme', 'custom'] as $origin) {
            $palette = $s['color']['palette'][$origin] ?? null;
            if (is_array($palette)) {
                foreach ($palette as $c) {
                    if (isset($c['slug'])) {
                        $colors[] = (string) $c['slug'];
                        if (isset($c['color'])) {
                            $hex[(string) $c['slug']] = (string) $c['color'];
                        }
                    }
                }
            }
        }
        if ($colors) {
            $out['color'] = array_values(array_unique($colors));
            $out['_color_hex'] = $hex;
        }

        $sizes = [];
        foreach (['default', 'theme', 'custom'] as $origin) {
            $fs = $s['typography']['fontSizes'][$origin] ?? null;
            if (is_array($fs)) {
                foreach ($fs as $f) {
                    if (isset($f['slug'])) {
                        $sizes[] = (string) $f['slug'];
                    }
                }
            }
        }
        if ($sizes) {
            $out['type'] = array_values(array_unique($sizes));
        }

        $spaces = [];
        foreach (['default', 'theme', 'custom'] as $origin) {
            $sp = $s['spacing']['spacingSizes'][$origin] ?? null;
            if (is_array($sp)) {
                foreach ($sp as $p) {
                    if (isset($p['slug'])) {
                        $spaces[] = (string) $p['slug'];
                    }
                }
            }
        }
        if ($spaces) {
            $out['space'] = array_values(array_unique($spaces));
        }

        $radius = $s['custom']['radius'] ?? null;
        if (is_array($radius)) {
            $out['radius'] = array_map('strval', array_keys($radius));
        }

        return $out;
    }

    private static function contrast_ratio(string $hex1, string $hex2): ?float {
        $l1 = self::luminance($hex1);
        $l2 = self::luminance($hex2);
        if ($l1 === null || $l2 === null) {
            return null;
        }
        $hi = max($l1, $l2);
        $lo = min($l1, $l2);
        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function luminance(string $hex): ?float {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }
        $chan = [];
        foreach ([0, 2, 4] as $i) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            $chan[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $chan[0] + 0.7152 * $chan[1] + 0.0722 * $chan[2];
    }
}
