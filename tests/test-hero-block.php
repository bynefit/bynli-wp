<?php
ini_set('error_log', sys_get_temp_dir() . '/bynli-connect-test-error.log');

$hb_stubs = [
    'esc_html' => 'function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, "UTF-8"); }',
    'esc_attr' => 'function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, "UTF-8"); }',
    'esc_url_raw' => 'function esc_url_raw($u) { $u = trim((string) $u); return ($u !== "" && preg_match("#^(https?://|/|\\#)#i", $u)) ? $u : ""; }',
    'esc_url' => 'function esc_url($u) { return htmlspecialchars(esc_url_raw($u), ENT_QUOTES, "UTF-8"); }',
    'get_block_wrapper_attributes' => 'function get_block_wrapper_attributes($extra = []) { $out = []; foreach (["class", "style"] as $k) { if (!empty($extra[$k])) { $out[] = $k . "=\"" . esc_attr($extra[$k]) . "\""; } } return implode(" ", $out); }',
    'wp_enqueue_script' => 'function wp_enqueue_script($handle) { $GLOBALS["hb_enqueued"][] = $handle; }',
    'serialize_block_attributes' => 'function serialize_block_attributes($attrs) { return json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }',
    'wp_get_global_settings' => 'function wp_get_global_settings() { return $GLOBALS["hb_palette"] ? ["color" => ["palette" => ["theme" => $GLOBALS["hb_palette"]]]] : []; }',
];
foreach ($hb_stubs as $hb_name => $hb_src) {
    if (!function_exists($hb_name)) {
        eval($hb_src);
    }
}
$GLOBALS['hb_enqueued'] = [];
$GLOBALS['hb_palette']  = [];

require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-blocks.php';
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-emitter.php';
require_once BYNLI_CONNECT_PLUGIN_DIR . 'includes/class-publish-contract.php';

function hb_render(array $attributes): string
{
    ob_start();
    (function () use ($attributes) {
        include BYNLI_CONNECT_PLUGIN_DIR . 'blocks/hero/render.php';
    })();
    return (string) ob_get_clean();
}

function hb_render_block(string $name, array $attributes): string
{
    ob_start();
    (function () use ($attributes, $name) {
        $block = null;
        include BYNLI_CONNECT_PLUGIN_DIR . 'blocks/' . $name . '/render.php';
    })();
    return (string) ob_get_clean();
}

$img = ['url' => 'https://cdn.example/hero.jpg', 'width' => 1600, 'height' => 900, 'kind' => 'image'];

$plain = hb_render(['heading' => 'Hello world']);
t_true(strpos($plain, '<h1 class="bynefit-hero__heading">Hello world</h1>') !== false, 'render: the heading is an h1 by default');
t_true(strpos($plain, 'bynefit-hero__bg') === false && strpos($plain, 'bynefit-hero__scrim') === false, 'render: no background layer or scrim without media');
t_true(strpos($plain, 'bynefit-hero--framed') === false, 'render: an unframed hero is not marked framed');
t_true(strpos($plain, 'class="bynefit-hero bynefit-hero--start"') !== false, 'render: left aligned by default');
t_true(strpos($plain, 'style=') === false, 'render: an unframed hero with no tokens emits no style attribute');

t_eq(hb_render(['heading' => '   ']), '', 'render: a blank heading renders nothing');
t_eq(hb_render(['eyebrow' => 'Only an eyebrow']), '', 'render: a hero with no heading renders nothing');

$full = hb_render([
    'eyebrow'  => 'New this year',
    'heading'  => 'Neighbours feeding neighbours',
    'text'     => 'Join us on Saturday.',
    'buttons'  => [['label' => 'Give', 'href' => 'https://example.org/give'], ['label' => 'Learn more', 'href' => '/about', 'variant' => 'secondary']],
]);
$p_eyebrow = strpos($full, 'bynefit-hero__eyebrow');
$p_heading = strpos($full, 'bynefit-hero__heading');
$p_text    = strpos($full, 'bynefit-hero__text');
$p_actions = strpos($full, 'bynefit-hero__actions');
t_true($p_eyebrow !== false && $p_eyebrow < $p_heading && $p_heading < $p_text && $p_text < $p_actions, 'render: eyebrow, heading, text, actions in reading order');
t_true(strpos($full, '<a class="bynefit-hero__btn bynefit-hero__btn--primary" href="https://example.org/give">Give</a>') !== false, 'render: primary button');
t_true(strpos($full, '<a class="bynefit-hero__btn bynefit-hero__btn--secondary" href="/about">Learn more</a>') !== false, 'render: secondary button');

$xss = hb_render([
    'eyebrow' => '<b>x</b>',
    'heading' => '<img src=x onerror=alert(1)>',
    'text'    => '"><script>alert(1)</script>',
    'buttons' => [['label' => '<i>go</i>', 'href' => 'javascript:alert(1)']],
]);
t_true(strpos($xss, '<img src=x') === false && strpos($xss, '&lt;img src=x onerror=alert(1)&gt;') !== false, 'render: the heading is escaped');
t_true(strpos($xss, '<script>') === false && strpos($xss, '<b>') === false && strpos($xss, '<i>') === false, 'render: eyebrow, text and labels are escaped');
t_true(strpos($xss, 'javascript:') === false, 'render: a script URL never reaches an href');

