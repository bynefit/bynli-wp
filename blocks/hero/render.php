<?php
if (!defined('ABSPATH')) {
    exit;
}

$heading = (string) ($attributes['heading'] ?? '');
if (trim($heading) === '') {
    return '';
}

$level = (isset($attributes['level']) && is_numeric($attributes['level'])) ? (int) $attributes['level'] : Bynli_Connect_Blocks::HERO_LEVEL_MIN;
if ($level < Bynli_Connect_Blocks::HERO_LEVEL_MIN || $level > Bynli_Connect_Blocks::HERO_LEVEL_MAX) {
    $level = Bynli_Connect_Blocks::HERO_LEVEL_MIN;
}
$align   = ($attributes['align'] ?? 'start') === 'center' ? 'center' : 'start';
$eyebrow = (string) ($attributes['eyebrow'] ?? '');
$text    = (string) ($attributes['text'] ?? '');

$bg_media = is_array($attributes['bgMedia'] ?? null) ? $attributes['bgMedia'] : null;
$bg_layer = $bg_media !== null
    ? Bynli_Connect_Blocks::bg_media_html($bg_media, 'hero', !empty($attributes['priority']))
    : '';
$framed = $bg_layer !== '';

$scrim = '';
if ($framed) {
    $overlay = is_array($attributes['overlay'] ?? null) ? $attributes['overlay'] : [];
    $scrim   = Bynli_Connect_Blocks::scrim_html(
        $overlay,
        'hero',
        Bynli_Connect_Blocks::HERO_OVERLAY_DEFAULT,
        Bynli_Connect_Blocks::SCRIM_FALLBACK
    );
}

$vars = [];
$minh_map = Bynli_Connect_Blocks::MIN_HEIGHTS;
$min_key  = $attributes['minHeight'] ?? '';
if (is_string($min_key) && isset($minh_map[$min_key])) {
    $vars['--bynefit-minh'] = $minh_map[$min_key];
}
$fg = Bynli_Connect_Blocks::token('color', $attributes['color'] ?? null);
if ($fg === null && $framed) {
    $fg = Bynli_Connect_Blocks::HERO_FG_FALLBACK;
}
if ($fg !== null) {
    $vars['--bynefit-hero-fg'] = $fg;
}
$style = '';
foreach ($vars as $prop => $val) {
    $style .= $prop . ':' . $val . ';';
}

$buttons  = is_array($attributes['buttons'] ?? null) ? $attributes['buttons'] : [];
$btn_html = Bynli_Connect_Blocks::action_buttons_html($buttons, 'hero');

$body = '';
if (trim($eyebrow) !== '') {
    $body .= '<p class="bynefit-hero__eyebrow">' . esc_html($eyebrow) . '</p>';
}
$body .= '<h' . $level . ' class="bynefit-hero__heading">' . esc_html($heading) . '</h' . $level . '>';
if (trim($text) !== '') {
    $body .= '<p class="bynefit-hero__text">' . esc_html($text) . '</p>';
}
if ($btn_html !== '') {
    $body .= '<div class="bynefit-hero__actions">' . $btn_html . '</div>';
}

$wrapper = get_block_wrapper_attributes([
    'class' => 'bynefit-hero bynefit-hero--' . $align . ($framed ? ' bynefit-hero--framed' : ''),
    'style' => $style,
]);

printf('<div %s>%s%s<div class="bynefit-hero__content">%s</div></div>', $wrapper, $bg_layer, $scrim, $body);
