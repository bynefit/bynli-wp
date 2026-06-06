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

    const MENU_SLUG  = 'bynli-connect-tickets';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu'], 11);
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

        ?>
        <div class="wrap bcn-wrap bcn-tickets">
            <header class="bcn-header">
                <div class="bcn-brand">
                    <span class="bcn-wordmark">Bynli<span class="bcn-wordmark-dot">.</span></span>
                    <span class="bcn-divider-v" aria-hidden="true"></span>
                    <span class="bcn-product">Support tickets</span>
                </div>
                <a class="bcn-btn" href="https://bynli.com/dash/support/center" target="_blank" rel="noopener">
                    Open new ticket
                    <span class="dashicons dashicons-external"></span>
                </a>
            </header>

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
                        <a href="https://bynli.com/dash/support/center" target="_blank" rel="noopener">
                            Open a new ticket on Bynli
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

        ?>
        <div class="wrap bcn-wrap bcn-tickets bcn-ticket-detail">
            <p>
                <a href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('All tickets', 'bynli-connect'); ?></a>
            </p>

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

                <footer class="bcn-thread-foot">
                    <p>
                        <?php esc_html_e('Reply or resolve from the WordPress side is coming in a later release. For now, open the ticket on Bynli to respond:', 'bynli-connect'); ?>
                    </p>
                    <p>
                        <a class="bcn-btn bcn-btn-primary" href="<?php echo esc_url('https://bynli.com/dash/support/center?tx=' . rawurlencode((string)($ticket['ticket_ref'] ?? $ref))); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Open ticket on Bynli', 'bynli-connect'); ?>
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </p>
                    <?php if ($resolved !== ''): ?>
                        <p class="bcn-thread-resolved"><?php
                            printf(esc_html__('Resolved %s', 'bynli-connect'), esc_html(self::human_when($resolved)));
                        ?></p>
                    <?php endif; ?>
                </footer>
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
