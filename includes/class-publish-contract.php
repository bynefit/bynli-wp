<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Emit-time publish-contract gate for a Bynefit Sites scene-graph page.
 *
 * Runs the static, document-level half of the contract: the things provable
 * from the doc + the resolved token vocabulary before any WP
 * write. Runtime budgets (CWV timings, critical CSS) are measured post-publish
 * and are not this gate's job. A failing page is refused and every violation is
 * returned so the app can surface the exact fix — never a silent partial write.
 */
class Bynli_Connect_Publish_Contract {

    const SUPPORTED_BLOCKS = ['heading', 'text', 'image', 'button', 'spacer', 'divider', 'gallery', 'quote', 'stat', 'accordion', 'embed', 'icon', 'list', 'cta', 'callout', 'card', 'logos', 'form', 'events', 'tabs', 'carousel'];

    const MAX_TABS     = 12;
    const MAX_CAROUSEL = 30;

    const CONTAINER_BLOCKS = ['section', 'card'];

    const EMBED_PROVIDERS   = ['youtube', 'vimeo', 'map'];
    const LIST_MARKERS      = ['check', 'arrow', 'dot', 'none'];
    const CALLOUT_VARIANTS  = ['info', 'success', 'warn', 'tip'];
    const CTA_BG_TOKENS     = ['surface', 'surface-2'];
    const BLOCK_ALIGNS      = ['start', 'center'];
    const EMBED_RATIOS      = ['16-9', '4-3', '1-1', '21-9'];
    const MAX_LIST_ITEMS    = 60;

    // Grid track bounds, READ FROM the render layer rather than restated here. The
    // gate's whole job is to refuse the values render would otherwise clamp in
    // silence, so a second copy that agrees by convention is the one arrangement that
    // cannot hold. These aliases exist so the rest of this file reads unchanged.
    const GRID_COLS_MIN       = Bynli_Connect_Blocks::GRID_COLS_MIN;
    const GRID_COLS_MAX       = Bynli_Connect_Blocks::GRID_COLS_MAX;
    const GALLERY_COLS_MAX    = Bynli_Connect_Blocks::GALLERY_COLS_MAX;

    const MAX_SECTIONS        = 200;
    const MAX_BLOCKS_SECTION  = 200;
    const MAX_GALLERY_ITEMS   = 60;
    const MAX_ACCORDION_ITEMS = 60;

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
            // The section's effective track count per breakpoint, resolved the same way
            // the emitter resolves it — including its defaults, because a section that
            // declares no cols still renders on 4 at sm and 12 at lg, and a block's
            // placement is bounded by whichever number applies.
            $tracks = [];
            $defaults = [
                'sm' => Bynli_Connect_Blocks::GRID_COLS_SM_DEFAULT,
                'lg' => Bynli_Connect_Blocks::GRID_COLS_LG_DEFAULT,
            ];
            foreach ($defaults as $bp => $fallback) {
                self::check_grid_cols(
                    $v, "$spath.grid.cols.$bp",
                    self::deep($section, ['grid', 'cols', $bp]),
                    self::GRID_COLS_MAX
                );
                $declared = self::deep($section, ['grid', 'cols', $bp]);
                $tracks[$bp] = is_numeric($declared) ? (int) $declared : $fallback;
            }
            foreach (['sm', 'lg'] as $bp) {
                self::check_token_ref($v, "$spath.padding.$bp", self::deep($section, ['padding', $bp]), 'space', $vocab, false);
            }

