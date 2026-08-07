<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/quote.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$text = isset($attributes['text']) ? (string) $attributes['text'] : '';
if (trim($text) === '') {
    return '';
}

$cite  = (string) ($attributes['cite'] ?? '');
$role  = (string) ($attributes['role'] ?? '');
$align = ($attributes['align'] ?? 'start') === 'center' ? 'center' : 'start';

$avatar = is_array($attributes['avatar'] ?? null) ? $attributes['avatar'] : [];
$aurl   = isset($avatar['url']) ? esc_url((string) $avatar['url']) : '';
$aw     = (isset($avatar['width']) && is_numeric($avatar['width'])) ? (int) $avatar['width'] : 0;
$ah     = (isset($avatar['height']) && is_numeric($avatar['height'])) ? (int) $avatar['height'] : 0;

$wrapper = get_block_wrapper_attributes(['class' => 'bynefit-quote bynefit-quote--' . $align]);

$foot = '';
if ($cite !== '' || $role !== '' || $aurl !== '') {
    $avatar_html = '';
    if ($aurl !== '') {
        $adims = ($aw > 0 ? ' width="' . $aw . '"' : '') . ($ah > 0 ? ' height="' . $ah . '"' : '');
        $avatar_html = '<img class="bynefit-quote__avatar" src="' . $aurl . '" alt=""' . $adims . ' loading="lazy" decoding="async">';
    }
    $who = '';
    if ($cite !== '') {
        $who .= '<span class="bynefit-quote__cite">' . esc_html($cite) . '</span>';
    }
    if ($role !== '') {
        $who .= '<span class="bynefit-quote__role">' . esc_html($role) . '</span>';
    }
    $foot = '<figcaption class="bynefit-quote__foot">' . $avatar_html . '<span class="bynefit-quote__who">' . $who . '</span></figcaption>';
}

printf(
    '<figure %s><blockquote class="bynefit-quote__text">%s</blockquote>%s</figure>',
    $wrapper,
    esc_html($text),
    $foot
);