$three = hb_render(['heading' => 'H', 'buttons' => [
    ['label' => 'One', 'href' => '/1'], ['label' => '', 'href' => '/blank'], ['label' => 'Two', 'href' => '/2'], ['label' => 'Three', 'href' => '/3'],
]]);
t_eq(substr_count($three, '<a class="bynefit-hero__btn'), 2, 'render: at most two buttons, blank ones skipped first');
t_true(strpos($three, '>One<') !== false && strpos($three, '>Two<') !== false && strpos($three, '>Three<') === false, 'render: the first two usable buttons win');

t_true(strpos(hb_render(['heading' => 'H', 'level' => 2]), '<h2 class="bynefit-hero__heading">H</h2>') !== false, 'render: level 2 is an h2');
foreach ([3, 0, -1, 'x', 1.5] as $bad_level) {
    t_true(strpos(hb_render(['heading' => 'H', 'level' => $bad_level]), '<h1 class="bynefit-hero__heading">') !== false, 'render: level ' . var_export($bad_level, true) . ' falls back to h1');
}
t_true(strpos(hb_render(['heading' => 'H', 'align' => 'center']), 'bynefit-hero--center') !== false, 'render: centered');
t_true(strpos(hb_render(['heading' => 'H', 'align' => 'end']), 'bynefit-hero--start') !== false, 'render: an unknown alignment is start');

$framed = hb_render(['heading' => 'H', 'bgMedia' => $img, 'overlay' => ['opacity' => 60]]);
t_true(strpos($framed, '<img class="bynefit-hero__bgel" src="https://cdn.example/hero.jpg" alt="" width="1600" height="900" decoding="async">') !== false, 'render: background image has explicit dimensions, an empty alt and is never lazy');
t_true(strpos($framed, 'fetchpriority') === false && strpos($framed, 'loading="lazy"') === false, 'render: a non-priority background is neither fetchpriority high nor lazy');
t_true(strpos($framed, '--bynefit-scrim-opacity:0.6;') !== false, 'render: the scrim carries the overlay opacity');
t_true(strpos($framed, '--bynefit-scrim-color:#0e0d10;') !== false, 'render: the scrim has a colour when none is declared');
t_true(strpos($framed, 'bynefit-hero--framed') !== false, 'render: a hero with media is marked framed');
t_true(strpos($framed, '--bynefit-hero-fg:#ffffff;') !== false, 'render: text defaults to light over media');
$bg_pos = strpos($framed, 'bynefit-hero__bg"');
$sc_pos = strpos($framed, 'bynefit-hero__scrim');
$ct_pos = strpos($framed, 'bynefit-hero__content');
t_true($bg_pos !== false && $bg_pos < $sc_pos && $sc_pos < $ct_pos, 'render: media, then scrim, then content');
t_true(strpos($framed, 'aria-hidden="true"></div>') !== false, 'render: the scrim is hidden from assistive tech');

$prio = hb_render(['heading' => 'H', 'bgMedia' => $img, 'overlay' => ['opacity' => 60], 'priority' => true]);
t_true(strpos($prio, 'fetchpriority="high"') !== false && strpos($prio, 'loading="lazy"') === false, 'render: a priority background is eager and high priority');

$default_scrim = hb_render(['heading' => 'H', 'bgMedia' => $img]);
t_true(strpos($default_scrim, '--bynefit-scrim-opacity:0.6;') !== false, 'render: a missing overlay still gets the default scrim');

t_true(strpos(hb_render(['heading' => 'H', 'bgMedia' => $img, 'overlay' => ['opacity' => 250]]), '--bynefit-scrim-opacity:1;') !== false, 'render: opacity above 100 clamps');
t_true(strpos(hb_render(['heading' => 'H', 'bgMedia' => $img, 'overlay' => ['opacity' => -5]]), '--bynefit-scrim-opacity:0;') !== false, 'render: opacity below 0 clamps');
t_true(strpos(hb_render(['heading' => 'H', 'bgMedia' => $img, 'overlay' => ['color' => 'ink', 'opacity' => 80]]), '--bynefit-scrim-color:var(--wp--preset--color--ink);') !== false, 'render: scrim colour resolves a token');

$inject = hb_render(['heading' => 'H', 'bgMedia' => $img, 'color' => 'x;background:url(//evil)', 'overlay' => ['color' => 'ink;background:red']]);
t_true(strpos($inject, 'evil') === false && strpos($inject, 'background:red') === false, 'render: a malformed token never reaches CSS');
t_true(strpos($inject, '--bynefit-hero-fg:#ffffff;') !== false && strpos($inject, '--bynefit-scrim-color:#0e0d10;') !== false, 'render: a malformed token falls back to the defaults');

t_true(strpos(hb_render(['heading' => 'H', 'bgMedia' => $img, 'color' => 'brand']), '--bynefit-hero-fg:var(--wp--preset--color--brand);') !== false, 'render: a text colour token wins over the media default');
t_true(strpos(hb_render(['heading' => 'H', 'color' => 'ink']), '--bynefit-hero-fg:var(--wp--preset--color--ink);') !== false, 'render: a text colour token applies without media');

t_true(strpos(hb_render(['heading' => 'H', 'minHeight' => 'full']), '--bynefit-minh:100vh;') !== false, 'render: minHeight maps to a viewport height');
t_true(strpos(hb_render(['heading' => 'H', 'minHeight' => 'bogus']), '--bynefit-minh') === false, 'render: an unknown minHeight is ignored');
t_true(strpos(hb_render(['heading' => 'H', 'minHeight' => ['x']]), '--bynefit-minh') === false, 'render: a non-string minHeight is ignored');

