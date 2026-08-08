<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/tabs.
 *
 * Progressive enhancement: the markup is a plain tablist + all panels visible,
 * so with no JS every panel's content is readable (stacked). assets/blocks.js
 * turns it into a WAI-ARIA tab widget (selects the first, hides the rest, wires
 * click + arrow keys).
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$items = is_array($attributes['items'] ?? null) ? $attributes['items'] : [];
$rows  = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    $label = trim((string) ($item['label'] ?? ''));
    $body  = (string) ($item['body'] ?? '');
    if ($label === '' || trim($body) === '') {
        continue;
    }
    $rows[] = ['label' => $label, 'body' => $body];
}
if (!$rows) {
    return '';
}

$uid = function_exists('wp_unique_id') ? wp_unique_id('bynefit-tabs-') : 'bynefit-tabs-' . substr(md5(serialize($rows)), 0, 8);

$tabs   = '';
$panels = '';
foreach ($rows as $i => $row) {
    $tabId   = $uid . '-tab-' . $i;
    $panelId = $uid . '-panel-' . $i;
    $tabs   .= '<button type="button" role="tab" id="' . esc_attr($tabId) . '"'
        . ' aria-controls="' . esc_attr($panelId) . '" class="bynefit-tabs__tab">'
        . esc_html($row['label']) . '</button>';
    $panels .= '<div role="tabpanel" id="' . esc_attr($panelId) . '"'
        . ' aria-labelledby="' . esc_attr($tabId) . '" class="bynefit-tabs__panel">'
        . wp_kses_post($row['body']) . '</div>';
}

$wrapper = get_block_wrapper_attributes(['class' => 'bynefit-tabs']);

printf(
    '<div %s data-bynefit-tabs><div class="bynefit-tabs__list" role="tablist">%s</div><div class="bynefit-tabs__panels">%s</div></div>',
    $wrapper,
    $tabs,
    $panels
);
