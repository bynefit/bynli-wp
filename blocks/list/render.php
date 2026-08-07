<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Server render for wp:bynefit/list.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

$items = is_array($attributes['items'] ?? null) ? $attributes['items'] : [];
if (!$items) {
    return '';
}

$marker = (string) ($attributes['marker'] ?? 'check');
if (!in_array($marker, ['check', 'arrow', 'dot', 'none'], true)) {
    $marker = 'check';
}
$default_icon = ['check' => 'check-circle', 'arrow' => 'arrow-right'];

$color = Bynli_Connect_Blocks::token('color', $attributes['color'] ?? null);
$style = $color !== null ? 'color:' . $color . ';' : '';

$wrapper = get_block_wrapper_attributes([
    'class' => 'bynefit-list bynefit-list--' . $marker,
    'style' => $style,
]);

$rows = '';
foreach ($items as $item) {
    if (is_array($item)) {
        $text = (string) ($item['text'] ?? '');
        $icon = (string) ($item['icon'] ?? '');
    } else {
        $text = (string) $item;
        $icon = '';
    }
    if (trim($text) === '') {
        continue;
    }

    $glyph = '';
    if ($marker !== 'none' && $marker !== 'dot') {
        $icon_name = $icon !== '' ? $icon : ($default_icon[$marker] ?? 'check-circle');
        $svg = Bynli_Connect_Blocks::icon_svg($icon_name, 20);
        if ($svg !== null) {
            $glyph = '<span class="bynefit-list__marker">' . $svg . '</span>';
        }
    }

    $rows .= '<li class="bynefit-list__item">' . $glyph . '<span class="bynefit-list__text">' . esc_html($text) . '</span></li>';
}

if ($rows === '') {
    return '';
}

printf('<ul %s>%s</ul>', $wrapper, $rows);