t_true(strpos(hb_render(['heading' => 'H', 'bgMedia' => $img + ['focal' => ['x' => 0.2, 'y' => 0.8]], 'overlay' => []]), '--bynefit-focal:20% 80%;') !== false, 'render: focal point becomes object-position');
t_true(strpos(hb_render(['heading' => 'H', 'bgMedia' => $img + ['focal' => ['x' => 9, 'y' => -3]]]), '--bynefit-focal:100% 0%;') !== false, 'render: focal point clamps');

$pic = hb_render(['heading' => 'H', 'bgMedia' => $img + ['sources' => ['avif' => 'https://cdn.example/h.avif', 'webp' => 'https://cdn.example/h.webp']]]);
t_true(strpos($pic, '<picture><source type="image/avif" srcset="https://cdn.example/h.avif"><source type="image/webp" srcset="https://cdn.example/h.webp"><img') !== false, 'render: avif then webp then the original');

$GLOBALS['hb_enqueued'] = [];
$video = hb_render(['heading' => 'H', 'bgMedia' => ['url' => 'https://cdn.example/h.mp4', 'kind' => 'video', 'poster' => 'https://cdn.example/h.jpg'], 'overlay' => ['opacity' => 60], 'priority' => true]);
t_true(strpos($video, '<video class="bynefit-hero__bgel" src="https://cdn.example/h.mp4" poster="https://cdn.example/h.jpg" muted loop playsinline preload="metadata" data-bynefit-bgvideo aria-hidden="true"></video>') !== false, 'render: video is muted, looping, inline, hidden from assistive tech, with a poster');
t_true(strpos($video, 'autoplay') === false, 'render: video never autoplays without the script');
t_true(in_array('bynefit-blocks', $GLOBALS['hb_enqueued'], true), 'render: a background video loads the script that adds the pause control');
$GLOBALS['hb_enqueued'] = [];
hb_render(['heading' => 'H', 'bgMedia' => $img]);
t_eq($GLOBALS['hb_enqueued'], [], 'render: an image background loads no script');

t_true(strpos(hb_render(['heading' => 'H', 'bgMedia' => ['url' => '']]), 'bynefit-hero__scrim') === false, 'render: media with no URL leaves the hero unframed');
t_true(strpos(hb_render(['heading' => 'H', 'bgMedia' => ['url' => 'javascript:alert(1)']]), 'javascript:') === false, 'render: a script URL is never a background');

$vocab_media = ['m1' => $img, 'v1' => ['url' => 'https://cdn.example/h.mp4', 'kind' => 'video', 'poster' => 'https://cdn.example/p.jpg']];

function hb_attrs(string $markup, string $name = 'bynefit/hero'): ?array
{
    if (!preg_match('/<!-- wp:' . preg_quote($name, '/') . ' (\{.*?\}) \/-->/s', $markup, $m)) {
        return null;
    }
    $a = json_decode($m[1], true);
    return is_array($a) ? $a : null;
}

function hb_emit(array $block, array $media = [], array $section = []): string
{
    return Bynli_Connect_Emitter::emit_page(
        ['sections' => [array_merge(['type' => 'section', 'blocks' => [$block]], $section)]],
        $media
    );
}

$e = hb_attrs(hb_emit(['type' => 'hero', 'heading' => 'Hi']));
t_eq($e, ['heading' => 'Hi', 'level' => 1, 'align' => 'start'], 'emit: a minimal hero carries heading, level and alignment');

$e = hb_attrs(hb_emit([
    'type' => 'hero', 'heading' => 'Big', 'eyebrow' => ' Tag ', 'text' => 'Sub', 'level' => 2, 'align' => 'center',
    'minHeight' => 'tall', 'style' => ['color' => 'color.brand'], 'priority' => true,
    'buttons' => [['label' => 'Go', 'href' => '/go'], ['label' => 'More', 'href' => '/more', 'variant' => 'secondary'], ['label' => 'Third', 'href' => '/3']],
    'bgMedia' => ['media' => 'm1', 'focal' => ['x' => 0.25, 'y' => 0.75]],
    'overlay' => ['color' => 'color.ink', 'opacity' => 65],
], $vocab_media));
t_eq($e['heading'] ?? null, 'Big', 'emit: heading');
t_eq($e['level'] ?? null, 2, 'emit: level');
t_eq($e['eyebrow'] ?? null, ' Tag ', 'emit: eyebrow is stored as written');
t_eq($e['text'] ?? null, 'Sub', 'emit: text');
t_eq($e['align'] ?? null, 'center', 'emit: alignment');
t_eq($e['minHeight'] ?? null, 'tall', 'emit: minHeight');
t_eq($e['color'] ?? null, 'brand', 'emit: the text colour resolves to a theme slug');
t_eq($e['priority'] ?? null, true, 'emit: priority on an image background');
t_eq(count($e['buttons'] ?? []), 2, 'emit: at most two buttons');
t_eq($e['buttons'][1] ?? null, ['label' => 'More', 'href' => '/more', 'variant' => 'secondary'], 'emit: the secondary variant is kept');
t_eq($e['bgMedia']['url'] ?? null, 'https://cdn.example/hero.jpg', 'emit: background url');
t_eq([$e['bgMedia']['width'] ?? null, $e['bgMedia']['height'] ?? null], [1600, 900], 'emit: background dimensions travel with it');
t_eq($e['bgMedia']['focal'] ?? null, ['x' => 0.25, 'y' => 0.75], 'emit: focal point');
t_true(!array_key_exists('alt', $e['bgMedia'] ?? []), 'emit: a decorative background carries no alt');
t_eq($e['overlay'] ?? null, ['color' => 'ink', 'opacity' => 65], 'emit: overlay colour resolves to a slug');

