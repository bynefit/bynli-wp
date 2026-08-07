<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/embed.
 *
 * The iframe src is CONSTRUCTED here from an allow-listed provider + a
 * per-provider-validated id — never taken as a raw URL from the payload — so a
 * managed page can't be turned into an arbitrary-origin iframe injection.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$provider = (string) ($attributes['provider'] ?? '');
$id       = (string) ($attributes['id'] ?? '');
$title    = (string) ($attributes['title'] ?? '');

$ratio = (string) ($attributes['ratio'] ?? '16-9');
if (!in_array($ratio, ['16-9', '4-3', '1-1', '21-9'], true)) {
    $ratio = '16-9';
}

$src   = '';
$allow = '';
switch ($provider) {
    case 'youtube':
        if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
            $src   = 'https://www.youtube-nocookie.com/embed/' . $id;
            $allow = 'accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
        }
        break;
    case 'vimeo':
        if (ctype_digit($id)) {
            $src   = 'https://player.vimeo.com/video/' . $id;
            $allow = 'fullscreen; picture-in-picture';
        }
        break;
    case 'map':
        if (trim($id) !== '') {
            $src = 'https://maps.google.com/maps?q=' . rawurlencode($id) . '&output=embed';
        }
        break;
}

if ($src === '') {
    return '';
}

$wrapper = get_block_wrapper_attributes(['class' => 'bynefit-embed bynefit-embed--r-' . $ratio]);

$iframe = '<iframe class="bynefit-embed__frame" src="' . esc_url($src) . '" title="' . esc_attr($title) . '"'
    . ' loading="lazy" referrerpolicy="strict-origin-when-cross-origin"'
    . ($allow !== '' ? ' allow="' . esc_attr($allow) . '" allowfullscreen' : '')
    . '></iframe>';

printf('<div %s>%s</div>', $wrapper, $iframe);
