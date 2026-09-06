<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/gallery.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$items = is_array($attributes['items'] ?? null) ? $attributes['items'] : [];
if (!$items) {
    return '';
}

$cols = is_array($attributes['columns'] ?? null) ? $attributes['columns'] : [];
$vars = [
    '--bynefit-gcols-sm' => (string) Bynli_Connect_Blocks::grid_int(
        $cols['sm'] ?? null,
        Bynli_Connect_Blocks::GRID_COLS_MIN,
        Bynli_Connect_Blocks::GALLERY_COLS_MAX,
        Bynli_Connect_Blocks::GALLERY_COLS_SM_DEFAULT
    ),
    '--bynefit-gcols-lg' => (string) Bynli_Connect_Blocks::grid_int(
        $cols['lg'] ?? null,
        Bynli_Connect_Blocks::GRID_COLS_MIN,
        Bynli_Connect_Blocks::GALLERY_COLS_MAX,
        Bynli_Connect_Blocks::GALLERY_COLS_LG_DEFAULT
    ),
];
$gap = Bynli_Connect_Blocks::token('spacing', $attributes['gap'] ?? null);
if ($gap !== null) {
    $vars['--bynefit-gap'] = $gap;
}
$radius = Bynli_Connect_Blocks::token('radius', $attributes['radius'] ?? null);
if ($radius !== null) {
    $vars['--bynefit-radius'] = $radius;
}

$style = '';
foreach ($vars as $prop => $val) {
    $style .= $prop . ':' . $val . ';';
}
$wrapper = get_block_wrapper_attributes(['class' => 'bynefit-gallery', 'style' => $style]);

$figs = '';
foreach ($items as $item) {
    if (!is_array($item)) {
        continue;
    }
    $url = isset($item['url']) ? esc_url((string) $item['url']) : '';
    if ($url === '') {
        continue;
    }
    $alt = esc_attr((string) ($item['alt'] ?? ''));
    $w   = (isset($item['width']) && is_numeric($item['width'])) ? (int) $item['width'] : 0;
    $h   = (isset($item['height']) && is_numeric($item['height'])) ? (int) $item['height'] : 0;
    $dims = ($w > 0 ? ' width="' . $w . '"' : '') . ($h > 0 ? ' height="' . $h . '"' : '');

    $focal = is_array($item['focal'] ?? null) ? $item['focal'] : [];
    $fx = isset($focal['x']) && is_numeric($focal['x']) ? min(1.0, max(0.0, (float) $focal['x'])) : 0.5;
    $fy = isset($focal['y']) && is_numeric($focal['y']) ? min(1.0, max(0.0, (float) $focal['y'])) : 0.5;
    $istyle = '--bynefit-focal:' . round($fx * 100, 2) . '% ' . round($fy * 100, 2) . '%;';

    $img = '<img class="bynefit-gallery__img" src="' . $url . '" alt="' . $alt . '"' . $dims . ' loading="lazy" decoding="async">';

    $sources = is_array($item['sources'] ?? null) ? $item['sources'] : [];
    $picture = '';
    foreach (['avif' => 'image/avif', 'webp' => 'image/webp'] as $ext => $mime) {
        if (!empty($sources[$ext]) && is_string($sources[$ext])) {
            $src = esc_url($sources[$ext]);
            if ($src !== '') {
                $picture .= '<source type="' . esc_attr($mime) . '" srcset="' . $src . '">';
            }
        }
    }
    $media = $picture !== '' ? '<picture>' . $picture . $img . '</picture>' : $img;

    $figs .= '<figure class="bynefit-gallery__item" style="' . esc_attr($istyle) . '">' . $media . '</figure>';
}

if ($figs === '') {
    return '';
}

printf('<div %s>%s</div>', $wrapper, $figs);
