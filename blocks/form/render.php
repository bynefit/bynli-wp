<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/form.
 *
 * Emits only the data-bynli="form" reference the Bynli loader hydrates — the
 * form itself renders from the Bynli form builder and submits to Bynli, so no
 * submission data is ever handled or stored by this WordPress site. Only a
 * strictly-validated form id is accepted; no markup comes from the payload.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$form_id = (string) ($attributes['formId'] ?? '');
if (!preg_match('/^frm_[A-Za-z0-9_\-]{6,40}$/', $form_id)) {
    return '';
}

$style = in_array($attributes['style'] ?? 'default', ['default', 'bootstrap', 'bare'], true)
    ? $attributes['style']
    : 'default';
$success      = (string) ($attributes['success'] ?? '');
$success_mode = in_array($attributes['successMode'] ?? '', ['toast', 'replace', 'hide'], true)
    ? $attributes['successMode']
    : '';

Bynli_Connect_Shortcodes::require_loader();

$embed  = '<div class="bynefit-form__embed" data-bynli="form" data-form-id="' . esc_attr($form_id) . '"';
$embed .= ' data-form-style="' . esc_attr($style) . '"';
if ($success !== '') {
    $embed .= ' data-form-success="' . esc_attr($success) . '"';
}
if ($success_mode !== '') {
    $embed .= ' data-form-success-mode="' . esc_attr($success_mode) . '"';
}
$embed .= '></div>';
$embed .= '<noscript><span class="bynefit-form__noscript">' . esc_html__('This form needs JavaScript to load.', 'bynli-connect') . '</span></noscript>';

$card    = !isset($attributes['card']) || !empty($attributes['card']);
$wrapper = get_block_wrapper_attributes([
    'class' => 'bynefit-form' . ($card ? ' bynefit-form--card' : ''),
]);

printf('<div %s>%s</div>', $wrapper, $embed);
