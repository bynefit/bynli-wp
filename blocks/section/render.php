<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/section.
 *
 * Renders a CSS Grid whose track count is phone-first (cols.sm) and expands to
 * desktop (cols.lg). Each inner block is wrapped in a positioned cell driven by
 * its entry in the `places` map (indexed to child order). Placement is passed
 * as CSS custom properties consumed by assets/blocks.css — no per-instance
 * inline rules, nothing render-blocking.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$cols     = is_array($attributes['cols'] ?? null) ? $attributes['cols'] : [];
$cols_sm  = Bynli_Connect_Blocks::grid_int($cols['sm'] ?? null, 1, 12, 4);
$cols_lg  = Bynli_Connect_Blocks::grid_int($cols['lg'] ?? null, 1, 12, 12);

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

$style = '';
foreach ($vars as $prop => $val) {
    $style .= $prop . ':' . $val . ';';
}

$wrapper = get_block_wrapper_attributes([
    'class' => 'bynefit-section',
    'style' => $style,
]);

$places      = is_array($attributes['places'] ?? null) ? $attributes['places'] : [];
$inner_blocks = ($block instanceof WP_Block && !empty($block->parsed_block['innerBlocks']))
    ? $block->parsed_block['innerBlocks']
    : [];

$cells = '';
foreach ($inner_blocks as $i => $inner) {
    $place    = isset($places[$i]) && is_array($places[$i]) ? $places[$i] : [];
    $cell_style = Bynli_Connect_Blocks::cell_vars($place);
    $rendered   = render_block($inner);
    $cells     .= '<div class="bynefit-cell"'
        . ($cell_style !== '' ? ' style="' . esc_attr($cell_style) . '"' : '')
        . '>' . $rendered . '</div>';
}

if ($cells === '') {
    return '';
}

printf('<div %s>%s</div>', $wrapper, $cells);