$e = hb_attrs(hb_emit(['type' => 'hero', 'heading' => 'V', 'priority' => true, 'bgMedia' => ['media' => 'v1'], 'overlay' => ['opacity' => 60]], $vocab_media));
t_eq($e['bgMedia']['kind'] ?? null, 'video', 'emit: a video background is marked as video');
t_eq($e['bgMedia']['poster'] ?? null, 'https://cdn.example/p.jpg', 'emit: the video poster travels with it');
t_true(!array_key_exists('priority', $e ?? []), 'emit: priority means nothing on a video');

$e = hb_attrs(hb_emit(['type' => 'hero', 'heading' => 'U', 'priority' => true, 'bgMedia' => ['media' => 'nope'], 'overlay' => ['opacity' => 60]], $vocab_media));
t_true(!array_key_exists('bgMedia', $e ?? []) && !array_key_exists('overlay', $e ?? []) && !array_key_exists('priority', $e ?? []), 'emit: an unresolved background drops its overlay and priority with it');

t_eq(hb_attrs(hb_emit(['type' => 'hero', 'heading' => 'L', 'level' => 7]))['level'] ?? null, 1, 'emit: an out-of-range level is h1');
t_eq(hb_attrs(hb_emit(['type' => 'hero', 'heading' => 'A', 'align' => 'end']))['align'] ?? null, 'start', 'emit: an unknown alignment is start');
t_true(strpos(hb_emit(['type' => 'hero', 'heading' => '  ']), 'bynefit/hero') === false, 'emit: a hero with no heading is not emitted');
t_true(!array_key_exists('minHeight', hb_attrs(hb_emit(['type' => 'hero', 'heading' => 'M', 'minHeight' => 'huge'])) ?? []), 'emit: an unknown minHeight is dropped');

$card_markup = hb_emit([
    'type' => 'card',
    'blocks' => [['type' => 'hero', 'heading' => 'Nested'], ['type' => 'text', 'text' => 'kept']],
]);
t_true(strpos($card_markup, 'bynefit/hero') === false && strpos($card_markup, 'kept') !== false, 'emit: a hero cannot nest inside a card');

$section_markup = hb_emit(['type' => 'text', 'text' => 'x'], $vocab_media, [
    'bgMedia' => ['media' => 'm1'], 'overlay' => ['color' => 'color.ink', 'opacity' => 40], 'minHeight' => 'medium',
]);
$sec = preg_match('/<!-- wp:bynefit\/section (\{.*?\}) -->/s', $section_markup, $m) ? json_decode($m[1], true) : [];
t_eq($sec['bgMedia']['url'] ?? null, 'https://cdn.example/hero.jpg', 'section emit: background media is unchanged');
t_eq($sec['bgMedia']['kind'] ?? null, 'image', 'section emit: background kind is unchanged');
t_eq($sec['overlay'] ?? null, ['color' => 'ink', 'opacity' => 40], 'section emit: overlay is unchanged');
t_eq($sec['minHeight'] ?? null, 'medium', 'section emit: minHeight is unchanged');
$sec_bad = preg_match('/<!-- wp:bynefit\/section (\{.*?\}) -->/s', hb_emit(['type' => 'text', 'text' => 'x'], [], ['minHeight' => 'huge']), $m) ? json_decode($m[1], true) : [];
t_true(!array_key_exists('minHeight', $sec_bad), 'section emit: an unknown minHeight is still dropped');

$cta = hb_attrs(hb_emit(['type' => 'cta', 'title' => 'T', 'buttons' => [['label' => 'a', 'href' => '/a'], ['label' => 'b', 'href' => '/b'], ['label' => 'c', 'href' => '/c']]]), 'bynefit/cta');
t_eq(count($cta['buttons'] ?? []), 2, 'cta emit: still at most two buttons');

$cta_out = hb_render_block('cta', ['title' => 'T', 'buttons' => [['label' => 'a', 'href' => '/a'], ['label' => 'b', 'href' => '/b', 'variant' => 'secondary'], ['label' => 'c', 'href' => '/c']]]);
t_true(strpos($cta_out, '<a class="bynefit-cta__btn bynefit-cta__btn--primary" href="/a">a</a><a class="bynefit-cta__btn bynefit-cta__btn--secondary" href="/b">b</a>') !== false && strpos($cta_out, '>c<') === false, 'cta render: buttons render as before, capped at two');

function hg_page(array $blocks, array $section = [], ?array $media = null): array
{
    return [
        'page' => [
            'slug' => 'home', 'title' => 'Home', 'seo' => ['description' => 'd'],
            'sections' => [array_merge(['type' => 'section', 'blocks' => $blocks], $section)],
        ],
        'media' => $media ?? [
            'm1' => ['url' => 'https://cdn.example/hero.jpg', 'width' => 1600, 'height' => 900],
            'v1' => ['url' => 'https://cdn.example/h.mp4', 'kind' => 'video'],
            'bad' => ['url' => 'javascript:alert(1)', 'width' => 10, 'height' => 10],
            'nodim' => ['url' => 'https://cdn.example/x.jpg'],
        ],
    ];
}