            if (is_array($section['bgMedia'] ?? null)) {
                $bmid  = (string) ($section['bgMedia']['media'] ?? '');
                $bdesc = $bmid !== '' && isset($media[$bmid]) && is_array($media[$bmid]) ? $media[$bmid] : null;
                if ($bdesc === null) {
                    $v[] = self::vio('media_unresolved', "$spath.bgMedia", "Section background references media '$bmid' not in the media map.");
                } else {
                    $burl = trim((string) ($bdesc['url'] ?? ''));
                    if ($burl === '' || !self::href_ok($burl)) {
                        $v[] = self::vio('media_bad_url', "$spath.bgMedia", 'Section background URL is missing or not http(s)/relative.');
                    }
                    $bkind = $section['bgMedia']['kind'] ?? ($bdesc['kind'] ?? 'image');
                    if ($bkind !== 'video' && ((int) ($bdesc['width'] ?? 0) <= 0 || (int) ($bdesc['height'] ?? 0) <= 0)) {
                        $v[] = self::vio('media_no_dimensions', "$spath.bgMedia", 'Section background image needs explicit width and height (CLS).');
                    }
                }
                // Text over a background image/video needs a scrim to stay legible.
                if (!is_array($section['overlay'] ?? null)) {
                    $v[] = self::vio('bg_needs_overlay', "$spath.overlay", 'A section with a background image/video needs an overlay scrim so overlaid text stays legible.');
                }
            }
            if (is_array($section['overlay'] ?? null)) {
                self::check_token_ref($v, "$spath.overlay.color", $section['overlay']['color'] ?? null, 'color', $vocab, false);
                // The emitter does (int) on this, so 42.7 published clean and rendered
                // at 42 — the same silent truncation the events limit was converted away
                // from three hundred lines up, in this file, in the same change.
                self::check_bounded_int(
                    $v, "$spath.overlay.opacity", $section['overlay']['opacity'] ?? null,
                    0, 100, 'Overlay opacity', 'overlay_opacity'
                );
            }
            if (isset($section['minHeight']) && !in_array((string) $section['minHeight'], ['short', 'medium', 'tall', 'full'], true)) {
                $v[] = self::vio('section_minheight', "$spath.minHeight", 'Section minHeight must be short, medium, tall, or full.');
            }
            if (isset($section['valign']) && !in_array((string) $section['valign'], ['top', 'center', 'bottom'], true)) {
                $v[] = self::vio('section_valign', "$spath.valign", 'Section valign must be top, center, or bottom.');
            }

