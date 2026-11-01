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
$cols_sm = Bynli_Connect_Blocks::grid_int(
    $cols['sm'] ?? null,
    Bynli_Connect_Blocks::GRID_COLS_MIN,
    Bynli_Connect_Blocks::GRID_COLS_MAX,
    Bynli_Connect_Blocks::GRID_COLS_SM_DEFAULT
);
$cols_lg = Bynli_Connect_Blocks::grid_int(
    $cols['lg'] ?? null,
    Bynli_Connect_Blocks::GRID_COLS_MIN,
    Bynli_Connect_Blocks::GRID_COLS_MAX,
    Bynli_Connect_Blocks::GRID_COLS_LG_DEFAULT
);

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
if ($bg !== null && empty($attributes['bgMedia'])) {
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
    $cell_style = Bynli_Connect_Blocks::cell_vars($place, ['sm' => $cols_sm, 'lg' => $cols_lg]);
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

$minh_map  = Bynli_Connect_Blocks::MIN_HEIGHTS;
$minh_key  = $attributes['minHeight'] ?? '';
$minh      = (is_string($minh_key) && isset($minh_map[$minh_key])) ? $minh_map[$minh_key] : '';
$valign_map = ['top' => 'flex-start', 'center' => 'center', 'bottom' => 'flex-end'];
$valign     = $valign_map[$attributes['valign'] ?? ''] ?? '';

$framed = $bg_media !== null || $overlay !== null || $minh !== '' || $valign !== '';

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

$bg_layer = $bg_media !== null ? Bynli_Connect_Blocks::bg_media_html($bg_media, 'section', true) : '';

$scrim = $overlay !== null ? Bynli_Connect_Blocks::scrim_html($overlay, 'section', 40) : '';

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
