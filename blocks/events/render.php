<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/events.
 *
 * Emits the data-bynli="events" reference the Bynli loader hydrates from the
 * team's live event data. Same contract as the [bynli-events] shortcode.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$team = strtolower((string) ($attributes['team'] ?? ''));
if (!preg_match('/^[a-z0-9\-]{3,100}$/', $team)) {
    return '';
}

$style = in_array($attributes['style'] ?? 'cards', ['cards', 'list', 'bare'], true) ? $attributes['style'] : 'cards';
$scope = in_array($attributes['scope'] ?? 'upcoming', ['upcoming', 'past'], true) ? $attributes['scope'] : 'upcoming';
$limit = (isset($attributes['limit']) && is_numeric($attributes['limit'])) ? max(1, min(50, (int) $attributes['limit'])) : 5;

Bynli_Connect_Shortcodes::require_loader();

$embed  = '<div data-bynli="events"';
$embed .= ' data-team="' . esc_attr($team) . '"';
$embed .= ' data-limit="' . esc_attr((string) $limit) . '"';
$embed .= ' data-style="' . esc_attr($style) . '"';
$embed .= ' data-scope="' . esc_attr($scope) . '"';
$embed .= '></div>';

$wrapper = get_block_wrapper_attributes(['class' => 'bynefit-events']);

printf('<div %s>%s</div>', $wrapper, $embed);