            $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
            if (count($blocks) > self::MAX_BLOCKS_SECTION) {
                $v[] = self::vio('section_too_large', "$spath.blocks", 'Section has more than ' . self::MAX_BLOCKS_SECTION . ' blocks.');
                continue;
            }
            foreach ($blocks as $bi => $block) {
                self::validate_block($block, "$spath.blocks[$bi]", $v, $vocab, $media, $bg_slug, $heading_levels, $priority_images, $tracks);
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

    /**
     * Validate one block in place, recursing into a card's children. Page-level
     * accumulators (heading levels, priority-image count) are threaded by
     * reference so a heading or LCP image nested in a card still counts toward
     * the page's one-H1 / one-LCP rules. $bg_slug is the effective background
     * (section, or the card's own) used for the block's contrast check.
     */
    private static function validate_block($block, string $bpath, array &$v, array $vocab, array $media, ?string $bg_slug, array &$heading_levels, int &$priority_images, ?array $tracks): void {
                if (!is_array($block)) {
                    $v[] = self::vio('block_shape', $bpath, 'Block is not an object.');
                    return;
                }
                $type = (string) ($block['type'] ?? '');
                if (!in_array($type, self::SUPPORTED_BLOCKS, true)) {
                    $v[] = self::vio('block_unsupported', $bpath, "Block type '$type' is not supported by the emitter yet.");
                    return;
                }

                if ($tracks !== null) {
                    self::check_place($v, "$bpath.place", $block['place'] ?? null, $tracks);
                }

                $style = is_array($block['style'] ?? null) ? $block['style'] : [];
                // Only the style keys a block's emitter actually consumes are part
                // of its contract; validating a key the emitter ignores would flag
                // (or worse, contrast-check) styling that never renders.
                $allowed_style = [
                    'heading' => ['color' => 'color'],
                    'text'    => ['color' => 'color', 'typography' => 'type'],
                    'image'   => ['radius' => 'radius'],
                    'gallery' => ['radius' => 'radius'],
                    'card'    => ['background' => 'color', 'radius' => 'radius', 'shadow' => 'shadow'],
                ];
                foreach (($allowed_style[$type] ?? []) as $key => $group) {
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
                    // 'columns', not 'cols'. The emitter reads block['columns'] and
                    // block.json declares it, so a gate on 'cols' validated a key the
                    // gallery does not have — it has never run, and columns:{sm:99}
                    // published clean and rendered clamped.
                    foreach (['sm', 'lg'] as $bp) {
                        self::check_grid_cols(
                            $v, "$bpath.columns.$bp",
                            self::deep($block, ['columns', $bp]),
                            self::GALLERY_COLS_MAX
                        );
                    }
                    // Gated like spacer.size and section.grid.gap, which are the same
                    // shape: emitted through resolve_token('space', …) and therefore
                    // silently dropped rather than refused when the token is not real.
                    self::check_token_ref($v, "$bpath.gap", $block['gap'] ?? null, 'space', $vocab, false);
                    $gitems = is_array($block['items'] ?? null) ? $block['items'] : [];
                    if (count($gitems) === 0) {
                        $v[] = self::vio('gallery_empty', "$bpath.items", 'Gallery has no images.');
                    } elseif (count($gitems) > self::MAX_GALLERY_ITEMS) {
                        $v[] = self::vio('gallery_too_large', "$bpath.items", 'Gallery has more than ' . self::MAX_GALLERY_ITEMS . ' images.');
                        return;
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
                    self::check_contrast($v, $bpath, [], $bg_slug, $vocab, true);
                } elseif ($type === 'stat') {
                    if (trim((string) ($block['value'] ?? '')) === '') {
                        $v[] = self::vio('stat_empty', "$bpath.value", 'Stat has no value.');
                    }
                    if (trim((string) ($block['label'] ?? '')) === '') {
                        $v[] = self::vio('stat_no_label', "$bpath.label", 'Stat needs a label.');
                    }
                    self::check_contrast($v, $bpath, [], $bg_slug, $vocab, true);
                } elseif ($type === 'accordion') {
                    $aitems = is_array($block['items'] ?? null) ? $block['items'] : [];
                    if (count($aitems) === 0) {
                        $v[] = self::vio('accordion_empty', "$bpath.items", 'Accordion has no items.');
                    } elseif (count($aitems) > self::MAX_ACCORDION_ITEMS) {
                        $v[] = self::vio('accordion_too_large', "$bpath.items", 'Accordion has more than ' . self::MAX_ACCORDION_ITEMS . ' items.');
                        return;
                    }
                    foreach ($aitems as $ai => $ait) {
                        if (!is_array($ait) || trim((string) ($ait['q'] ?? '')) === '' || trim((string) ($ait['a'] ?? '')) === '') {
                            $v[] = self::vio('accordion_item', "$bpath.items[$ai]", 'Accordion item needs both a question and an answer.');
                        }
                    }
                } elseif ($type === 'embed') {
                    $provider = (string) ($block['provider'] ?? '');
                    $eid = (string) ($block['id'] ?? '');
                    // An unrecognised ratio is not emitted at all, so the block falls
                    // back to 16-9 and the author sees a shape they did not pick.
                    self::check_enum(
                        $v, "$bpath.ratio", $block['ratio'] ?? null,
                        self::EMBED_RATIOS, 'Embed ratio', 'embed_ratio'
                    );
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
                if (in_array($type, ['quote', 'stat', 'cta'], true)) {
                    // The emitter rewrites anything that is not exactly 'center' to
                    // 'start', so "end" or a typo renders left-aligned with no complaint.
                    self::check_enum(
                        $v, "$bpath.align", $block['align'] ?? null,
                        self::BLOCK_ALIGNS, 'Alignment', 'block_align'
                    );
                }
                if ($type === 'icon') {
                    $iname = (string) ($block['name'] ?? '');
                    if (Bynli_Connect_Blocks::icon_svg($iname) === null) {
                        $v[] = self::vio('icon_unknown', "$bpath.name", "Icon '$iname' is not in the icon set.");
                    }
                    // icon_svg() clamps size to 8..96, so an out-of-range value published
                    // clean and rendered at a size the author did not choose — the same
                    // silent clamp the grid gates exist to refuse, in the branch beside them.
                    self::check_bounded_int(
                        $v, "$bpath.size", $block['size'] ?? null,
                        Bynli_Connect_Blocks::ICON_SIZE_MIN,
                        Bynli_Connect_Blocks::ICON_SIZE_MAX,
                        'Icon size',
                        'icon_size'
                    );
                    self::check_token_ref($v, "$bpath.color", $block['color'] ?? null, 'color', $vocab, false);
                } elseif ($type === 'list') {
                    $litems = is_array($block['items'] ?? null) ? $block['items'] : [];
                    if (count($litems) === 0) {
                        $v[] = self::vio('list_empty', "$bpath.items", 'List has no items.');
                    } elseif (count($litems) > self::MAX_LIST_ITEMS) {
                        $v[] = self::vio('list_too_large', "$bpath.items", 'List has more than ' . self::MAX_LIST_ITEMS . ' items.');
                        return;
                    }
                    foreach ($litems as $li => $lit) {
                        $ltext = is_array($lit) ? (string) ($lit['text'] ?? '') : (string) $lit;
                        if (trim($ltext) === '') {
                            $v[] = self::vio('list_item_empty', "$bpath.items[$li]", 'List item has no text.');
                        }
                        if (is_array($lit) && !empty($lit['icon']) && Bynli_Connect_Blocks::icon_svg((string) $lit['icon']) === null) {
                            $v[] = self::vio('icon_unknown', "$bpath.items[$li].icon", "Icon '{$lit['icon']}' is not in the icon set.");
                        }
                    }
                    if (isset($block['marker']) && !in_array((string) $block['marker'], self::LIST_MARKERS, true)) {
                        $v[] = self::vio('list_marker', "$bpath.marker", 'List marker must be check, arrow, dot, or none.');
                    }
                    self::check_token_ref($v, "$bpath.color", $block['color'] ?? null, 'color', $vocab, false);
                } elseif ($type === 'cta') {
                    $cbtns = is_array($block['buttons'] ?? null) ? $block['buttons'] : [];
                    $valid_btn = 0;
                    foreach ($cbtns as $ci => $cbtn) {
                        if (!is_array($cbtn)) {
                            continue;
                        }
                        $clabel = (string) ($cbtn['label'] ?? '');
                        $chref  = (string) ($cbtn['href'] ?? '');
                        if (trim($clabel) === '' || $chref === '' || !self::href_ok($chref)) {
                            $v[] = self::vio('cta_button', "$bpath.buttons[$ci]", 'CTA button needs a label and a valid http(s)/relative URL.');
                        } else {
                            $valid_btn++;
                        }
                    }
                    if (trim((string) ($block['title'] ?? '')) === '' && $valid_btn === 0) {
                        $v[] = self::vio('cta_empty', $bpath, 'CTA needs a title or at least one button.');
                    }
                    $cta_bg = self::check_token_ref($v, "$bpath.bg", $block['bg'] ?? null, 'color', $vocab, false);
                    if ($cta_bg !== null && !in_array($cta_bg, self::CTA_BG_TOKENS, true)) {
                        $v[] = self::vio('cta_bg', "$bpath.bg", 'CTA fill must be a surface token so the title, supporting line, and buttons stay legible.');
                    }
                } elseif ($type === 'callout') {
                    if (!in_array((string) ($block['variant'] ?? 'info'), self::CALLOUT_VARIANTS, true)) {
                        $v[] = self::vio('callout_variant', "$bpath.variant", 'Callout variant must be info, success, warn, or tip.');
                    }
                    if (trim((string) ($block['title'] ?? '')) === '' && trim((string) ($block['text'] ?? '')) === '') {
                        $v[] = self::vio('callout_empty', $bpath, 'Callout needs a title or text.');
                    }
                } elseif ($type === 'tabs') {
                    $titems = is_array($block['items'] ?? null) ? $block['items'] : [];
                    if (count($titems) < 2) {
                        $v[] = self::vio('tabs_min', "$bpath.items", 'Tabs need at least two items.');
                    } elseif (count($titems) > self::MAX_TABS) {
                        $v[] = self::vio('tabs_too_large', "$bpath.items", 'Tabs has more than ' . self::MAX_TABS . ' items.');
                    } else {
                        foreach ($titems as $ti => $tit) {
                            if (!is_array($tit) || trim((string) ($tit['label'] ?? '')) === '' || trim((string) ($tit['body'] ?? '')) === '') {
                                $v[] = self::vio('tabs_item', "$bpath.items[$ti]", 'Each tab needs a label and body.');
                            }
                        }
                    }
                } elseif ($type === 'carousel') {
                    $citems = is_array($block['items'] ?? null) ? $block['items'] : [];
                    if (count($citems) === 0) {
                        $v[] = self::vio('carousel_empty', "$bpath.items", 'Carousel has no slides.');
                    } elseif (count($citems) > self::MAX_CAROUSEL) {
                        $v[] = self::vio('carousel_too_large', "$bpath.items", 'Carousel has more than ' . self::MAX_CAROUSEL . ' slides.');
                    } else {
                        foreach ($citems as $ci => $cit) {
                            if (!is_array($cit) || trim((string) ($cit['text'] ?? '')) === '') {
                                $v[] = self::vio('carousel_item', "$bpath.items[$ci]", 'Each carousel slide needs text.');
                                continue;
                            }
                            if (is_array($cit['avatar'] ?? null)) {
                                $amid = (string) ($cit['avatar']['media'] ?? '');
                                if ($amid !== '' && !(isset($media[$amid]) && is_array($media[$amid]))) {
                                    $v[] = self::vio('media_unresolved', "$bpath.items[$ci].avatar.media", "Carousel avatar references media '$amid' not in the media map.");
                                }
                            }
                        }
                    }
                } elseif ($type === 'form') {
                    if (!preg_match('/^frm_[A-Za-z0-9_\-]{6,40}$/', (string) ($block['formId'] ?? ''))) {
                        $v[] = self::vio('form_id', "$bpath.formId", 'Form needs a valid Bynefit form id (frm_…).');
                    }
                    if (isset($block['style']) && !in_array((string) $block['style'], ['default', 'bootstrap', 'bare'], true)) {
                        $v[] = self::vio('form_style', "$bpath.style", 'Form style must be default, bootstrap, or bare.');
                    }
                    if (isset($block['successMode']) && !in_array((string) $block['successMode'], ['toast', 'replace', 'hide'], true)) {
                        $v[] = self::vio('form_success_mode', "$bpath.successMode", 'Form success mode must be toast, replace, or hide.');
                    }
                } elseif ($type === 'events') {
                    if (!preg_match('/^[a-z0-9\-]{3,100}$/', strtolower((string) ($block['team'] ?? '')))) {
                        $v[] = self::vio('events_team', "$bpath.team", 'Events needs a valid team slug.');
                    }
                    if (isset($block['style']) && !in_array((string) $block['style'], ['cards', 'list', 'bare'], true)) {
                        $v[] = self::vio('events_style', "$bpath.style", 'Events style must be cards, list, or bare.');
                    }
                    if (isset($block['scope']) && !in_array((string) $block['scope'], ['upcoming', 'past'], true)) {
                        $v[] = self::vio('events_scope', "$bpath.scope", 'Events scope must be upcoming or past.');
                    }
                    // isIntLike, not is_numeric: 2.9 passed and rendered as 2, which is the
                    // truncation every other gate in this file refuses.
                    if (isset($block['limit'])
                        && (!Bynli_Connect_Blocks::isIntLike($block['limit'])
                            || (int) $block['limit'] < 1
                            || (int) $block['limit'] > 50)) {
                        $v[] = self::vio('events_limit', "$bpath.limit", 'Events limit must be a whole number, 1–50.');
                    }
                } elseif ($type === 'card') {
                    self::check_token_ref($v, "$bpath.padding", $block['padding'] ?? null, 'space', $vocab, false);
                    $card_bg = Bynli_Connect_Emitter::resolve_token('color', self::deep($block, ['style', 'background'])) ?? $bg_slug;
                    $cblocks = is_array($block['blocks'] ?? null) ? $block['blocks'] : [];
                    if (count($cblocks) === 0) {
                        $v[] = self::vio('card_empty', "$bpath.blocks", 'Card has no content.');
                    } elseif (count($cblocks) > self::MAX_BLOCKS_SECTION) {
                        $v[] = self::vio('card_too_large', "$bpath.blocks", 'Card has more than ' . self::MAX_BLOCKS_SECTION . ' blocks.');
                    } else {
                        foreach ($cblocks as $ci => $cb) {
                            $ctype = is_array($cb) ? (string) ($cb['type'] ?? '') : '';
                            if (in_array($ctype, self::CONTAINER_BLOCKS, true)) {
                                $v[] = self::vio('card_nesting', "$bpath.blocks[$ci]", 'A card can hold content blocks, not sections or other cards.');
                                continue;
                            }
                            // null, not the section's tracks: a card child's place map is
                            // never emitted, so nothing clamps it and nothing may refuse it.
                            self::validate_block($cb, "$bpath.blocks[$ci]", $v, $vocab, $media, $card_bg, $heading_levels, $priority_images, null);
                        }
                    }
                } elseif ($type === 'logos') {
                    $loitems = is_array($block['items'] ?? null) ? $block['items'] : [];
                    if (count($loitems) === 0) {
                        $v[] = self::vio('logos_empty', "$bpath.items", 'Logo cloud has no logos.');
                    } elseif (count($loitems) > self::MAX_GALLERY_ITEMS) {
                        $v[] = self::vio('logos_too_large', "$bpath.items", 'Logo cloud has more than ' . self::MAX_GALLERY_ITEMS . ' logos.');
                    } else {
                        foreach ($loitems as $loi => $lo) {
                            $lp = "$bpath.items[$loi]";
                            $lmid = is_array($lo) ? (string) ($lo['media'] ?? '') : '';
                            $ldesc = $lmid !== '' && isset($media[$lmid]) && is_array($media[$lmid]) ? $media[$lmid] : null;
                            if ($ldesc === null) {
                                $v[] = self::vio('media_unresolved', "$lp.media", "Logo references media '$lmid' not in the media map.");
                                continue;
                            }
                            $lurl = trim((string) ($ldesc['url'] ?? ''));
                            if ($lurl === '' || !self::href_ok($lurl)) {
                                $v[] = self::vio('media_bad_url', "$lp.media", 'Logo URL is missing or not http(s)/relative.');
                            }
                            if ((int) ($ldesc['width'] ?? 0) <= 0 || (int) ($ldesc['height'] ?? 0) <= 0) {
                                $v[] = self::vio('media_no_dimensions', "$lp.media", 'Logo needs explicit width and height (CLS).');
                            }
                            if (trim((string) ($lo['alt'] ?? ($ldesc['alt'] ?? ''))) === '') {
                                $v[] = self::vio('image_alt_missing', "$lp.alt", 'Logo needs alt text.');
                            }
                        }
                    }
                }
    }

    /**
     * The five placement properties render clamps alongside the track count.
     *
     * cell_vars() puts col, colSpan, row, rowSpan and order through the same
     * grid_int() as cols, and the gate validated none of them — so `row: 5000`
     * published clean and rendered as 999. Same defect as the one this gate was added
     * for, on the same block, decided one function away.
     *
     * col is bounded by the SECTION'S track count and colSpan by what is left of the
     * row after col — the same arithmetic cell_vars() uses, so nothing this accepts is
     * clamped or coerced afterwards.
     *
     * Only a value the renderer would REWRITE is refused: 2.9 truncates to 2, which is a
     * layout the author did not describe. 4.0 and '1e2' reproduce exactly, so they are
     * range-checked rather than type-rejected — see isIntLike(), which derives that rule
     * from grid_int() rather than restating it. It runs only for a
     * block whose placement a renderer actually reads: a card child's place map is
     * never emitted, and refusing one would refuse the whole page for a value that has
     * no effect on it.
     *
     * $tracks has no default on purpose. An omitted argument would silently restore the
     * 12-track bound, which is the defect this parameter exists to end.
     */
    private static function check_place(array &$v, string $path, $place, array $tracks): void {
        if (!is_array($place)) {
            return;
        }
        $bounds = [
            'col'      => [Bynli_Connect_Blocks::GRID_COLS_MIN, Bynli_Connect_Blocks::GRID_COLS_MAX],
            'colSpan'  => [Bynli_Connect_Blocks::GRID_COLS_MIN, Bynli_Connect_Blocks::GRID_COLS_MAX],
            'row'      => [Bynli_Connect_Blocks::PLACE_MIN,     Bynli_Connect_Blocks::PLACE_MAX],
            'rowSpan'  => [Bynli_Connect_Blocks::PLACE_MIN,     Bynli_Connect_Blocks::PLACE_MAX],
            'order'    => [Bynli_Connect_Blocks::ORDER_MIN,     Bynli_Connect_Blocks::ORDER_MAX],
        ];
        foreach (['sm', 'lg'] as $bp) {
            if (!isset($place[$bp]) || !is_array($place[$bp])) {
                continue;
            }
            // The SECTION'S track count, not the maximum one. render bounds col by it
            // and colSpan by what is left of the row after col, so bounding either by
            // 12 lets a value through that render then clamps in silence.
            $track_default = $bp === 'sm'
                ? Bynli_Connect_Blocks::GRID_COLS_SM_DEFAULT
                : Bynli_Connect_Blocks::GRID_COLS_LG_DEFAULT;
            $track = isset($tracks[$bp]) ? (int) $tracks[$bp] : $track_default;
            $track = max(Bynli_Connect_Blocks::GRID_COLS_MIN, min(Bynli_Connect_Blocks::GRID_COLS_MAX, $track));
            $col   = isset($place[$bp]['col']) && is_numeric($place[$bp]['col'])
                ? (int) $place[$bp]['col']
                : 1;
            $bounds['col'][1]     = $track;
            $bounds['colSpan'][1] = max(1, $track - max(1, min($track, $col)) + 1);
            foreach ($bounds as $key => [$min, $max]) {
                if (!array_key_exists($key, $place[$bp]) || $place[$bp][$key] === null) {
                    continue;
                }
                $val = $place[$bp][$key];
                $ipath = "$path.$bp.$key";
                // The predicate lives on the class that owns grid_int(), so the gate and
                // the renderer cannot drift on what counts as an integer. A padded or
                // signed integer string renders as that integer and must be
                // RANGE-checked here rather than type-rejected — rejecting it refused
                // the whole page for a value the renderer lays out exactly as written.
                if (!Bynli_Connect_Blocks::isIntLike($val)) {
                    $v[] = self::vio('place_type', $ipath, ucfirst($key) . ' must be a whole number.');
                    continue;
                }
                $n = (int) $val;
                if ($n < $min || $n > $max) {
                    $v[] = self::vio('place_range', $ipath,
                        ucfirst($key) . " must be between $min and $max.");
                }
            }
        }
    }

    /**
     * A grid track count must be an integer inside the range render will accept.
     *
     * null/absent is fine — every consumer has a documented default. A value that is
     * present but out of range is a violation rather than something to clamp: the phone
     * breakpoint is the one that matters, because a large cols.sm produces unusable
     * slivers on a phone (the grids stay overflow-SAFE thanks to minmax(0, 1fr), so
     * nothing breaks visibly — it just becomes unreadable, which is worse to diagnose
     * from a screenshot).
     *
     * Rejects non-integer numerics too. grid_int() casts "2.9" to 2, so accepting it
     * here would publish a layout the author did not describe.
     */
    private static function check_grid_cols(array &$v, string $path, $value, int $max): void {
        self::check_bounded_int($v, $path, $value, self::GRID_COLS_MIN, $max, 'Grid column count', 'grid_cols');
    }

    /**
     * One of a fixed set, refused rather than rewritten.
     *
     * The emitter coerces an unrecognised value to a default — align becomes 'start',
     * an unknown embed ratio falls back to 16-9 — so the page publishes clean and
     * renders something the author did not choose. Same class as the numeric clamps,
     * different primitive.
     */
    private static function check_enum(
        array &$v,
        string $path,
        $value,
        array $allowed,
        string $noun,
        string $code
    ): void {
        if ($value === null) {
            return;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $v[] = self::vio($code, $path, $noun . ' must be one of: ' . implode(', ', $allowed) . '.');
        }
    }

    /**
     * A bounded whole number, refused rather than clamped.
     *
     * The same shape as check_grid_cols, generalised so a fourth bound does not become
     * a fourth copy. Uses the shared int-like predicate, so what the gate accepts is
     * what the renderer accepts on whatever PHP version is running.
     */
    private static function check_bounded_int(
        array &$v,
        string $path,
        $value,
        int $min,
        int $max,
        string $noun,
        string $code_prefix
    ): void {
        if ($value === null) {
            return;
        }
        if (!Bynli_Connect_Blocks::isIntLike($value)) {
            $v[] = self::vio($code_prefix . '_type', $path, $noun . ' must be a whole number.');
            return;
        }
        $n = (int) $value;
        if ($n < $min || $n > $max) {
            $v[] = self::vio($code_prefix . '_range', $path, "$noun must be between $min and $max.");
        }
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

        $shadows = [];
        foreach (['default', 'theme', 'custom'] as $origin) {
            $sh = $s['shadow']['presets'][$origin] ?? null;
            if (is_array($sh)) {
                foreach ($sh as $p) {
                    if (isset($p['slug'])) {
                        $shadows[] = (string) $p['slug'];
                    }
                }
            }
        }
        if ($shadows) {
            $out['shadow'] = array_values(array_unique($shadows));
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
