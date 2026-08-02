<?php
if (!defined('ABSPATH')) { exit; }

class Bynli_Connect_Tickets {

    const MENU_SLUG       = 'bynli-connect-tickets';
    const NONCE_REPLY     = 'bynli_connect_ticket_reply';
    const NONCE_RESOLVE   = 'bynli_connect_ticket_resolve';
    const NONCE_NEW       = 'bynli_connect_ticket_new';

    public function __construct() {
        add_action('admin_menu',                           [$this, 'register_menu'], 11);
        add_action('wp_ajax_bynli_connect_ticket_reply',   [$this, 'handle_reply']);
        add_action('wp_ajax_bynli_connect_ticket_resolve', [$this, 'handle_resolve']);
        add_action('wp_ajax_bynli_connect_ticket_new',     [$this, 'handle_new']);
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

    public function handle_reply(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to reply to tickets.', 'bynli-connect')], 403);
        }

        $ref = isset($_POST['ticket_ref']) ? sanitize_text_field((string)$_POST['ticket_ref']) : '';
        if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $ref)) {
            wp_send_json_error(['message' => __('Invalid ticket reference.', 'bynli-connect')], 400);
        }

        if (!check_ajax_referer(self::NONCE_REPLY . '_' . $ref, '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Reload and try again.', 'bynli-connect')], 403);
        }

        $body = isset($_POST['reply_body']) ? trim(wp_unslash((string)$_POST['reply_body'])) : '';
        if ($body === '') {
            wp_send_json_error([
                'message' => __('Reply cannot be empty.', 'bynli-connect'),
                'field'   => 'reply_body',
            ], 400);
        }

        $payload = ['body' => $body];
        $wp_user = wp_get_current_user();
        if ($wp_user && $wp_user->exists()) {
            if (!empty($wp_user->user_email))   $payload['wp_user_email'] = (string)$wp_user->user_email;
            if (!empty($wp_user->display_name)) $payload['wp_user_name']  = (string)$wp_user->display_name;
        }

        $res = Bynli_Connect_Api::post('/api/site-host/tickets/' . rawurlencode($ref) . '/reply', $payload);

        if (!$res['ok']) {
            $status = is_int($res['status'] ?? null) && $res['status'] >= 400 && $res['status'] < 600
                ? (int)$res['status']
                : 502;
            wp_send_json_error([
                'message' => (string)($res['message'] ?? __('Could not post reply.', 'bynli-connect')),
            ], $status);
        }

        $author   = ($wp_user && $wp_user->exists() && !empty($wp_user->display_name))
            ? (string)$wp_user->display_name
            : __('Team member', 'bynli-connect');
        $when_iso = (string)($res['data']['created_at'] ?? gmdate('Y-m-d H:i:s'));

        wp_send_json_success([
            'message_html' => self::render_thread_message_html([
                'is_staff'   => false,
                'author'     => $author,
                'body'       => $body,
                'created_at' => $when_iso,
                'attachment' => null,
            ]),
            'created_at' => $when_iso,
        ]);
    }

    public function handle_resolve(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to resolve tickets.', 'bynli-connect')], 403);
        }

        $ref = isset($_POST['ticket_ref']) ? sanitize_text_field((string)$_POST['ticket_ref']) : '';
        if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $ref)) {
            wp_send_json_error(['message' => __('Invalid ticket reference.', 'bynli-connect')], 400);
        }

        if (!check_ajax_referer(self::NONCE_RESOLVE . '_' . $ref, '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Reload and try again.', 'bynli-connect')], 403);
        }

        $note    = isset($_POST['resolve_note']) ? trim(wp_unslash((string)$_POST['resolve_note'])) : '';
        $payload = $note !== '' ? ['note' => $note] : [];

        $wp_user = wp_get_current_user();
        if ($wp_user && $wp_user->exists()) {
            if (!empty($wp_user->user_email))   $payload['wp_user_email'] = (string)$wp_user->user_email;
            if (!empty($wp_user->display_name)) $payload['wp_user_name']  = (string)$wp_user->display_name;
        }

        $res = Bynli_Connect_Api::post('/api/site-host/tickets/' . rawurlencode($ref) . '/resolve', $payload);

        if (!$res['ok']) {
            $status = is_int($res['status'] ?? null) && $res['status'] >= 400 && $res['status'] < 600
                ? (int)$res['status']
                : 502;
            wp_send_json_error([
                'message' => (string)($res['message'] ?? __('Could not mark resolved.', 'bynli-connect')),
            ], $status);
        }

        $resolved_iso = gmdate('Y-m-d H:i:s');

        wp_send_json_success([
            'status'    => 'resolved',
            'foot_html' => self::render_resolved_foot_html($resolved_iso),
        ]);
    }

    /**
     * AJAX handler — open a new ticket from wp-admin (v0.7).
     *
     * Site-attributed via the host key; subject/body/category come from
     * the form. Categories are restricted to the four the server accepts
     * from a host-key flow (technical / billing / general / account).
     *
     * Returns JSON via wp_send_json_*. The form's JS handler renders the
     * error inline on failure and navigates to the new ticket's detail
     * view (?result=opened) on success — that navigation is the "user
     * just created a resource, take them there" exception to the no-
     * redirect-after-POST rule.
     */
    public function handle_new(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You do not have permission to open tickets.', 'bynli-connect')], 403);
        }
        if (!check_ajax_referer(self::NONCE_NEW, '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Reload the page and try again.', 'bynli-connect')], 403);
        }

        $subject  = isset($_POST['ticket_subject']) ? trim(wp_unslash((string)$_POST['ticket_subject'])) : '';
        $body     = isset($_POST['ticket_body'])    ? trim(wp_unslash((string)$_POST['ticket_body']))    : '';
        $category = isset($_POST['ticket_category']) ? sanitize_text_field((string)$_POST['ticket_category']) : 'general';

        $allowed_cats = ['technical', 'billing', 'general', 'account'];
        if (!in_array($category, $allowed_cats, true)) $category = 'general';

        if ($subject === '' || mb_strlen($subject) < 3) {
            wp_send_json_error([
                'message' => __('Subject is required (at least 3 characters).', 'bynli-connect'),
                'field'   => 'subject',
            ], 400);
        }
        if ($body === '') {
            wp_send_json_error([
                'message' => __('Message is required.', 'bynli-connect'),
                'field'   => 'body',
            ], 400);
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
            $status = is_int($res['status'] ?? null) && $res['status'] >= 400 && $res['status'] < 600
                ? (int)$res['status']
                : 502;
            wp_send_json_error([
                'message' => (string)($res['message'] ?? __('Could not open the ticket.', 'bynli-connect')),
            ], $status);
        }

        $ref = (string)($res['data']['ticket_ref'] ?? '');
        $detail_url = $ref !== '' && preg_match('/^[A-Za-z0-9_-]{3,64}$/', $ref)
            ? self::console_url(['ticket_ref' => $ref, 'result' => 'opened'])
            : self::console_url(['result' => 'opened']);

        wp_send_json_success([
            'ticket_ref' => $ref,
            'detail_url' => $detail_url,
        ]);
    }

    /**
     * Standalone submenu — the Tickets surface now lives inside the Bynefit
     * Connect console (Settings → Bynefit Connect → Tickets). Keep the submenu
     * as a thin redirect so bookmarks + the post-open detail links resolve.
     */
    public function render(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden.', 403);
        $args = [];
        foreach (['ticket_ref', 'status', 'result'] as $k) {
            if (isset($_GET[$k])) $args[$k] = sanitize_text_field((string)$_GET[$k]);
        }
        if (isset($_GET['new'])) $args['new'] = '1';
        wp_safe_redirect(self::console_url($args));
        exit;
    }

    /** URL of the console Tickets section, with optional query args. */
    public static function console_url(array $args = []): string {
        return add_query_arg(
            array_merge(['page' => Bynli_Connect_Settings::MENU_SLUG, 'section' => 'tickets'], $args),
            admin_url('options-general.php')
        );
    }

    /**
     * Rendered inside the console 'tickets' panel by Bynli_Connect_Settings.
     * Routes to detail or list on ?ticket_ref=. Makes a remote API call, so
     * the console only invokes this when tickets is the active section.
     */
    public static function render_panel(): void {
        if (Bynli_Connect_Settings::key() === '') { self::render_unconfigured(); return; }
        $ref = isset($_GET['ticket_ref']) ? sanitize_text_field((string)$_GET['ticket_ref']) : '';
        if ($ref !== '' && preg_match('/^[A-Za-z0-9_-]{3,64}$/', $ref)) {
            self::render_detail($ref);
            return;
        }
        self::render_list();
    }

    // ── States ─────────────────────────────────────────────────────

    private static function render_unconfigured(): void {
        $conn = add_query_arg(
            ['page' => Bynli_Connect_Settings::MENU_SLUG, 'section' => 'connection'],
            admin_url('options-general.php')
        );
        ?>
        <div class="bcn-note warn">
            <span class="dashicons dashicons-warning" aria-hidden="true"></span>
            <span>No Bynefit site-host key yet — <a href="<?php echo esc_url($conn); ?>">finish setup in Connection</a> so this site can read your team’s tickets.</span>
        </div>
        <?php
    }

    private static function render_list(): void {
        $status = isset($_GET['status']) ? sanitize_text_field((string)$_GET['status']) : 'open';
        if (!in_array($status, ['open', 'resolved', 'in_progress', 'all'], true)) $status = 'open';

        $res = Bynli_Connect_Api::get('/api/site-host/tickets', ['status' => $status]);

        $flash_result = isset($_GET['result']) ? sanitize_text_field((string)$_GET['result']) : '';
        $form_open    = isset($_GET['new']) && $_GET['new'] === '1';
        // #20 — per-tab counts, when the server sends them. Absent → no chips.
        $counts = (!empty($res['ok']) && isset($res['data']['counts']) && is_array($res['data']['counts']))
            ? $res['data']['counts'] : [];
        ?>
        <div class="bcn-tk">
            <div class="bcn-tk-head">
                <div>
                    <h2 class="bcn-tk-title">Support tickets</h2>
                    <p class="bcn-tk-sub">Your team’s Bynefit support threads, from this site.</p>
                </div>
                <a class="bcn-btn primary sm" href="<?php echo esc_url(self::console_url(['new' => '1'])); ?>#bcn-new-ticket">
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> Open new ticket
                </a>
            </div>

            <?php if ($flash_result === 'opened'): ?>
                <div class="bcn-notice bcn-notice-ok"><span class="dashicons dashicons-yes-alt"></span>
                    <span>Ticket opened. Bynefit support will be notified.</span></div>
            <?php endif; ?>

            <details id="bcn-new-ticket" class="bcn-new-ticket"<?php echo $form_open ? ' open' : ''; ?>>
                <summary>Open a new ticket from this site</summary>
                <form class="bcn-new-ticket-form bcn-ajax-form" data-bcn-action="bynli_connect_ticket_new" novalidate>
                    <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr(wp_create_nonce(self::NONCE_NEW)); ?>" />
                    <div class="bcn-form-feedback" data-role="feedback" hidden></div>

                    <div class="bcn-field">
                        <label class="bcn-label" for="bcn-new-subject">Subject</label>
                        <input class="bcn-input sans" type="text" id="bcn-new-subject" name="ticket_subject"
                               maxlength="200" minlength="3" required placeholder="Short summary of the problem" />
                    </div>
                    <div class="bcn-field">
                        <label class="bcn-label" for="bcn-new-category">Category</label>
                        <select class="bcn-input sans" id="bcn-new-category" name="ticket_category">
                            <option value="technical">Technical — something on the site is broken</option>
                            <option value="billing">Billing — questions about an invoice or charge</option>
                            <option value="account">Account — access, roles, or settings</option>
                            <option value="general" selected>General — anything else</option>
                        </select>
                    </div>
                    <div class="bcn-field">
                        <label class="bcn-label" for="bcn-new-body">Message</label>
                        <textarea class="bcn-input sans" id="bcn-new-body" name="ticket_body" rows="6" maxlength="5000" required
                                  placeholder="Describe what you need help with. Bynefit staff will see this immediately."></textarea>
                        <span class="bcn-hint"><?php
                            $wp_user = wp_get_current_user();
                            if ($wp_user && $wp_user->exists() && !empty($wp_user->user_email)) {
                                printf(
                                    esc_html__('Posting as %1$s (%2$s). Bynefit staff will email this address with any reply.', 'bynli-connect'),
                                    esc_html($wp_user->display_name ?: $wp_user->user_login),
                                    esc_html($wp_user->user_email)
                                );
                            } else { esc_html_e('Bynefit staff will reply to this WordPress site.', 'bynli-connect'); }
                        ?></span>
                    </div>
                    <div class="bcn-actions">
                        <button type="submit" class="bcn-btn primary" data-role="submit">Send to Bynefit support</button>
                    </div>
                </form>
            </details>

            <nav class="bcn-tabs" aria-label="Ticket status filter">
                <?php foreach (['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'all' => 'All'] as $k => $label):
                    $on  = ($status === $k);
                    $cnt = isset($counts[$k]) ? (int)$counts[$k] : null; ?>
                    <a class="bcn-tab<?php echo $on ? ' is-active' : ''; ?>"
                       href="<?php echo esc_url(self::console_url(['status' => $k])); ?>"
                       <?php echo $on ? 'aria-current="page"' : ''; ?>>
                        <?php echo esc_html($label); ?>
                        <?php if ($cnt !== null && $cnt > 0): ?><span class="bcn-tab-count"><?php echo esc_html((string)$cnt); ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if (!$res['ok']): ?>
                <div class="bcn-notice bcn-notice-err"><span class="dashicons dashicons-warning"></span>
                    <span><?php echo esc_html($res['message'] ?? 'Could not fetch tickets.'); ?></span></div>
            <?php else: $tickets = $res['data']['tickets'] ?? []; ?>
                <?php if (empty($tickets)): ?>
                    <div class="bcn-empty" role="status">
                        <span class="dashicons dashicons-tickets-alt bcn-empty-icon" aria-hidden="true"></span>
                        <p class="bcn-empty-title"><?php echo $status === 'open' ? 'No open tickets right now.' : 'No tickets matching this filter.'; ?></p>
                        <p class="bcn-empty-cta"><a href="<?php echo esc_url(self::console_url(['new' => '1'])); ?>#bcn-new-ticket">Open a new one from this site</a></p>
                    </div>
                <?php else: ?>
                    <div class="bcn-tk-table-wrap">
                        <table class="bcn-tk-table">
                            <thead><tr><th>Subject</th><th>Status</th><th>Priority</th><th>Last update</th><th>Replies</th></tr></thead>
                            <tbody>
                            <?php foreach ($tickets as $t):
                                $ref         = (string)($t['ticket_ref'] ?? '');
                                $subject     = (string)($t['subject']    ?? '(no subject)');
                                $st          = (string)($t['status']     ?? 'open');
                                $pri         = (string)($t['priority']   ?? 'normal');
                                $reply_count = (int)($t['reply_count']   ?? 0);
                                $upd_at      = (string)($t['updated_at'] ?? '');
                                $preview     = (string)($t['last_reply_preview'] ?? ''); ?>
                                <tr>
                                    <td class="bcn-tk-subj">
                                        <a href="<?php echo esc_url(self::console_url(['ticket_ref' => $ref])); ?>"><?php echo esc_html($subject); ?></a>
                                        <code class="bcn-ticket-ref"><?php echo esc_html($ref); ?></code>
                                        <?php if ($preview !== ''): ?><p class="bcn-ticket-preview"><?php echo esc_html($preview); ?></p><?php endif; ?>
                                    </td>
                                    <td><span class="bcn-pill bcn-pill-<?php echo esc_attr($st); ?>"><?php echo esc_html(ucfirst(str_replace('_', ' ', $st))); ?></span></td>
                                    <td><span class="bcn-pill bcn-pill-pri-<?php echo esc_attr($pri); ?>"><?php echo esc_html(ucfirst($pri)); ?></span></td>
                                    <td><?php echo esc_html($upd_at !== '' ? self::human_when($upd_at) : '—'); ?></td>
                                    <td><?php echo esc_html((string)$reply_count); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_detail(string $ref): void {
        $res = Bynli_Connect_Api::get('/api/site-host/tickets/' . rawurlencode($ref));
        $list_url = self::console_url();

        $flash_result = isset($_GET['result']) ? sanitize_text_field((string)$_GET['result']) : '';

        ?>
        <div class="bcn-tk bcn-tk-detail">
            <p class="bcn-tk-back">
                <a href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('All tickets', 'bynli-connect'); ?></a>
            </p>

            <?php if ($flash_result === 'opened'): ?>
                <div class="bcn-notice bcn-notice-ok">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <span>Ticket opened. Bynefit support will be notified.</span>
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
                <header class="bcn-tk-detail-head">
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

                <?php
                echo self::render_thread_message_html([
                    'is_staff'   => false,
                    'author'     => $submitter !== '' ? $submitter : __('Team member', 'bynli-connect'),
                    'body'       => $body,
                    'created_at' => $created,
                    'attachment' => null,
                ]);

                if (!empty($replies)): foreach ($replies as $r):
                    echo self::render_thread_message_html([
                        'is_staff'   => !empty($r['is_staff']),
                        'author'     => (string)($r['author']     ?? (!empty($r['is_staff']) ? 'Bynli support' : 'Team member')),
                        'body'       => (string)($r['body']       ?? ''),
                        'created_at' => (string)($r['created_at'] ?? ''),
                        'attachment' => isset($r['attachment']) && is_array($r['attachment']) ? $r['attachment'] : null,
                    ]);
                endforeach; endif; ?>

                <?php
                $ticket_ref_attr = (string)($ticket['ticket_ref'] ?? $ref);
                $current   = wp_get_current_user();
                $who_name  = $current && $current->exists() ? (string)$current->display_name : '';
                $who_email = $current && $current->exists() ? (string)$current->user_email   : '';
                ?>
                <?php if ($st !== 'resolved'): ?>
                <footer class="bcn-thread-foot">
                    <form class="bcn-reply-form bcn-ajax-form"
                          data-bcn-action="bynli_connect_ticket_reply"
                          data-bcn-on-success="reply"
                          novalidate>
                        <input type="hidden" name="ticket_ref" value="<?php echo esc_attr($ticket_ref_attr); ?>">
                        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr(wp_create_nonce(self::NONCE_REPLY . '_' . $ticket_ref_attr)); ?>">

                        <div class="bcn-form-feedback" data-role="feedback" hidden></div>

                        <label class="bcn-label" for="bcn-reply-body"><?php esc_html_e('Reply', 'bynli-connect'); ?></label>
                        <textarea id="bcn-reply-body" name="reply_body" rows="4"
                                  class="bcn-input bcn-textarea" maxlength="5000"
                                  placeholder="<?php esc_attr_e('Write a reply…', 'bynli-connect'); ?>"
                                  required></textarea>

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
                            <button type="submit" class="bcn-btn primary" data-role="submit">
                                <?php esc_html_e('Send reply', 'bynli-connect'); ?>
                            </button>
                            <a class="bcn-btn" href="<?php echo esc_url('https://bynli.com/dash/support/center'); ?>" target="_blank" rel="noopener">
                                <?php esc_html_e('Open on Bynefit', 'bynli-connect'); ?>
                                <span class="dashicons dashicons-external"></span>
                            </a>
                        </div>
                    </form>

                    <form class="bcn-resolve-form bcn-ajax-form"
                          data-bcn-action="bynli_connect_ticket_resolve"
                          data-bcn-on-success="resolve"
                          data-bcn-confirm="<?php echo esc_attr__('Mark this ticket resolved? Staff will see the close on their side.', 'bynli-connect'); ?>"
                          novalidate>
                        <input type="hidden" name="ticket_ref" value="<?php echo esc_attr($ticket_ref_attr); ?>">
                        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr(wp_create_nonce(self::NONCE_RESOLVE . '_' . $ticket_ref_attr)); ?>">

                        <div class="bcn-form-feedback" data-role="feedback" hidden></div>

                        <details class="bcn-resolve-details">
                            <summary><?php esc_html_e('Mark resolved', 'bynli-connect'); ?></summary>
                            <label class="bcn-label" for="bcn-resolve-note"><?php esc_html_e('Optional final note', 'bynli-connect'); ?></label>
                            <textarea id="bcn-resolve-note" name="resolve_note" rows="2"
                                      class="bcn-input bcn-textarea" maxlength="5000"
                                      placeholder="<?php esc_attr_e('e.g. Got it working — thanks!', 'bynli-connect'); ?>"></textarea>
                            <p class="bcn-hint"><?php esc_html_e('If filled, posted as a final reply before closing the ticket.', 'bynli-connect'); ?></p>
                            <button type="submit" class="bcn-btn" data-role="submit">
                                <?php esc_html_e('Mark resolved', 'bynli-connect'); ?>
                            </button>
                        </details>
                    </form>
                </footer>
                <?php else: ?>
                <footer class="bcn-thread-foot">
                    <?php echo self::render_resolved_foot_html($resolved !== '' ? $resolved : (string)($ticket['updated_at'] ?? '')); ?>
                </footer>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_thread_message_html(array $msg): string {
        $is_staff = !empty($msg['is_staff']);
        $author   = (string)($msg['author']     ?? ($is_staff ? 'Bynli support' : 'Team member'));
        $body     = (string)($msg['body']       ?? '');
        $when     = (string)($msg['created_at'] ?? '');
        $att      = isset($msg['attachment']) && is_array($msg['attachment']) ? $msg['attachment'] : null;

        ob_start();
        ?>
        <article class="bcn-thread-msg <?php echo $is_staff ? 'bcn-thread-msg--staff' : 'bcn-thread-msg--customer'; ?>">
            <header class="bcn-thread-msg-head">
                <strong><?php echo esc_html($author); ?></strong>
                <?php if ($is_staff): ?>
                    <span class="bcn-pill bcn-pill-staff">Bynli</span>
                <?php endif; ?>
                <span class="bcn-thread-msg-when"><?php echo esc_html($when !== '' ? self::human_when($when) : ''); ?></span>
            </header>
            <div class="bcn-thread-msg-body"><?php echo nl2br(esc_html($body)); ?></div>
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
        <?php
        return (string)ob_get_clean();
    }

    public static function render_resolved_foot_html(string $resolved_iso): string {
        ob_start();
        ?>
        <p class="bcn-thread-resolved"><?php
            printf(
                esc_html__('Resolved %s — thread closed. Open on Bynefit to reopen if needed.', 'bynli-connect'),
                esc_html($resolved_iso !== '' ? self::human_when($resolved_iso) : '')
            );
        ?></p>
        <p>
            <a class="bcn-btn" href="<?php echo esc_url('https://bynli.com/dash/support/center'); ?>" target="_blank" rel="noopener">
                <?php esc_html_e('Open on Bynefit', 'bynli-connect'); ?>
                <span class="dashicons dashicons-external"></span>
            </a>
        </p>
        <?php
        return (string)ob_get_clean();
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
