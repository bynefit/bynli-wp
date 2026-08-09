<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/media.
 *
 * Emits publish-contract-compliant media: explicit width/height (kills CLS),
 * focal point as object-position, AVIF -> WebP -> original <picture> sources
 * when available, lazy below the fold but eager + fetchpriority=high for a
 * flagged LCP image (never lazy-load the LCP image).
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$url = isset($attributes['url']) ? esc_url_raw((string) $attributes['url']) : '';
if ($url === '') {
    return '';
}

$kind     = ($attributes['kind'] ?? 'image') === 'video' ? 'video' : 'image';
$alt      = isset($attributes['alt']) ? (string) $attributes['alt'] : '';
$width    = (isset($attributes['width']) && is_numeric($attributes['width'])) ? (int) $attributes['width'] : 0;
$height   = (isset($attributes['height']) && is_numeric($attributes['height'])) ? (int) $attributes['height'] : 0;
$priority = !empty($attributes['priority']);

$focal = is_array($attributes['focal'] ?? null) ? $attributes['focal'] : [];
$fx    = isset($focal['x']) && is_numeric($focal['x']) ? min(1.0, max(0.0, (float) $focal['x'])) : 0.5;
$fy    = isset($focal['y']) && is_numeric($focal['y']) ? min(1.0, max(0.0, (float) $focal['y'])) : 0.5;

$radius = Bynli_Connect_Blocks::token('radius', $attributes['radius'] ?? null);

$style = '--bynefit-focal:' . round($fx * 100, 2) . '% ' . round($fy * 100, 2) . '%;';
if ($radius !== null) {
    $style .= '--bynefit-radius:' . $radius . ';';
}

// CLS fallback (#51): the emitter contract supplies width+height, but when a
// caller omits either the element has no intrinsic ratio and height:100% has
// nothing to fill. Flag the wrapper so CSS reserves a default aspect ratio.
$has_dims = ($width > 0 && $height > 0);

$wrapper = get_block_wrapper_attributes([
    'class' => 'bynefit-media bynefit-media--' . $kind . ($has_dims ? '' : ' bynefit-media--nodims'),
    'style' => $style,
]);

$dims = ($width > 0 ? ' width="' . $width . '"' : '') . ($height > 0 ? ' height="' . $height . '"' : '');

if ($kind === 'video') {
    $poster = isset($attributes['poster']) ? esc_url_raw((string) $attributes['poster']) : '';
    $inner  = '<video class="bynefit-media__el" src="' . esc_url($url) . '"'
        . ($poster !== '' ? ' poster="' . esc_url($poster) . '"' : '')
        . $dims
        . ' playsinline muted loop' . ($priority ? '' : ' preload="none"') . '></video>';
    printf('<figure %s>%s</figure>', $wrapper, $inner);
    return;
}

$loading = $priority ? '' : ' loading="lazy"';
$fetch   = $priority ? ' fetchpriority="high"' : '';
$decode  = $priority ? '' : ' decoding="async"';

$img = '<img class="bynefit-media__el" src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '"'
    . $dims . $loading . $fetch . $decode . '>';

$sources = is_array($attributes['sources'] ?? null) ? $attributes['sources'] : [];
$picture = '';
foreach (['avif' => 'image/avif', 'webp' => 'image/webp'] as $ext => $mime) {
    if (!empty($sources[$ext]) && is_string($sources[$ext])) {
        $src = esc_url($sources[$ext]);
        if ($src !== '') {
            $picture .= '<source type="' . esc_attr($mime) . '" srcset="' . $src . '">';
        }
    }
}

$media = $picture !== ''
    ? '<picture>' . $picture . $img . '</picture>'
    : $img;

printf('<figure %s>%s</figure>', $wrapper, $media);