function hg_codes(array $blocks, array $section = [], ?array $media = null): array
{
    $p = hg_page($blocks, $section, $media);
    $r = Bynli_Connect_Publish_Contract::validate($p['page'], $p['media']);
    return array_column($r['violations'], 'code');
}

function hg_violation(array $blocks, string $code, array $section = [], ?array $media = null): ?array
{
    $p = hg_page($blocks, $section, $media);
    foreach (Bynli_Connect_Publish_Contract::validate($p['page'], $p['media'])['violations'] as $v) {
        if ($v['code'] === $code) {
            return $v;
        }
    }
    return null;
}

$hero_ok = ['type' => 'hero', 'heading' => 'Hi', 'bgMedia' => ['media' => 'm1'], 'overlay' => ['opacity' => 60], 'buttons' => [['label' => 'Go', 'href' => '/go']]];
t_eq(hg_codes([$hero_ok]), [], 'gate: a complete hero with media and a scrim passes');
t_eq(hg_codes([['type' => 'hero', 'heading' => 'Hi']]), [], 'gate: a bare hero passes');
t_eq(hg_codes([['type' => 'hero', 'heading' => 'Hi', 'bgMedia' => ['media' => 'v1'], 'overlay' => ['opacity' => 60]]]), [], 'gate: a video background needs no dimensions');

t_true(in_array('hero_heading_empty', hg_codes([['type' => 'hero', 'heading' => ' ']]), true), 'gate: a hero needs a heading');
t_true(in_array('hero_level_range', hg_codes([['type' => 'hero', 'heading' => 'H', 'level' => 3]]), true), 'gate: level 3 is refused');
t_true(in_array('hero_level_type', hg_codes([['type' => 'hero', 'heading' => 'H', 'level' => 1.5]]), true), 'gate: a fractional level is refused, not truncated');
t_true(in_array('hero_level_type', hg_codes([['type' => 'hero', 'heading' => 'H', 'level' => 'x']]), true), 'gate: a non-numeric level is refused');
t_eq(hg_codes([['type' => 'hero', 'heading' => 'H', 'level' => 2], ['type' => 'heading', 'level' => 1, 'text' => 'Title']]), [], 'gate: an h2 hero beside a separate h1 passes');
t_true(in_array('h1_missing', hg_codes([['type' => 'hero', 'heading' => 'H', 'level' => 2]]), true), 'gate: a page whose only hero is an h2 has no h1');
t_true(in_array('h1_multiple', hg_codes([['type' => 'hero', 'heading' => 'H'], ['type' => 'heading', 'level' => 1, 'text' => 'Title']]), true), 'gate: a default-level hero counts as the page h1');
t_true(in_array('h1_multiple', hg_codes([['type' => 'hero', 'heading' => 'H'], ['type' => 'hero', 'heading' => 'Two']]), true), 'gate: two h1 heroes are refused');
t_true(in_array('heading_order', hg_codes([['type' => 'hero', 'heading' => 'H', 'level' => 2], ['type' => 'heading', 'level' => 4, 'text' => 'Skip']]), true), 'gate: a hero level takes part in heading order');

t_true(in_array('hero_needs_overlay', hg_codes([['type' => 'hero', 'heading' => 'H', 'bgMedia' => ['media' => 'm1']]]), true), 'gate: media without a scrim is refused');
t_true(in_array('hero_overlay_orphan', hg_codes([['type' => 'hero', 'heading' => 'H', 'overlay' => ['opacity' => 60]]]), true), 'gate: a scrim with nothing to cover is refused');

foreach ([[-1, 'hero_overlay_opacity_range'], [101, 'hero_overlay_opacity_range'], [40.5, 'hero_overlay_opacity_type'], ['x', 'hero_overlay_opacity_type']] as [$op, $code]) {
    t_true(in_array($code, hg_codes([['type' => 'hero', 'heading' => 'H', 'bgMedia' => ['media' => 'm1'], 'overlay' => ['opacity' => $op]]]), true), 'gate: overlay opacity ' . var_export($op, true) . ' is ' . $code);
}
foreach ([60, 100] as $op) {
    t_eq(hg_codes([['type' => 'hero', 'heading' => 'H', 'bgMedia' => ['media' => 'm1'], 'overlay' => ['opacity' => $op]]]), [], 'gate: overlay opacity ' . $op . ' passes');
}
t_eq(hg_codes([['type' => 'hero', 'heading' => 'H', 'bgMedia' => ['media' => 'm1'], 'overlay' => []]]), [], 'gate: an overlay with defaults passes');
foreach ([0, 30, 50] as $thin) {
    t_true(in_array('contrast_low', hg_codes([['type' => 'hero', 'heading' => 'H', 'bgMedia' => ['media' => 'm1'], 'overlay' => ['opacity' => $thin]]]), true), 'gate: a ' . $thin . '% scrim is too thin for light text over a bright photo');
}

