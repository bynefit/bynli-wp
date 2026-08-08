<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/section.
 *
 * Renders a CSS Grid whose track count is phone-first (cols.sm) and expands to
 * desktop (cols.lg). Each inner block is wrapped in a positioned cell driven by
 * its entry in the `places` map (indexed to child order).
 *
 * A section can also be "framed": a background image/video with an overlay
 * scrim and a min-height, which is how a full-bleed hero (and a full-width
 * background video) is expressed — the grid content then layers above the bg.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$cols    = is_array($attributes['cols'] ?? null) ? $attributes['cols'] : [];
$cols_sm = Bynli_Connect_Blocks::grid_int($cols['sm'] ?? null, 1, 12, 4);
$cols_lg = Bynli_Connect_Blocks::grid_int($cols['lg'] ?? null, 1, 12, 12);

$vars = [
    '--bynefit-cols-sm' => (string) $cols_sm,
    '--bynefit-cols-lg' => (string) $cols_lg,
];

$gap = Bynli_Connect_Blocks::token('spacing', $attributes['gap'] ?? null);
if ($gap !== null) {
    $vars['--bynefit-gap'] = $gap;
}

$padding = is_array($attributes['padding'] ?? null) ? $attributes['padding'] : [];
$pad_sm  = Bynli_Connect_Blocks::token('spacing', $padding['sm'] ?? null);
$pad_lg  = Bynli_Connect_Blocks::token('spacing', $padding['lg'] ?? null);
if ($pad_sm !== null) {
    $vars['--bynefit-pad-sm'] = $pad_sm;
}
if ($pad_lg !== null) {
    $vars['--bynefit-pad-lg'] = $pad_lg;
}

$bg = Bynli_Connect_Blocks::token('color', $attributes['bg'] ?? null);
if ($bg !== null) {
    $vars['--bynefit-bg'] = $bg;
}

$grid_style = '';
foreach ($vars as $prop => $val) {
    $grid_style .= $prop . ':' . $val . ';';
}

$places       = is_array($attributes['places'] ?? null) ? $attributes['places'] : [];
$inner_blocks = ($block instanceof WP_Block && !empty($block->parsed_block['innerBlocks']))
    ? $block->parsed_block['innerBlocks']
    : [];

$cells = '';
foreach ($inner_blocks as $i => $inner) {
    $place      = isset($places[$i]) && is_array($places[$i]) ? $places[$i] : [];
    $cell_style = Bynli_Connect_Blocks::cell_vars($place);
    $rendered   = render_block($inner);
    $cells     .= '<div class="bynefit-cell"'
        . ($cell_style !== '' ? ' style="' . esc_attr($cell_style) . '"' : '')
        . '>' . $rendered . '</div>';
}

if ($cells === '') {
    return '';
}

$bg_media = is_array($attributes['bgMedia'] ?? null) ? $attributes['bgMedia'] : null;
$overlay  = is_array($attributes['overlay'] ?? null) ? $attributes['overlay'] : null;

$minh_map  = ['short' => '40vh', 'medium' => '60vh', 'tall' => '80vh', 'full' => '100vh'];
$minh      = isset($minh_map[$attributes['minHeight'] ?? '']) ? $minh_map[$attributes['minHeight']] : '';
$valign_map = ['top' => 'flex-start', 'center' => 'center', 'bottom' => 'flex-end'];
$valign     = $valign_map[$attributes['valign'] ?? ''] ?? '';

$framed = $bg_media !== null || $overlay !== null || $minh !== '';

if (!$framed) {
    $wrapper = get_block_wrapper_attributes(['class' => 'bynefit-section', 'style' => $grid_style]);
    printf('<div %s>%s</div>', $wrapper, $cells);
    return;
}

$shell_style = '';
if ($minh !== '') {
    $shell_style .= '--bynefit-minh:' . $minh . ';';
}
if ($valign !== '') {
    $shell_style .= '--bynefit-valign:' . $valign . ';';
}

$bg_layer = '';
if ($bg_media !== null) {
    $url = isset($bg_media['url']) ? esc_url((string) $bg_media['url']) : '';
    if ($url !== '') {
        $focal = is_array($bg_media['focal'] ?? null) ? $bg_media['focal'] : [];
        $fx = isset($focal['x']) && is_numeric($focal['x']) ? min(1.0, max(0.0, (float) $focal['x'])) : 0.5;
        $fy = isset($focal['y']) && is_numeric($focal['y']) ? min(1.0, max(0.0, (float) $focal['y'])) : 0.5;
        $bg_style = '--bynefit-focal:' . round($fx * 100, 2) . '% ' . round($fy * 100, 2) . '%;';

        if (($bg_media['kind'] ?? 'image') === 'video') {
            $poster = isset($bg_media['poster']) ? esc_url((string) $bg_media['poster']) : '';
            $el = '<video class="bynefit-section__bgel" src="' . $url . '"'
                . ($poster !== '' ? ' poster="' . $poster . '"' : '')
                . ' autoplay muted loop playsinline preload="metadata" aria-hidden="true"></video>';
        } else {
            $w = (isset($bg_media['width']) && is_numeric($bg_media['width'])) ? (int) $bg_media['width'] : 0;
            $h = (isset($bg_media['height']) && is_numeric($bg_media['height'])) ? (int) $bg_media['height'] : 0;
            $dims = ($w > 0 ? ' width="' . $w . '"' : '') . ($h > 0 ? ' height="' . $h . '"' : '');
            $img = '<img class="bynefit-section__bgel" src="' . $url . '" alt=""' . $dims . ' fetchpriority="high" decoding="async">';
            $sources = is_array($bg_media['sources'] ?? null) ? $bg_media['sources'] : [];
            $picture = '';
            foreach (['avif' => 'image/avif', 'webp' => 'image/webp'] as $ext => $mime) {
                if (!empty($sources[$ext]) && is_string($sources[$ext])) {
                    $src = esc_url($sources[$ext]);
                    if ($src !== '') {
                        $picture .= '<source type="' . esc_attr($mime) . '" srcset="' . $src . '">';
                    }
                }
            }
            $el = $picture !== '' ? '<picture>' . $picture . $img . '</picture>' : $img;
        }
        $bg_layer = '<div class="bynefit-section__bg" style="' . esc_attr($bg_style) . '">' . $el . '</div>';
    }
}

$scrim = '';
if ($overlay !== null) {
    $oc = Bynli_Connect_Blocks::token('color', $overlay['color'] ?? null);
    $op = isset($overlay['opacity']) && is_numeric($overlay['opacity'])
        ? min(100, max(0, (int) $overlay['opacity'])) / 100
        : 0.4;
    $scrim_style = ($oc !== null ? '--bynefit-scrim-color:' . $oc . ';' : '') . '--bynefit-scrim-opacity:' . $op . ';';
    $scrim = '<div class="bynefit-section__scrim" style="' . esc_attr($scrim_style) . '" aria-hidden="true"></div>';
}

$wrapper = get_block_wrapper_attributes([
    'class' => 'bynefit-section-shell',
    'style' => $shell_style,
]);

printf(
    '<div %s>%s%s<div class="bynefit-section" style="%s">%s</div></div>',
    $wrapper,
    $bg_layer,
    $scrim,
    esc_attr($grid_style),
    $cells
);
