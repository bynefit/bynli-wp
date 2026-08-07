<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/logos.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$items = is_array($attributes['items'] ?? null) ? $attributes['items'] : [];
if (!$items) {
    return '';
}
$muted = !isset($attributes['muted']) || !empty($attributes['muted']);

$wrapper = get_block_wrapper_attributes([
    'class' => 'bynefit-logos' . ($muted ? ' bynefit-logos--muted' : ''),
]);

$cells = '';
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    $url = isset($item['url']) ? esc_url((string) $item['url']) : '';
    if ($url === '') {
        continue;
    }
    $alt = esc_attr((string) ($item['alt'] ?? ''));
    $w = (isset($item['width']) && is_numeric($item['width'])) ? (int) $item['width'] : 0;
    $h = (isset($item['height']) && is_numeric($item['height'])) ? (int) $item['height'] : 0;
    $dims = ($w > 0 ? ' width="' . $w . '"' : '') . ($h > 0 ? ' height="' . $h . '"' : '');

    $cells .= '<img class="bynefit-logos__img" src="' . $url . '" alt="' . $alt . '"' . $dims . ' loading="lazy" decoding="async">';
}

if ($cells === '') {
    return '';
}

printf('<div %s>%s</div>', $wrapper, $cells);