$v = hg_violation([['type' => 'hero', 'heading' => 'H', 'bgMedia' => ['media' => 'zzz'], 'overlay' => ['opacity' => 60]]], 'media_unresolved');
t_true($v !== null && strpos($v['message'], 'Hero background') === 0 && $v['path'] === 'sections[0].blocks[0].bgMedia', 'gate: an unresolved hero background names the hero');
t_true(in_array('media_no_dimensions', hg_codes([['type' => 'hero', 'heading' => 'H', 'bgMedia' => ['media' => 'nodim'], 'overlay' => ['opacity' => 60]]]), true), 'gate: a background image needs dimensions (CLS)');
t_true(in_array('media_bad_url', hg_codes([['type' => 'hero', 'heading' => 'H', 'bgMedia' => ['media' => 'bad'], 'overlay' => ['opacity' => 60]]]), true), 'gate: a script URL is refused as a background');

t_true(in_array('hero_minheight', hg_codes([['type' => 'hero', 'heading' => 'H', 'minHeight' => 'huge']]), true), 'gate: an unknown minHeight is refused');
t_eq(hg_codes([['type' => 'hero', 'heading' => 'H', 'minHeight' => 'full']]), [], 'gate: a known minHeight passes');
t_true(in_array('block_align', hg_codes([['type' => 'hero', 'heading' => 'H', 'align' => 'end']]), true), 'gate: an unknown alignment is refused');
t_eq(hg_codes([['type' => 'hero', 'heading' => 'H', 'align' => 'center']]), [], 'gate: center passes');
t_eq(hb_render(['heading' => 'H', 'bgMedia' => $img, 'color' => ['x'], 'overlay' => ['color' => ['y']]]) !== '', true, 'render: array-valued colour tokens do not fatal');
t_eq(hb_render_block('section', ['minHeight' => ['tall'], 'cols' => ['sm' => 4, 'lg' => 12]]), '', 'render: an array minHeight does not fatal in a section either');

t_eq(hg_codes([array_merge($hero_ok, ['priority' => true])]), [], 'gate: a priority image background passes');
t_true(in_array('hero_priority', hg_codes([['type' => 'hero', 'heading' => 'H', 'priority' => true]]), true), 'gate: priority without a background is refused');
t_true(in_array('hero_priority', hg_codes([['type' => 'hero', 'heading' => 'H', 'priority' => true, 'bgMedia' => ['media' => 'v1'], 'overlay' => ['opacity' => 60]]]), true), 'gate: priority on a video is refused');
t_true(in_array('lcp_multiple', hg_codes([array_merge($hero_ok, ['priority' => true]), ['type' => 'image', 'media' => 'm1', 'alt' => 'a', 'priority' => true]]), true), 'gate: a priority hero counts toward the one-LCP rule');

$b = hg_violation([['type' => 'hero', 'heading' => 'H', 'buttons' => [['label' => 'ok', 'href' => '/ok'], ['label' => 'bad', 'href' => 'javascript:x']]]], 'hero_button');
t_true($b !== null && $b['path'] === 'sections[0].blocks[0].buttons[1]', 'gate: a hero button with a script URL is refused at its own path');
t_true(in_array('hero_button', hg_codes([['type' => 'hero', 'heading' => 'H', 'buttons' => [['label' => '', 'href' => '/x']]]]), true), 'gate: a hero button needs a label');
$three_btns = [['label' => 'a', 'href' => '/a'], ['label' => 'b', 'href' => '/b'], ['label' => 'c', 'href' => '/c']];
t_true(in_array('hero_button_count', hg_codes([['type' => 'hero', 'heading' => 'H', 'buttons' => $three_btns]]), true), 'gate: a third hero button is refused, not dropped');
t_eq(hg_codes([['type' => 'hero', 'heading' => 'H', 'buttons' => array_slice($three_btns, 0, 2)]]), [], 'gate: two hero buttons pass');
t_true(in_array('hero_button_shape', hg_codes([['type' => 'hero', 'heading' => 'H', 'buttons' => 'Go']]), true), 'gate: a string where the hero buttons list belongs is refused');
t_true(in_array('hero_button_shape', hg_codes([['type' => 'hero', 'heading' => 'H', 'buttons' => ['Go']]]), true), 'gate: a hero button that is not an object is refused');
t_true(in_array('hero_bgmedia_shape', hg_codes([['type' => 'hero', 'heading' => 'H', 'bgMedia' => 'm1']]), true), 'gate: a background that is not an object is refused');
t_true(in_array('hero_overlay_shape', hg_codes([['type' => 'hero', 'heading' => 'H', 'overlay' => 60]]), true), 'gate: an overlay that is not an object is refused');
t_true(in_array('hero_heading_shape', hg_codes([['type' => 'hero', 'heading' => ['x']]]), true), 'gate: a heading that is not text is refused');
t_true(in_array('hero_eyebrow_shape', hg_codes([['type' => 'hero', 'heading' => 'H', 'eyebrow' => ['x']]]), true), 'gate: an eyebrow that is not text is refused');
t_true(in_array('hero_text_shape', hg_codes([['type' => 'hero', 'heading' => 'H', 'text' => 12]]), true), 'gate: a supporting line that is not text is refused');
t_true(in_array('hero_button_shape', hg_codes([['type' => 'hero', 'heading' => 'H', 'buttons' => [['label' => ['x'], 'href' => '/a']]]]), true), 'gate: a button label that is not text is refused');
t_true(in_array('hero_button_shape', hg_codes([['type' => 'hero', 'heading' => 'H', 'buttons' => [['label' => 'a', 'href' => ['x']]]]]), true), 'gate: a button URL that is not text is refused');
t_true(in_array('hero_button_variant', hg_codes([['type' => 'hero', 'heading' => 'H', 'buttons' => [['label' => 'a', 'href' => '/a', 'variant' => 'ghost']]]]), true), 'gate: an unknown button variant is refused, not rendered as primary');
t_eq(hg_codes([['type' => 'hero', 'heading' => 'H', 'buttons' => [['label' => 'a', 'href' => '/a', 'variant' => 'primary'], ['label' => 'b', 'href' => '/b', 'variant' => 'secondary']]]]), [], 'gate: primary and secondary pass');

