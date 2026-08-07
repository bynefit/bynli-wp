<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/callout.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$variant = (string) ($attributes['variant'] ?? 'info');
if (!in_array($variant, ['info', 'success', 'warn', 'tip'], true)) {
    $variant = 'info';
}
$title = (string) ($attributes['title'] ?? '');
$text  = (string) ($attributes['text'] ?? '');
if (trim($title) === '' && trim($text) === '') {
    return '';
}

$icon_for = [
    'info'    => 'info',
    'success' => 'check-circle',
    'warn'    => 'alert-triangle',
    'tip'     => 'lightbulb',
];
$svg = Bynli_Connect_Blocks::icon_svg($icon_for[$variant], 20);

$wrapper = get_block_wrapper_attributes([
    'class' => 'bynefit-callout bynefit-callout--' . $variant,
]);

$body = '';
if ($title !== '') {
    $body .= '<p class="bynefit-callout__title">' . esc_html($title) . '</p>';
}
if ($text !== '') {
    $body .= '<p class="bynefit-callout__text">' . esc_html($text) . '</p>';
}

printf(
    '<aside %s role="note"><span class="bynefit-callout__icon">%s</span><div class="bynefit-callout__body">%s</div></aside>',
    $wrapper,
    $svg ?? '',
    $body
);
