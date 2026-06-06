<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Bynli_Connect_Tickets — WP-admin surface for the team's Bynli support
 * tickets. v0.5 read-only (bynli#1208) — list view + thread reader. Reply
 * + resolve come in v0.6 via separate POST endpoints.
 *
 * Registers as a submenu page under the existing Settings → Bynli Connect
 * entry (keeps the plugin's admin footprint to one parent). The page reads
 * from /api/site-host/tickets and /api/site-host/tickets/{ref} using the
 * existing site-host key — no extra credentials to set up.
 */
class Bynli_Connect_Tickets {

    const MENU_SLUG       = 'bynli-connect-tickets';
    const NONCE_REPLY     = 'bynli_connect_ticket_reply';
    const NONCE_RESOLVE   = 'bynli_connect_ticket_resolve';
    const NONCE_NEW       = 'bynli_connect_ticket_new';

    public function __construct() {
        add_action('admin_menu',                                [$this, 'register_menu'], 11);
        add_action('admin_post_bynli_connect_ticket_reply',     [$this, 'handle_reply']);
        add_action('admin_post_bynli_connect_ticket_resolve',   [$this, 'handle_resolve']);
        add_action('admin_post_bynli_connect_ticket_new',       [$this, 'handle_new']);
    }

    public function register_menu(): void {
        // Submenu under Settings → so the plugin doesn't sprout a second
        // top-level menu. The settings page itself is also under Settings;
        // both share the same admin parent for predictable navigation.
        add_options_page(
            'Bynli Support Tickets',
            'Bynli Tickets',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    /**
     * POST handler — site-attributed reply on a ticket. (bynli#1208 v0.6)
     * Wired to admin-post.php so we get a real WP nonce on the form.
     * Redirects back to the detail view with a flash flag in the query.
     */
    public function handle_reply(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden.', 403);

        $ref  = isset($_POST['ticket_ref']) ? sanitize_text_field((string)$_POST['ticket_ref']) : '';
        $body = isset($_POST['reply_body']) ? wp_unslash((string)$_POST['reply_body']) : '';
        $body = trim($body);

        if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $ref)) {
            wp_die('Invalid ticket reference.', 400);
        }
        check_admin_referer(self::NONCE_REPLY . '_' . $ref);

        $base = admin_url('options-general.php?page=' . self::MENU_SLUG . '&ticket_ref=' . rawurlencode($ref));

        if ($body === '') {
            wp_safe_redirect(add_query_arg(['result' => 'empty'], $base));
            exit;
        }

        // Attribute the reply to the WP user who clicked send so Bynli
        // can label it on the thread + route follow-up support emails
        // back to them, even if they have no Bynli account.
        // (bynli#1208 followup — bynli#1249)
        $payload = ['body' => $body];
        $wp_user = wp_get_current_user();
        if ($wp_user && $wp_user->exists()) {
            if (!empty($wp_user->user_email))   $payload['wp_user_email'] = (string)$wp_user->user_email;
            if (!empty($wp_user->display_name)) $payload['wp_user_name']  = (string)$wp_user->display_name;
        }

        $res = Bynli_Connect_Api::post('/api/site-host/tickets/' . rawurlencode($ref) . '/reply', $payload);
        $args = ['result' => $res['ok'] ? 'replied' : 'reply_failed'];
        if (!$res['ok']) $args['err'] = rawurlencode((string)($res['message'] ?? 'unknown'));
        wp_safe_redirect(add_query_arg($args, $base));
        exit;
    }

    /**
     * POST handler — site-attributed resolve. Optional "note" posts a final
     * reply alongside the resolve flip on the server.
     */
    public function handle_resolve(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden.', 403);

        $ref  = isset($_POST['ticket_ref']) ? sanitize_text_field((string)$_POST['ticket_ref']) : '';
        $note = isset($_POST['resolve_note']) ? trim(wp_unslash((string)$_POST['resolve_note'])) : '';

        if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $ref)) {
            wp_die('Invalid ticket reference.', 400);
        }
        check_admin_referer(self::NONCE_RESOLVE . '_' . $ref);

        $payload = $note !== '' ? ['note' => $note] : [];
        // WP user identity attached to the optional final note + the
        // resolve audit log on Bynli. Same plumbing as handle_reply.
        $wp_user = wp_get_current_user();
        if ($wp_user && $wp_user->exists()) {
            if (!empty($wp_user->user_email))   $payload['wp_user_email'] = (string)$wp_user->user_email;
            if (!empty($wp_user->display_name)) $payload['wp_user_name']  = (string)$wp_user->display_name;
        }

        $res = Bynli_Connect_Api::post('/api/site-host/tickets/' . rawurlencode($ref) . '/resolve', $payload);

        $base = admin_url('options-general.php?page=' . self::MENU_SLUG . '&ticket_ref=' . rawurlencode($ref));
        $args = ['result' => $res['ok'] ? 'resolved' : 'resolve_failed'];
        if (!$res['ok']) $args['err'] = rawurlencode((string)($res['message'] ?? 'unknown'));
        wp_safe_redirect(add_query_arg($args, $base));
        exit;
    }

    /**
     * POST handler — open a new ticket from wp-admin. (bynli#1208 v0.7)
     * Site-attributed via the host key; subject/body/category come from
     * the form. Categories are restricted to the four the server accepts
     * from a host-key flow (technical / billing / general / account).
     */
    public function handle_new(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden.', 403);

        check_admin_referer(self::NONCE_NEW);

        $subject  = isset($_POST['ticket_subject']) ? trim(wp_unslash((string)$_POST['ticket_subject'])) : '';
        $body     = isset($_POST['ticket_body'])    ? trim(wp_unslash((string)$_POST['ticket_body']))    : '';
        $category = isset($_POST['ticket_category']) ? sanitize_text_field((string)$_POST['ticket_category']) : 'general';

        $allowed_cats = ['technical', 'billing', 'general', 'account'];
        if (!in_array($category, $allowed_cats, true)) $category = 'general';

        $list_base = admin_url('options-general.php?page=' . self::MENU_SLUG);

        if ($subject === '' || mb_strlen($subject) < 3) {
            wp_safe_redirect(add_query_arg(['new' => '1', 'result' => 'empty_subject'], $list_base));
            exit;
        }
        if ($body === '') {
            wp_safe_redirect(add_query_arg(['new' => '1', 'result' => 'empty_body'], $list_base));
            exit;
        }

        $payload = [
            'subject'  => $subject,
            'body'     => $body,
            'category' => $category,
        ];
        $wp_user = wp_get_current_user();
        if ($wp_user && $wp_user->exists()) {
            if (!empty($wp_user->user_email))   $payload['wp_user_email'] = (string)$wp_user->user_email;
            if (!empty($wp_user->display_name)) $payload['wp_user_name']  = (string)$wp_user->display_name;
        }

        $res = Bynli_Connect_Api::post('/api/site-host/tickets', $payload);

        if (!$res['ok']) {
            $args = ['new' => '1', 'result' => 'open_failed'];
            $args['err'] = rawurlencode((string)($res['message'] ?? 'unknown'));
            wp_safe_redirect(add_query_arg($args, $list_base));
            exit;
        }

        $ref = (string)($res['data']['ticket_ref'] ?? '');
        if ($ref !== '' && preg_match('/^[A-Za-z0-9_-]{3,64}$/', $ref)) {
            wp_safe_redirect(add_query_arg(
                ['ticket_ref' => $ref, 'result' => 'opened'],
                $list_base
            ));
        } else {
            wp_safe_redirect(add_query_arg(['result' => 'opened'], $list_base));
        }
        exit;
    }

    /** Top-level renderer — routes to list or detail based on ?ticket_ref=. */
    public function render(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden.', 403);

        $key_configured = Bynli_Connect_Settings::key() !== '';
        if (!$key_configured) {
            $this->render_unconfigured();
            return;
        }

        $ref = isset($_GET['ticket_ref']) ? sanitize_text_field((string)$_GET['ticket_ref']) : '';
        if ($ref !== '' && preg_match('/^[A-Za-z0-9_-]{3,64}$/', $ref)) {
            $this->render_detail($ref);
            return;
        }

        $this->render_list();
    }

    // ── States ─────────────────────────────────────────────────────

    private function render_unconfigured(): void {
        ?>
        <div class="wrap bcn-wrap">
            <h1>Bynli Support Tickets</h1>
            <div class="bcn-notice bcn-notice-warn" style="margin-top:16px">
                <span class="dashicons dashicons-warning"></span>
                <span>
                    No Bynli site-host key configured yet —
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=' . Bynli_Connect_Settings::MENU_SLUG)); ?>">finish setup</a>
                    first so this site can read your team's tickets.
                </span>
            </div>
        </div>
        <?php
    }

    private function render_list(): void {
        $status = isset($_GET['status']) ? sanitize_text_field((string)$_GET['status']) : 'open';
        if (!in_array($status, ['open', 'resolved', 'in_progress', 'all'], true)) $status = 'open';

        $res = Bynli_Connect_Api::get('/api/site-host/tickets', ['status' => $status]);

        $flash_result = isset($_GET['result']) ? sanitize_text_field((string)$_GET['result']) : '';
        $flash_err    = isset($_GET['err'])    ? sanitize_text_field(urldecode((string)$_GET['err'])) : '';
        $form_open    = (isset($_GET['new']) && $_GET['new'] === '1') || in_array($flash_result, ['empty_subject', 'empty_body', 'open_failed'], true);

        // Repopulate the form when a validation redirect bounced us back so
        // the WP admin doesn't lose what they typed.
        $sticky_subject  = isset($_POST['ticket_subject'])  ? (string)wp_unslash($_POST['ticket_subject'])  : '';
        $sticky_body     = isset($_POST['ticket_body'])     ? (string)wp_unslash($_POST['ticket_body'])     : '';
        $sticky_category = isset($_POST['ticket_category']) ? sanitize_text_field((string)$_POST['ticket_category']) : 'general';

        ?>
        <div class="wrap bcn-wrap bcn-tickets">
            <header class="bcn-header">
                <div class="bcn-brand">
                    <span class="bcn-wordmark">Bynli<span class="bcn-wordmark-dot">.</span></span>
                    <span class="bcn-divider-v" aria-hidden="true"></span>
                    <span class="bcn-product">Support tickets</span>
                </div>
                <a class="bcn-btn" href="<?php echo esc_url(add_query_arg(['new' => '1'], menu_page_url(self::MENU_SLUG, false))); ?>#bcn-new-ticket">
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <?php esc_html_e('Open new ticket', 'bynli-connect'); ?>
                </a>
            </header>

            <?php
            $list_flash_map = [
                'opened'        => ['ok',   __('Ticket opened. Bynli support will be notified.', 'bynli-connect')],
                'empty_subject' => ['warn', __('Subject is required (at least 3 characters).', 'bynli-connect')],
                'empty_body'    => ['warn', __('Message is required.', 'bynli-connect')],
                'open_failed'   => ['err',  __('Could not open the ticket.', 'bynli-connect')],
            ];
            if (isset($list_flash_map[$flash_result])):
                list($kind, $msg) = $list_flash_map[$flash_result];
                $cls = $kind === 'ok' ? 'bcn-notice-ok' : ($kind === 'warn' ? 'bcn-notice-warn' : 'bcn-notice-err');
                $ico = $kind === 'ok' ? 'yes-alt' : ($kind === 'warn' ? 'warning' : 'dismiss');
            ?>
                <div class="bcn-notice <?php echo esc_attr($cls); ?>">
                    <span class="dashicons dashicons-<?php echo esc_attr($ico); ?>"></span>
                    <span>
                        <?php echo esc_html($msg); ?>
                        <?php if ($flash_err !== '' && $kind === 'err'): ?>
                            <span class="bcn-flash-err"> &mdash; <?php echo esc_html($flash_err); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>

            <details id="bcn-new-ticket" class="bcn-new-ticket"<?php echo $form_open ? ' open' : ''; ?>>
                <summary><?php esc_html_e('Open a new ticket from this site', 'bynli-connect'); ?></summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="bcn-new-ticket-form">
                    <input type="hidden" name="action" value="bynli_connect_ticket_new" />
                    <?php wp_nonce_field(self::NONCE_NEW); ?>

                    <p class="bcn-field">
                        <label for="bcn-new-subject"><?php esc_html_e('Subject', 'bynli-connect'); ?></label>
                        <input type="text" id="bcn-new-subject" name="ticket_subject"
                               value="<?php echo esc_attr($sticky_subject); ?>"
                               maxlength="200" minlength="3" required
                               placeholder="<?php esc_attr_e('Short summary of the problem', 'bynli-connect'); ?>" />
                    </p>

                    <p class="bcn-field">
                        <label for="bcn-new-category"><?php esc_html_e('Category', 'bynli-connect'); ?></label>
                        <select id="bcn-new-category" name="ticket_category">
                            <?php
                            $cats = [
                                'technical' => __('Technical — something on the site is broken', 'bynli-connect'),
                                'billing'   => __('Billing — questions about an invoice or charge', 'bynli-connect'),
                                'account'   => __('Account — access, roles, or settings', 'bynli-connect'),
                                'general'   => __('General — anything else', 'bynli-connect'),
                            ];
                            foreach ($cats as $key => $label):
                            ?>
                                <option value="<?php echo esc_attr($key); ?>"<?php selected($sticky_category, $key); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <p class="bcn-field">
                        <label for="bcn-new-body"><?php esc_html_e('Message', 'bynli-connect'); ?></label>
                        <textarea id="bcn-new-body" name="ticket_body" rows="6" maxlength="5000" required
                                  placeholder="<?php esc_attr_e('Describe what you need help with. Bynli staff will see this immediately.', 'bynli-connect'); ?>"><?php echo esc_textarea($sticky_body); ?></textarea>
                        <span class="bcn-field-hint">
                            <?php
                            $wp_user = wp_get_current_user();
                            if ($wp_user && $wp_user->exists() && !empty($wp_user->user_email)) {
                                printf(
                                    esc_html__('Posting as %1$s (%2$s). Bynli staff will email this address with any reply.', 'bynli-connect'),
                                    esc_html($wp_user->display_name ?: $wp_user->user_login),
                                    esc_html($wp_user->user_email)
                                );
                            } else {
                                esc_html_e('Bynli staff will reply to this WordPress site.', 'bynli-connect');
                            }
                            ?>
                        </span>
                    </p>

                    <p class="bcn-actions">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Send to Bynli support', 'bynli-connect'); ?>
                        </button>
                    </p>
                </form>
            </details>

            <nav class="bcn-tabs" aria-label="Ticket status filter">
                <?php foreach (['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'all' => 'All'] as $k => $label):
                    $url = add_query_arg(['status' => $k], menu_page_url(self::MENU_SLUG, false));
                ?>
                    <a class="bcn-tab<?php echo $status === $k ? ' is-active' : ''; ?>"
                       href="<?php echo esc_url($url); ?>"
                       <?php echo $status === $k ? 'aria-current="page"' : ''; ?>>
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if (!$res['ok']): ?>
                <div class="bcn-notice bcn-notice-err">
                    <span class="dashicons dashicons-warning"></span>
                    <span><?php echo esc_html($res['message'] ?? 'Could not fetch tickets.'); ?></span>
                </div>
            <?php else:
                $tickets = $res['data']['tickets'] ?? [];
            ?>
                <?php if (empty($tickets)): ?>
                    <p class="bcn-empty">
                        <?php
                        echo $status === 'open'
                            ? esc_html__('No open tickets right now. ', 'bynli-connect')
                            : esc_html__('No tickets matching this filter. ', 'bynli-connect');
                        ?>
                        <a href="<?php echo esc_url(add_query_arg(['new' => '1'], menu_page_url(self::MENU_SLUG, false))); ?>#bcn-new-ticket">
                            <?php esc_html_e('Open a new one from this site', 'bynli-connect'); ?>
                        </a>.
                    </p>
                <?php else: ?>
                    <table class="widefat striped bcn-ticket-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Subject', 'bynli-connect'); ?></th>
                                <th><?php esc_html_e('Status', 'bynli-connect'); ?></th>
                                <th><?php esc_html_e('Priority', 'bynli-connect'); ?></th>
                                <th><?php esc_html_e('Last update', 'bynli-connect'); ?></th>
                                <th><?php esc_html_e('Replies', 'bynli-connect'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tickets as $t):
                            $ref         = (string)($t['ticket_ref'] ?? '');
                            $subject     = (string)($t['subject']    ?? '(no subject)');
                            $st          = (string)($t['status']     ?? 'open');
                            $pri         = (string)($t['priority']   ?? 'normal');
                            $reply_count = (int)($t['reply_count']   ?? 0);
                            $upd_at      = (string)($t['updated_at'] ?? '');
                            $preview     = (string)($t['last_reply_preview'] ?? '');
                            $detail_url  = add_query_arg(['ticket_ref' => $ref], menu_page_url(self::MENU_SLUG, false));
                        ?>
                            <tr>
                                <td class="bcn-ticket-subj">
                                    <a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html($subject); ?></a>
                                    <code class="bcn-ticket-ref"><?php echo esc_html($ref); ?></code>
                                    <?php if ($preview !== ''): ?>
                                        <p class="bcn-ticket-preview"><?php echo esc_html($preview); ?></p>
                                    <?php endif; ?>
                                </td>
                                <td><span class="bcn-pill bcn-pill-<?php echo esc_attr($st); ?>"><?php echo esc_html(ucfirst(str_replace('_', ' ', $st))); ?></span></td>
                                <td><span class="bcn-pill bcn-pill-pri-<?php echo esc_attr($pri); ?>"><?php echo esc_html(ucfirst($pri)); ?></span></td>
                                <td><?php echo esc_html($upd_at !== '' ? self::human_when($upd_at) : '—'); ?></td>
                                <td><?php echo esc_html((string)$reply_count); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_detail(string $ref): void {
        $res = Bynli_Connect_Api::get('/api/site-host/tickets/' . rawurlencode($ref));
        $list_url = menu_page_url(self::MENU_SLUG, false);

        // Flash messages from handle_reply / handle_resolve redirects.
        $flash_result = isset($_GET['result']) ? sanitize_text_field((string)$_GET['result']) : '';
        $flash_err    = isset($_GET['err'])    ? sanitize_text_field(urldecode((string)$_GET['err'])) : '';

        ?>
        <div class="wrap bcn-wrap bcn-tickets bcn-ticket-detail">
            <p>
                <a href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('All tickets', 'bynli-connect'); ?></a>
            </p>

            <?php
            // Flash strip — only renders for known result codes so a tampered
            // query string can't paint arbitrary HTML.
            $flash_map = [
                'opened'          => ['ok',   __('Ticket opened. Bynli support will be notified.', 'bynli-connect')],
                'replied'         => ['ok',   __('Reply posted. Bynli support will be notified.', 'bynli-connect')],
                'resolved'        => ['ok',   __('Ticket marked resolved. Bynli will close it on their side.', 'bynli-connect')],
                'empty'           => ['warn', __('Reply cannot be empty.', 'bynli-connect')],
                'reply_failed'    => ['err',  __('Could not post reply.', 'bynli-connect')],
                'resolve_failed'  => ['err',  __('Could not mark resolved.', 'bynli-connect')],
            ];
            if (isset($flash_map[$flash_result])):
                list($kind, $msg) = $flash_map[$flash_result];
                $cls = $kind === 'ok' ? 'bcn-notice-ok' : ($kind === 'warn' ? 'bcn-notice-warn' : 'bcn-notice-err');
                $ico = $kind === 'ok' ? 'yes-alt' : ($kind === 'warn' ? 'warning' : 'dismiss');
            ?>
                <div class="bcn-notice <?php echo esc_attr($cls); ?>">
                    <span class="dashicons dashicons-<?php echo esc_attr($ico); ?>"></span>
                    <span>
                        <?php echo esc_html($msg); ?>
                        <?php if ($flash_err !== '' && $kind === 'err'): ?>
                            <span class="bcn-flash-err"> &mdash; <?php echo esc_html($flash_err); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if (!$res['ok']):
                $is_404 = ($res['status'] ?? 0) === 404;
            ?>
                <div class="bcn-notice <?php echo $is_404 ? 'bcn-notice-warn' : 'bcn-notice-err'; ?>">
                    <span class="dashicons dashicons-warning"></span>
                    <span>
                        <?php echo esc_html(
                            $is_404
                                ? __('That ticket does not exist or is not visible from this site.', 'bynli-connect')
                                : ($res['message'] ?? __('Could not fetch the ticket.', 'bynli-connect'))
                        ); ?>
                    </span>
                </div>
            <?php else:
                $ticket  = $res['data']['ticket']  ?? [];
                $replies = $res['data']['replies'] ?? [];
                $subj    = (string)($ticket['subject']     ?? '(no subject)');
                $body    = (string)($ticket['body']        ?? '');
                $st      = (string)($ticket['status']      ?? 'open');
                $pri     = (string)($ticket['priority']    ?? 'normal');
                $cat     = (string)($ticket['category']    ?? '');
                $created = (string)($ticket['created_at']  ?? '');
                $resolved = (string)($ticket['resolved_at'] ?? '');
                $submitter = isset($ticket['submitter']['name']) ? (string)$ticket['submitter']['name'] : '';
            ?>
                <header class="bcn-header">
                    <div>
                        <h1 class="bcn-ticket-title"><?php echo esc_html($subj); ?></h1>
                        <p class="bcn-ticket-meta">
                            <code><?php echo esc_html((string)($ticket['ticket_ref'] ?? $ref)); ?></code>
                            <?php if ($cat !== ''): ?>
                                &middot; <?php echo esc_html($cat); ?>
                            <?php endif; ?>
                            <?php if ($submitter !== ''): ?>
                                &middot; <?php printf(esc_html__('opened by %s', 'bynli-connect'), esc_html($submitter)); ?>
                            <?php endif; ?>
                            <?php if ($created !== ''): ?>
                                &middot; <?php echo esc_html(self::human_when($created)); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="bcn-ticket-status-chips">
                        <span class="bcn-pill bcn-pill-<?php echo esc_attr($st); ?>"><?php echo esc_html(ucfirst(str_replace('_', ' ', $st))); ?></span>
                        <span class="bcn-pill bcn-pill-pri-<?php echo esc_attr($pri); ?>"><?php echo esc_html(ucfirst($pri)); ?></span>
                    </div>
                </header>

                <article class="bcn-thread-msg bcn-thread-msg--customer">
                    <header class="bcn-thread-msg-head">
                        <strong><?php echo esc_html($submitter !== '' ? $submitter : __('Team member', 'bynli-connect')); ?></strong>
                        <span class="bcn-thread-msg-when"><?php echo esc_html(self::human_when($created)); ?></span>
                    </header>
                    <div class="bcn-thread-msg-body"><?php echo nl2br(esc_html($body)); ?></div>
                </article>

                <?php if (!empty($replies)): foreach ($replies as $r):
                    $is_staff = !empty($r['is_staff']);
                    $author   = (string)($r['author']     ?? ($is_staff ? 'Bynli support' : 'Team member'));
                    $rbody    = (string)($r['body']       ?? '');
                    $rat      = (string)($r['created_at'] ?? '');
                    $att      = isset($r['attachment']) && is_array($r['attachment']) ? $r['attachment'] : null;
                ?>
                    <article class="bcn-thread-msg <?php echo $is_staff ? 'bcn-thread-msg--staff' : 'bcn-thread-msg--customer'; ?>">
                        <header class="bcn-thread-msg-head">
                            <strong><?php echo esc_html($author); ?></strong>
                            <?php if ($is_staff): ?>
                                <span class="bcn-pill bcn-pill-staff">Bynli</span>
                            <?php endif; ?>
                            <span class="bcn-thread-msg-when"><?php echo esc_html(self::human_when($rat)); ?></span>
                        </header>
                        <div class="bcn-thread-msg-body"><?php echo nl2br(esc_html($rbody)); ?></div>
                        <?php if ($att): ?>
                            <p class="bcn-thread-msg-att">
                                <span class="dashicons dashicons-paperclip"></span>
                                <?php echo esc_html(($att['name'] ?? '') ?: __('attachment', 'bynli-connect')); ?>
                                <?php if (isset($att['size']) && $att['size'] !== null): ?>
                                    <span class="bcn-thread-msg-att-size">(<?php echo esc_html(size_format((int)$att['size'])); ?>)</span>
                                <?php endif; ?>
                                <em><?php esc_html_e('— open on Bynli to download', 'bynli-connect'); ?></em>
                            </p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; endif; ?>

                <?php if ($st !== 'resolved'): ?>
                <footer class="bcn-thread-foot">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="bcn-reply-form">
                        <input type="hidden" name="action"     value="bynli_connect_ticket_reply">
                        <input type="hidden" name="ticket_ref" value="<?php echo esc_attr((string)($ticket['ticket_ref'] ?? $ref)); ?>">
                        <?php wp_nonce_field(self::NONCE_REPLY . '_' . ((string)($ticket['ticket_ref'] ?? $ref))); ?>

                        <label class="bcn-label" for="bcn-reply-body"><?php esc_html_e('Reply', 'bynli-connect'); ?></label>
                        <textarea id="bcn-reply-body" name="reply_body" rows="4"
                                  class="bcn-input bcn-textarea" maxlength="5000"
                                  placeholder="<?php esc_attr_e('Write a reply…', 'bynli-connect'); ?>"
                                  required></textarea>
                        <?php
                        // Show the WP user the plugin will attribute this
                        // reply to — Bynli labels the thread with it AND
                        // routes staff follow-up emails to this address.
                        $current = wp_get_current_user();
                        $who_name  = $current && $current->exists() ? (string)$current->display_name : '';
                        $who_email = $current && $current->exists() ? (string)$current->user_email   : '';
                        ?>
                        <p class="bcn-hint">
                            <?php
                            if ($who_email !== '') {
                                printf(
                                    /* translators: %1$s: display name, %2$s: email */
                                    esc_html__('Posted as %1$s (%2$s). Bynli staff will email this address with any reply.', 'bynli-connect'),
                                    esc_html($who_name ?: $who_email),
                                    esc_html($who_email)
                                );
                            } else {
                                esc_html_e('Posted as your connected WordPress site.', 'bynli-connect');
                            }
                            ?>
                            <?php esc_html_e('Max 5000 characters.', 'bynli-connect'); ?>
                        </p>

                        <div class="bcn-reply-actions">
                            <button type="submit" class="bcn-btn bcn-btn-primary">
                                <?php esc_html_e('Send reply', 'bynli-connect'); ?>
                            </button>
                            <a class="bcn-btn" href="<?php echo esc_url('https://bynli.com/dash/support/center?tx=' . rawurlencode((string)($ticket['ticket_ref'] ?? $ref))); ?>" target="_blank" rel="noopener">
                                <?php esc_html_e('Open on Bynli', 'bynli-connect'); ?>
                                <span class="dashicons dashicons-external"></span>
                            </a>
                        </div>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="bcn-resolve-form"
                          onsubmit="return confirm('<?php echo esc_js(__('Mark this ticket resolved? Staff will see the close on their side.', 'bynli-connect')); ?>');">
                        <input type="hidden" name="action"     value="bynli_connect_ticket_resolve">
                        <input type="hidden" name="ticket_ref" value="<?php echo esc_attr((string)($ticket['ticket_ref'] ?? $ref)); ?>">
                        <?php wp_nonce_field(self::NONCE_RESOLVE . '_' . ((string)($ticket['ticket_ref'] ?? $ref))); ?>

                        <details class="bcn-resolve-details">
                            <summary><?php esc_html_e('Mark resolved', 'bynli-connect'); ?></summary>
                            <label class="bcn-label" for="bcn-resolve-note"><?php esc_html_e('Optional final note', 'bynli-connect'); ?></label>
                            <textarea id="bcn-resolve-note" name="resolve_note" rows="2"
                                      class="bcn-input bcn-textarea" maxlength="5000"
                                      placeholder="<?php esc_attr_e('e.g. Got it working — thanks!', 'bynli-connect'); ?>"></textarea>
                            <p class="bcn-hint"><?php esc_html_e('If filled, posted as a final reply before closing the ticket.', 'bynli-connect'); ?></p>
                            <button type="submit" class="bcn-btn">
                                <?php esc_html_e('Mark resolved', 'bynli-connect'); ?>
                            </button>
                        </details>
                    </form>
                </footer>
                <?php else: ?>
                <footer class="bcn-thread-foot">
                    <p class="bcn-thread-resolved"><?php
                        printf(esc_html__('Resolved %s — thread closed. Open on Bynli to reopen if needed.', 'bynli-connect'), esc_html(self::human_when($resolved !== '' ? $resolved : ($ticket['updated_at'] ?? ''))));
                    ?></p>
                    <p>
                        <a class="bcn-btn" href="<?php echo esc_url('https://bynli.com/dash/support/center?tx=' . rawurlencode((string)($ticket['ticket_ref'] ?? $ref))); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Open on Bynli', 'bynli-connect'); ?>
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </p>
                </footer>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Friendly time renderer — "3 hours ago" for recent, full date for older.
     * Falls back to the raw string if WP's date utilities can't parse it.
     */
    private static function human_when(string $iso): string {
        $ts = strtotime($iso);
        if (!$ts) return $iso;
        $diff = time() - $ts;
        if ($diff < 60 * 60 * 24 * 7) {
            return sprintf(
                /* translators: %s: relative time */
                __('%s ago', 'bynli-connect'),
                human_time_diff($ts, time())
            );
        }
        return date_i18n(get_option('date_format', 'M j, Y'), $ts);
    }
}
