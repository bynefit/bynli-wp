<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/card.
 *
 * A surface that stacks its inner blocks. $content is the inner blocks WordPress
 * already rendered for this block, so there is no per-child work here.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

if (trim($content) === '') {
    return '';
}

$vars = [];
$bg = Bynli_Connect_Blocks::token('color', $attributes['bg'] ?? null);
if ($bg !== null) {
    $vars['--bynefit-card-bg'] = $bg;
}
$radius = Bynli_Connect_Blocks::token('radius', $attributes['radius'] ?? null);
if ($radius !== null) {
    $vars['--bynefit-card-radius'] = $radius;
}
$shadow = Bynli_Connect_Blocks::token('shadow', $attributes['shadow'] ?? null);
if ($shadow !== null) {
    $vars['--bynefit-card-shadow'] = $shadow;
}
$pad = Bynli_Connect_Blocks::token('spacing', $attributes['padding'] ?? null);
if ($pad !== null) {
    $vars['--bynefit-card-pad'] = $pad;
}

$style = '';
foreach ($vars as $prop => $val) {
    $style .= $prop . ':' . $val . ';';
}

$wrapper = get_block_wrapper_attributes([
    'class' => 'bynefit-card',
    'style' => $style,
]);

printf('<div %s>%s</div>', $wrapper, $content);
