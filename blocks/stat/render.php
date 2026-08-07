<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/stat.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$value = isset($attributes['value']) ? (string) $attributes['value'] : '';
if (trim($value) === '') {
    return '';
}

$label   = (string) ($attributes['label'] ?? '');
$caption = (string) ($attributes['caption'] ?? '');
$align   = ($attributes['align'] ?? 'start') === 'center' ? 'center' : 'start';

$wrapper = get_block_wrapper_attributes(['class' => 'bynefit-stat bynefit-stat--' . $align]);

$out = '<div class="bynefit-stat__value">' . esc_html($value) . '</div>';
if ($label !== '') {
    $out .= '<div class="bynefit-stat__label">' . esc_html($label) . '</div>';
}
if ($caption !== '') {
    $out .= '<div class="bynefit-stat__caption">' . esc_html($caption) . '</div>';
}

printf('<div %s>%s</div>', $wrapper, $out);
