<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/carousel.
 *
 * Progressive enhancement: all slides render visible (stacked) so the content
 * is readable with no JS; assets/blocks.js turns it into a one-at-a-time
 * carousel (prev/next/dots, aria-live, autoplay only under
 * prefers-reduced-motion: no-preference, paused on hover/focus).
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$items  = is_array($attributes['items'] ?? null) ? $attributes['items'] : [];
$slides = [];
foreach ($items as $item) {
    if (is_array($item) && trim((string) ($item['text'] ?? '')) !== '') {
        $slides[] = $item;
    }
}
if (!$slides) {
    return '';
}

$total    = count($slides);
$autoplay = !empty($attributes['autoplay']);
$uid      = function_exists('wp_unique_id') ? wp_unique_id('bynefit-carousel-') : 'bynefit-carousel';

$track = '';
$dots  = '';
foreach ($slides as $i => $s) {
    $avatar = is_array($s['avatar'] ?? null) ? $s['avatar'] : [];
    $aurl   = isset($avatar['url']) ? esc_url((string) $avatar['url']) : '';
    $aw     = (isset($avatar['width']) && is_numeric($avatar['width'])) ? (int) $avatar['width'] : 0;
    $ah     = (isset($avatar['height']) && is_numeric($avatar['height'])) ? (int) $avatar['height'] : 0;

    $foot = '';
    $cite = trim((string) ($s['cite'] ?? ''));
    $role = trim((string) ($s['role'] ?? ''));
    if ($cite !== '' || $role !== '' || $aurl !== '') {
        $av = $aurl !== ''
            ? '<img class="bynefit-carousel__avatar" src="' . $aurl . '" alt=""'
                . ($aw > 0 ? ' width="' . $aw . '"' : '') . ($ah > 0 ? ' height="' . $ah . '"' : '')
                . ' loading="lazy" decoding="async">'
            : '';
        $who = ($cite !== '' ? '<span class="bynefit-carousel__cite">' . esc_html($cite) . '</span>' : '')
             . ($role !== '' ? '<span class="bynefit-carousel__role">' . esc_html($role) . '</span>' : '');
        $foot = '<figcaption class="bynefit-carousel__foot">' . $av . '<span class="bynefit-carousel__who">' . $who . '</span></figcaption>';
    }

    $track .= '<figure class="bynefit-carousel__slide" role="group" aria-roledescription="slide"'
        . ' aria-label="' . esc_attr(($i + 1) . ' / ' . $total) . '">'
        . '<blockquote class="bynefit-carousel__text">' . esc_html((string) $s['text']) . '</blockquote>'
        . $foot . '</figure>';

    $dots .= '<button type="button" class="bynefit-carousel__dot" aria-label="'
        . esc_attr('Go to slide ' . ($i + 1)) . '"></button>';
}

$wrapper = get_block_wrapper_attributes(['class' => 'bynefit-carousel']);
$controls = $total > 1
    ? '<button type="button" class="bynefit-carousel__nav bynefit-carousel__prev" aria-label="Previous">&#8249;</button>'
        . '<button type="button" class="bynefit-carousel__nav bynefit-carousel__next" aria-label="Next">&#8250;</button>'
        . '<div class="bynefit-carousel__dots">' . $dots . '</div>'
    : '';

printf(
    '<div %s data-bynefit-carousel%s><div class="bynefit-carousel__track" aria-live="polite">%s</div>%s</div>',
    $wrapper,
    $autoplay ? ' data-autoplay="1"' : '',
    $track,
    $controls
);