t_true(in_array('style_literal', hg_codes([['type' => 'hero', 'heading' => 'H', 'style' => ['color' => '#fff']]]), true), 'gate: a literal text colour is refused');
t_eq(array_count_values(hg_codes([['type' => 'hero', 'heading' => 'H', 'style' => ['color' => '#fff']]]))['style_literal'] ?? 0, 1, 'gate: a bad text colour is reported once');
t_true(in_array('place_shape', hg_codes([['type' => 'hero', 'heading' => 'H', 'place' => 5]]), true), 'gate: a hero is placed like every other block');

t_true(in_array('card_nesting', hg_codes([['type' => 'card', 'blocks' => [['type' => 'hero', 'heading' => 'H']]]]), true), 'gate: a hero cannot nest inside a card');

$GLOBALS['hb_palette'] = [
    ['slug' => 'ink', 'color' => '#000000'], ['slug' => 'white', 'color' => '#ffffff'],
    ['slug' => 'surface', 'color' => '#ffffff'], ['slug' => 'brand', 'color' => '#0b7a70'], ['slug' => 'mid', 'color' => '#888888'], ['slug' => 'grey', 'color' => '#757575'],
];
t_eq(hg_codes([$hero_ok]), [], 'gate: defaults are legible with a palette present');
t_true(in_array('contrast_low', hg_codes([array_merge($hero_ok, ['style' => ['color' => 'color.ink']])]), true), 'gate: dark text over the default dark scrim is refused');
t_true(in_array('contrast_low', hg_codes([array_merge($hero_ok, ['overlay' => ['color' => 'color.white', 'opacity' => 60]])]), true), 'gate: the default light text over a light scrim is refused');
t_eq(hg_codes([array_merge($hero_ok, ['style' => ['color' => 'color.ink'], 'overlay' => ['color' => 'color.white', 'opacity' => 60]])]), [], 'gate: dark text over a light scrim passes');
t_eq(hg_codes([array_merge($hero_ok, ['style' => ['color' => 'color.white'], 'overlay' => ['color' => 'color.ink', 'opacity' => 60]])]), [], 'gate: light text over a dark scrim passes');
t_true(in_array('contrast_low', hg_codes([array_merge($hero_ok, ['style' => ['color' => 'color.ink'], 'overlay' => ['color' => 'color.mid', 'opacity' => 40]])]), true), 'gate: dark text is judged against a dark photo, not only a bright one');
$msg = function (array $block): string {
    $p = hg_page([$block]);
    foreach (Bynli_Connect_Publish_Contract::validate($p['page'], $p['media'])['violations'] as $viol) {
        if ($viol['code'] === 'contrast_low') {
            return $viol['message'];
        }
    }
    return '';
};
t_true(strpos($msg(array_merge($hero_ok, ['style' => ['color' => 'color.ink'], 'overlay' => ['color' => 'color.mid', 'opacity' => 40]])), 'a dark photo') !== false, 'gate: dark text on a thin mid scrim blames a dark photo');
t_true(strpos($msg(array_merge($hero_ok, ['overlay' => ['color' => 'color.ink', 'opacity' => 40]])), 'a bright photo') !== false, 'gate: light text on a thin dark scrim blames a bright photo');
t_true(strpos($msg(array_merge($hero_ok, ['style' => ['color' => 'color.grey'], 'overlay' => ['color' => 'color.ink', 'opacity' => 0]])), 'as light as the text') !== false, 'gate: mid-grey text with no scrim blames a photo as light as the text');
t_true(in_array('contrast_low', hg_codes([array_merge($hero_ok, ['overlay' => ['color' => 'color.white']])]), true), 'gate: a light scrim with the default opacity is refused for the default light text');
t_eq(hg_codes([array_merge($hero_ok, ['overlay' => ['color' => 'color.ink']])]), [], 'gate: a dark scrim with the default opacity is accepted');
t_true(in_array('contrast_low', hg_codes([array_merge($hero_ok, ['style' => ['color' => 'color.grey'], 'overlay' => ['color' => 'color.ink']])]), true), 'gate: the default opacity is the one that is judged, not a fully opaque scrim');
foreach ([90, 95] as $heavy) {
    t_true(in_array('contrast_low', hg_codes([array_merge($hero_ok, ['style' => ['color' => 'color.grey'], 'overlay' => ['color' => 'color.ink', 'opacity' => $heavy]])]), true), 'gate: mid-grey text on a ' . $heavy . '% dark scrim is judged against a photo as light as the text');
}
t_eq(hg_codes([array_merge($hero_ok, ['style' => ['color' => 'color.grey'], 'overlay' => ['color' => 'color.ink', 'opacity' => 100]])]), [], 'gate: a fully opaque dark scrim leaves mid-grey text no photo to fight');
t_true(in_array('contrast_low', hg_codes([array_merge($hero_ok, ['style' => ['color' => 'color.grey'], 'overlay' => ['color' => 'color.ink', 'opacity' => 0]])]), true), 'gate: text whose luminance lies inside the scrim range can meet a photo of its own luminance');
t_true(in_array('contrast_low', hg_codes([['type' => 'hero', 'heading' => 'H', 'style' => ['color' => 'color.white']]]), true), 'gate: without media the text is checked against the section background');
t_eq(hg_codes([['type' => 'hero', 'heading' => 'H', 'style' => ['color' => 'color.ink']]]), [], 'gate: legible text on the section background passes');
t_true(in_array('token_undefined', hg_codes([array_merge($hero_ok, ['overlay' => ['color' => 'color.nope', 'opacity' => 60]])]), true), 'gate: a scrim token the theme does not define is refused');
t_true(!in_array('contrast_low', hg_codes([array_merge($hero_ok, ['overlay' => ['color' => 'color.nope', 'opacity' => 60]])]), true), 'gate: an unknown scrim token is not also reported as low contrast');
$GLOBALS['hb_palette'] = [];

