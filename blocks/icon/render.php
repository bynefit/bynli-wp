<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/icon.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$name = isset($attributes['name']) ? (string) $attributes['name'] : '';
$size = (isset($attributes['size']) && is_numeric($attributes['size'])) ? (int) $attributes['size'] : 24;
$label = (string) ($attributes['label'] ?? '');

$svg = Bynli_Connect_Blocks::icon_svg($name, $size, $label);
if ($svg === null) {
    return '';
}

$color = Bynli_Connect_Blocks::token('color', $attributes['color'] ?? null);
$style = $color !== null ? 'color:' . $color . ';' : '';

$wrapper = get_block_wrapper_attributes([
    'class' => 'bynefit-icon-wrap',
    'style' => $style,
]);

printf('<span %s>%s</span>', $wrapper, $svg);
