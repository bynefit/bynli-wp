<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/accordion.
 *
 * Native <details>/<summary> — accessible and keyboard-operable with no JS.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$items = is_array($attributes['items'] ?? null) ? $attributes['items'] : [];
if (!$items) {
    return '';
}

$wrapper = get_block_wrapper_attributes(['class' => 'bynefit-accordion']);

$rows = '';
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    $q = trim((string) ($item['q'] ?? ''));
    $a = (string) ($item['a'] ?? '');
    if ($q === '' || trim($a) === '') {
        continue;
    }
    $rows .= '<details class="bynefit-accordion__item"><summary class="bynefit-accordion__q">'
        . esc_html($q)
        . '</summary><div class="bynefit-accordion__a">'
        . wp_kses_post($a)
        . '</div></details>';
}

if ($rows === '') {
    return '';
}

printf('<div %s>%s</div>', $wrapper, $rows);
