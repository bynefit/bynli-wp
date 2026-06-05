<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Shortcode handlers for Bynli Connect v0.2.
 *
 * Each shortcode emits the same `data-bynli` HTML that a Bynli-hosted
 * team site uses. The bynli.js loader is enqueued exactly once per
 * page, only if at least one shortcode was rendered, so pages without
 * Bynli content stay un-tagged.
 *
 *   [bynli-form id="frm_xxx"]            One-line embed of a Bynli form
 *   [bynli-form id="frm_xxx" style="default" success="Thanks!"]
 *
 *   [bynli-modal label="..." title="..." body="..." href="..."]
 *                                         Click-to-open modal trigger
 *
 *   [bynli-confirm label="..." message="..." href="..."]
 *                                         Confirm-before-navigation prompt
 *
 *   [bynli-toast message="..." kind="success"]
 *                                         Fires a toast on page load
 *
 *   [bynli-widget team="acme"]            Floating Bynli widget bubble
 *
 * For each shortcode, attribute names map directly onto the
 * data-* attributes the team-site builders read — keeps the contract
 * single-source-of-truth across all hosts.
 */
class Bynli_Connect_Shortcodes {

    const LOADER_URL = 'https://bynli.com/sites/bynli.js';

    private static $loader_needed = false;

    public function __construct() {
        add_shortcode('bynli-form',    [$this, 'render_form']);
        add_shortcode('bynli-modal',   [$this, 'render_modal']);
        add_shortcode('bynli-confirm', [$this, 'render_confirm']);
        add_shortcode('bynli-toast',   [$this, 'render_toast']);
        add_shortcode('bynli-widget',  [$this, 'render_widget']);

        // Enqueue the loader on wp_footer ONLY if a shortcode marked it
        // needed during page render.
        add_action('wp_footer', [$this, 'maybe_emit_loader'], 5);
    }

    public function maybe_emit_loader(): void {
        if (!self::$loader_needed) return;
        printf(
            '<script src="%s" async></script>' . "\n",
            esc_url(self::LOADER_URL)
        );
    }

    private function need_loader(): void {
        self::$loader_needed = true;
    }

    // ── Form ────────────────────────────────────────────────────────
    public function render_form($atts = []): string {
        $a = shortcode_atts([
            'id'           => '',
            'style'        => 'default',   // default | bootstrap | bare
            'success'      => '',           // success message
            'success_mode' => '',           // toast | replace | hide
        ], (array) $atts, 'bynli-form');

        if (!preg_match('/^frm_[A-Za-z0-9_\-]{6,40}$/', (string) $a['id'])) {
            return '<!-- bynli-form: bad or missing id -->';
        }
        $this->need_loader();

        $attrs  = sprintf(' data-bynli="form" data-form-id="%s"', esc_attr($a['id']));
        $attrs .= ' data-form-style="' . esc_attr($a['style']) . '"';
        if ($a['success']      !== '') $attrs .= ' data-form-success="'      . esc_attr($a['success']) . '"';
        if ($a['success_mode'] !== '') $attrs .= ' data-form-success-mode="' . esc_attr($a['success_mode']) . '"';

        return '<div' . $attrs . '></div>';
    }

    // ── Modal ───────────────────────────────────────────────────────
    public function render_modal($atts = []): string {
        $a = shortcode_atts([
            'label'   => 'Open',
            'title'   => '',
            'body'    => '',
            'confirm' => '',
            'cancel'  => '',
            'href'    => '',
        ], (array) $atts, 'bynli-modal');
        $this->need_loader();

        $attrs  = ' data-bynli="modal"';
        if ($a['title']   !== '') $attrs .= ' data-modal-title="'   . esc_attr($a['title'])   . '"';
        if ($a['body']    !== '') $attrs .= ' data-modal-body="'    . esc_attr($a['body'])    . '"';
        if ($a['confirm'] !== '') $attrs .= ' data-modal-confirm="' . esc_attr($a['confirm']) . '"';
        if ($a['cancel']  !== '') $attrs .= ' data-modal-cancel="'  . esc_attr($a['cancel'])  . '"';
        if ($a['href']    !== '') $attrs .= ' data-modal-href="'    . esc_url($a['href'])     . '"';

        return '<button type="button"' . $attrs . '>' . esc_html($a['label']) . '</button>';
    }

    // ── Confirm ─────────────────────────────────────────────────────
    public function render_confirm($atts = []): string {
        $a = shortcode_atts([
            'label'   => 'Continue',
            'message' => 'Are you sure?',
            'yes'     => '',
            'no'      => '',
            'href'    => '',
            'danger'  => '',
        ], (array) $atts, 'bynli-confirm');
        $this->need_loader();

        $attrs  = ' data-bynli="confirm"';
        $attrs .= ' data-confirm-message="' . esc_attr($a['message']) . '"';
        if ($a['yes']    !== '') $attrs .= ' data-confirm-yes="'    . esc_attr($a['yes'])  . '"';
        if ($a['no']     !== '') $attrs .= ' data-confirm-no="'     . esc_attr($a['no'])   . '"';
        if ($a['href']   !== '') $attrs .= ' data-confirm-href="'   . esc_url($a['href'])  . '"';
        if ($a['danger'] !== '') $attrs .= ' data-confirm-danger="1"';

        return '<button type="button"' . $attrs . '>' . esc_html($a['label']) . '</button>';
    }

    // ── Toast ───────────────────────────────────────────────────────
    public function render_toast($atts = []): string {
        $a = shortcode_atts([
            'message' => '',
            'kind'    => 'info',   // info | success | error | warning
            'on'      => 'load',   // load | click (load fires automatically)
            'label'   => '',       // only relevant when on=click
        ], (array) $atts, 'bynli-toast');

        if ($a['message'] === '') return '<!-- bynli-toast: empty message -->';
        $this->need_loader();

        $attrs  = ' data-bynli="toast"';
        $attrs .= ' data-toast="'      . esc_attr($a['message']) . '"';
        $attrs .= ' data-toast-kind="' . esc_attr($a['kind'])    . '"';
        $attrs .= ' data-toast-on="'   . esc_attr($a['on'])      . '"';

        // on="load": invisible span auto-fires on page load.
        // on="click": labeled button user has to press.
        if ($a['on'] === 'click') {
            $label = $a['label'] !== '' ? $a['label'] : 'Show';
            return '<button type="button"' . $attrs . '>' . esc_html($label) . '</button>';
        }
        return '<span aria-hidden="true" style="display:none"' . $attrs . '></span>';
    }

    // ── Widget (floating bubble) ────────────────────────────────────
    public function render_widget($atts = []): string {
        $a = shortcode_atts([
            'team'     => '',
            'position' => '',
            'label'    => '',
        ], (array) $atts, 'bynli-widget');

        if (!preg_match('/^[a-z0-9\-]{3,100}$/', (string) $a['team'])) {
            return '<!-- bynli-widget: bad or missing team slug -->';
        }

        // The legacy widget.js loads independently — it is NOT the same
        // file as bynli.js. We do not flag self::$loader_needed here
        // because this widget brings its own loader.
        $attrs  = ' src="' . esc_url('https://bynli.com/widget.js') . '"';
        $attrs .= ' data-team="' . esc_attr($a['team']) . '"';
        if ($a['position'] !== '') $attrs .= ' data-position="' . esc_attr($a['position']) . '"';
        if ($a['label']    !== '') $attrs .= ' data-label="'    . esc_attr($a['label'])    . '"';
        $attrs .= ' async';

        return '<script' . $attrs . '></script>';
    }
}