$sv = hg_violation([['type' => 'text', 'text' => 'x']], 'media_unresolved', ['bgMedia' => ['media' => 'zzz'], 'overlay' => ['opacity' => 40]]);
t_true($sv !== null && $sv['message'] === "Section background references media 'zzz' not in the media map." && $sv['path'] === 'sections[0].bgMedia', 'gate: the section background message is unchanged');
t_true(in_array('media_no_dimensions', hg_codes([['type' => 'text', 'text' => 'x']], ['bgMedia' => ['media' => 'nodim'], 'overlay' => ['opacity' => 40]]), true), 'gate: the section background still needs dimensions');
t_true(in_array('bg_needs_overlay', hg_codes([['type' => 'text', 'text' => 'x']], ['bgMedia' => ['media' => 'm1']]), true), 'gate: the section background still needs a scrim');
t_true(in_array('section_minheight', hg_codes([['type' => 'text', 'text' => 'x']], ['minHeight' => 'huge']), true), 'gate: the section minHeight is still checked');
$cv = hg_violation([['type' => 'cta', 'title' => 'T', 'buttons' => [['label' => 'ok', 'href' => '/ok'], ['label' => '', 'href' => '/x']]]], 'cta_button');
t_true($cv !== null && $cv['path'] === 'sections[0].blocks[0].buttons[1]' && $cv['message'] === 'CTA button needs a label and a valid http(s)/relative URL.', 'gate: the cta button message and path are unchanged');
$cta_three = [['label' => 'a', 'href' => '/a'], ['label' => 'b', 'href' => '/b'], ['label' => 'c', 'href' => '/c']];
t_true(in_array('cta_button_count', hg_codes([['type' => 'cta', 'title' => 'T', 'buttons' => $cta_three]]), true), 'gate: a third cta button is refused, not dropped');
t_eq(hg_codes([['type' => 'heading', 'level' => 1, 'text' => 'Title'], ['type' => 'cta', 'title' => 'T', 'buttons' => array_slice($cta_three, 0, 2)]]), [], 'gate: two cta buttons pass');
t_true(in_array('cta_button_variant', hg_codes([['type' => 'cta', 'title' => 'T', 'buttons' => [['label' => 'a', 'href' => '/a', 'variant' => 'ghost']]]]), true), 'gate: an unknown cta variant is refused, not rendered as primary');
t_true(in_array('cta_button_shape', hg_codes([['type' => 'cta', 'title' => 'T', 'buttons' => ['junk']]]), true), 'gate: a cta button that is not an object is refused');
t_true(in_array('cta_empty', hg_codes([['type' => 'cta', 'title' => '', 'buttons' => [['label' => '', 'href' => '']]]]), true), 'gate: a cta with nothing usable is still refused');

function hb_node(string ...$args): ?array
{
    $proc = @proc_open(array_merge(['node', __DIR__ . '/fixtures/bgvideo-child.js'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return null;
    }
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $json = json_decode($out, true);
    return is_array($json) ? $json : null;
}

$js_section = hb_node('bynefit-section-shell', 'motion');
if ($js_section === null) {
    echo "SKIP background video script tests: node unavailable\n";
} else {
    t_eq($js_section['hasToggle'], true, 'script: a section background video gets a pause control');
    $js_hero = hb_node('bynefit-hero', 'motion');
    t_eq($js_hero['hasToggle'] ?? null, true, 'script: a hero background video gets a pause control');
    t_eq($js_hero['label'] ?? null, 'Pause background video', 'script: the hero control starts as pause when the video plays');
    t_eq($js_hero['playing'] ?? null, true, 'script: the hero video plays when motion is allowed');
    $js_still = hb_node('bynefit-hero', 'reduce');
    t_eq($js_still['playing'] ?? null, false, 'script: the hero video stays paused under reduced motion');
    t_eq($js_still['label'] ?? null, 'Play background video', 'script: under reduced motion the control offers play');
    t_eq(hb_node('some-other-wrapper', 'motion')['hasToggle'] ?? null, false, 'script: a video outside a framed block is left alone');
}

set_error_handler(function ($no, $str) {
    throw new ErrorException($str);
});
$array_min = null;
try {
    $array_min = hg_codes([['type' => 'text', 'text' => 'x']], ['minHeight' => ['tall']]);
} catch (ErrorException $e) {
    $array_min = ['threw: ' . $e->getMessage()];
}
restore_error_handler();
t_true(in_array('section_minheight', $array_min, true), 'gate: an array minHeight is refused without a PHP warning');
