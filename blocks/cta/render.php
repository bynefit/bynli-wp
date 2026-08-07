<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/cta.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$title = (string) ($attributes['title'] ?? '');
$text  = (string) ($attributes['text'] ?? '');
$align = ($attributes['align'] ?? 'start') === 'center' ? 'center' : 'start';

$buttons = is_array($attributes['buttons'] ?? null) ? $attributes['buttons'] : [];
$btn_html = '';
$count = 0;
foreach ($buttons as $btn) {
    if (!is_array($btn) || $count >= 2) {
        continue;
    }
    $label = (string) ($btn['label'] ?? '');
    $href  = (string) ($btn['href'] ?? '');
    if (trim($label) === '' || trim($href) === '') {
        continue;
    }
    $variant = ($btn['variant'] ?? 'primary') === 'secondary' ? 'secondary' : 'primary';
    $btn_html .= '<a class="bynefit-cta__btn bynefit-cta__btn--' . $variant . '" href="' . esc_url($href) . '">' . esc_html($label) . '</a>';
    $count++;
}

if (trim($title) === '' && $btn_html === '') {
    return '';
}

$bg = Bynli_Connect_Blocks::token('color', $attributes['bg'] ?? null);
$style = $bg !== null ? '--bynefit-bg:' . $bg . ';' : '';

$wrapper = get_block_wrapper_attributes([
    'class' => 'bynefit-cta bynefit-cta--' . $align . ($bg !== null ? ' bynefit-cta--filled' : ''),
    'style' => $style,
]);

$body = '';
if ($title !== '') {
    $body .= '<p class="bynefit-cta__title">' . esc_html($title) . '</p>';
}
if ($text !== '') {
    $body .= '<p class="bynefit-cta__text">' . esc_html($text) . '</p>';
}
if ($btn_html !== '') {
    $body .= '<div class="bynefit-cta__actions">' . $btn_html . '</div>';
}

printf('<div %s>%s</div>', $wrapper, $body);
