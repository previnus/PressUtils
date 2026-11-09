<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Admin
{
    public static function hooks(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('network_admin_menu', static function (): void {
            add_menu_page('WP Utility Suite', 'Utility Suite', 'manage_network_options', 'wpus-network', [self::class, 'networkPage'], 'dashicons-admin-tools');
        });
        add_action('admin_post_wpus_action', [self::class, 'handle']);
        add_action('wp_dashboard_setup', static function (): void {
            if (current_user_can('wpus_read_health') && Policy::feature('admin_tools', 'dashboard')) {
                wp_add_dashboard_widget('wpus_dashboard', __('Utility Suite status', 'wp-utility-suite'), [Panels::class, 'overview']);
            }
        });
    }

    public static function labels(): array
    {
        return [
            'security' => __('Security & Audit', 'wp-utility-suite'),
            'performance' => __('Performance', 'wp-utility-suite'),
            'crm' => __('CRM & Webhooks', 'wp-utility-suite'),
            'admin_tools' => __('Admin Tools', 'wp-utility-suite'),
            'woocommerce' => __('WooCommerce', 'wp-utility-suite'),
            'monitoring' => __('Monitoring & SEO', 'wp-utility-suite'),
        ];
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'active' => __('Active', 'wp-utility-suite'),
            'off' => __('Off', 'wp-utility-suite'),
            'paused' => __('Paused by policy', 'wp-utility-suite'),
            'unavailable' => __('Unavailable', 'wp-utility-suite'),
            default => __('Setup in progress', 'wp-utility-suite'),
        };
    }

    public static function menu(): void
    {
        if (self::visible() || current_user_can('manage_options')) {
            add_menu_page('WP Utility Suite', __('Utility Suite', 'wp-utility-suite'), 'read', 'wpus', [self::class, 'page'], 'dashicons-admin-tools');
        }
    }

    public static function visible(): array
    {
        return array_filter(self::labels(), static fn (string $key): bool => Policy::canView($key), ARRAY_FILTER_USE_KEY);
    }

    public static function url(string $tab = '', array $args = []): string
    {
        return add_query_arg(array_merge(['page' => 'wpus', 'tab' => $tab], $args), admin_url('admin.php'));
    }

    public static function page(): void
    {
        $visible = self::visible();
        if (!$visible && !current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have access to Utility Suite.', 'wp-utility-suite'), '', ['response' => 403]);
        }
        $tab = sanitize_key(self::scalar($_GET, 'tab', 30));
        if ($tab !== '' && !isset($visible[$tab])) {
            wp_die(esc_html__('You do not have access to this module.', 'wp-utility-suite'), '', ['response' => 403]);
        }
        echo '<div class="wrap"><h1>WP Utility Suite</h1><nav class="nav-tab-wrapper" aria-label="' .
            esc_attr__('Utility Suite modules', 'wp-utility-suite') . '">';
        foreach (['' => __('Overview', 'wp-utility-suite')] + $visible as $id => $label) {
            printf('<a href="%s" class="nav-tab%s"%s>%s</a>', esc_url(self::url($id)),
                $id === $tab ? ' nav-tab-active' : '', $id === $tab ? ' aria-current="page"' : '', esc_html($label));
        }
        echo '</nav>';
        self::notice();
        if (!Lifecycle::ready()) {
            echo '<p>' . esc_html__('Setup is incomplete. Check InnoDB support and database permissions, then reload this page.', 'wp-utility-suite') . '</p></div>';
            return;
        }
        if ($tab === '') {
            Panels::overview(true);
        } else {
            printf('<h2>%s</h2><p>%s</p>', esc_html($visible[$tab]), esc_html(self::stateLabel(Policy::state($tab))));
            if (Policy::canManage($tab)) {
                Panels::settings($tab);
            }
            match ($tab) {
                'security' => Panels::audit(),
                'performance' => Panels::performance(),
                'admin_tools' => Panels::notes(),
                'monitoring' => Panels::monitoring(),
                'woocommerce' => Panels::commerce(),
                'crm' => Panels::crm(),
            };
        }
        echo '</div>';
    }

    public static function open(string $command, string $tab, array $fields = []): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('wpus_' . $command, 'wpus_nonce', false);
        foreach (['action' => 'wpus_action', 'command' => $command, 'tab' => $tab] + $fields as $key => $value) {
            printf('<input type="hidden" name="%s" value="%s">', esc_attr($key), esc_attr((string) $value));
        }
    }

    public static function button(string $label, string $style = 'secondary'): void
    {
        submit_button($label, $style, 'submit', false);
        echo '</form>';
    }

    public static function scalar(array $source, string $key, int $max): string
    {
        $value = $source[$key] ?? '';
        return is_string($value) && strlen($value) <= $max ? wp_unslash($value) : '';
    }

    public static function positive(array $source, string $key): int
    {
        $value = self::scalar($source, $key, 19);
        return ctype_digit($value) && (string) (int) $value === ltrim($value, '0') ? (int) $value : 0;
    }

    private static function choices(string $field, array $allowed): array
    {
        $values = $_POST[$field] ?? [];
        if (!is_array($values) || count($values) > count($allowed)) {
            throw new \InvalidArgumentException();
        }
        foreach ($values as $value) {
            if (!is_string($value) || !in_array(wp_unslash($value), $allowed, true)) {
                throw new \InvalidArgumentException();
            }
        }
        return array_values(array_unique(array_map('wp_unslash', $values)));
    }

    public static function handle(): void
    {
        $command = sanitize_key(self::scalar($_POST, 'command', 32));
        $tab = sanitize_key(self::scalar($_POST, 'tab', 32));
        $nonce = self::scalar($_POST, 'wpus_nonce', 32);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $command === ''
            || !wp_verify_nonce($nonce, 'wpus_' . $command)) {
            wp_die(esc_html__('This request is missing a valid Utility Suite nonce.', 'wp-utility-suite'), '', ['response' => 403]);
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
            wp_die(esc_html__('Utility Suite accepts requests up to 64 KiB.', 'wp-utility-suite'), '', ['response' => 413]);
        }
        if ($command === 'network') {
            self::saveNetwork();
        }
        if ($command === 'lifecycle') {
            self::saveLifecycle();
        }
        if (!Lifecycle::ready() || !isset(self::visible()[$tab])) {
            wp_die(esc_html__('This Utility Suite action is unavailable.', 'wp-utility-suite'), '', ['response' => 403]);
        }
        $id = self::positive($_POST, 'id');
        $revision = self::positive($_POST, 'revision');
        try {
            $status = match ($command) {
                'settings' => self::saveSettings($tab, $revision),
                'audit_export' => self::export(),
                'note_save' => Notes::save(sanitize_text_field(self::bounded('title', 160)),
                    sanitize_textarea_field(self::bounded('body', 16384)), self::optionalId('post_id'), self::optionalId('id'), $revision),
                'note_delete' => Notes::remove($id, $revision),
                'expire' => Operations::scheduleExpiry(self::positive($_POST, 'post_id'), self::scalar($_POST, 'when', 16)),
                'expiry_cancel' => Operations::cancel($id, $revision),
                'crawl' => Operations::scheduleCrawl(),
                'cleanup_preview' => Performance::previewCleanup(),
                'cleanup_apply' => Performance::applyCleanup($id, $revision),
                'redirect_save' => Monitoring::saveRule(self::scalar($_POST, 'source_path', 1024), self::scalar($_POST, 'target_path', 1024),
                    self::positive($_POST, 'status_code'), self::optionalId('id'), $revision),
                'redirect_delete' => Monitoring::removeRule($id, $revision),
                'destination_save' => Crm::configure(self::optionalId('id'), $revision, sanitize_text_field(self::scalar($_POST, 'label', 320)),
                    self::bounded('endpoint', 2048), self::bounded('signing', 128), self::choices('events', array_keys(Crm::EVENTS)), self::bounded('mapping', 4096)),
                'destination_state' => Crm::changeState($id, $revision, self::scalar($_POST, 'next_state', 16)),
                'destination_test' => Crm::test($id, $revision),
                'delivery_retry' => Crm::redrive($id),
                default => 'invalid',
            };
        } catch (\InvalidArgumentException) {
            $status = 'invalid';
        }
        wp_safe_redirect(self::url($tab, ['wpus_status' => $status]), 303);
        exit;
    }

    private static function bounded(string $field, int $length): string
    {
        $value = $_POST[$field] ?? '';
        if (!is_string($value) || strlen($value) > $length * 2 || strlen(wp_unslash($value)) > $length) {
            throw new \InvalidArgumentException();
        }
        return wp_unslash($value);
    }

    private static function optionalId(string $field): int
    {
        $value = self::bounded($field, 19);
        $id = self::positive($_POST, $field);
        if ($value !== '' && $value !== '0' && $id < 1) {
            throw new \InvalidArgumentException();
        }
        return $id;
    }

    private static function saveSettings(string $module, int $revision): string
    {
        if (!Policy::canManage($module)) {
            return 'denied';
        }
        $patch = [];
        foreach (Settings::defaults()[$module] as $key => $default) {
            if (is_bool($default) && current_user_can('wpus_manage_modules')) {
                $patch[$key] = self::scalar($_POST, $key, 1) === '1';
            } elseif (is_int($default)) {
                $patch[$key] = self::positive($_POST, $key);
            } elseif ($key === 'expiry_types') {
                $patch[$key] = self::choices($key, ['post', 'page']);
            }
        }
        return Settings::save($module, $patch, $revision);
    }

    public static function auditFilter(array $fields): ?array
    {
        $today = gmdate('Y-m-d');
        $from = self::scalar($fields, 'from', 10) ?: gmdate('Y-m-d', time() - 7 * DAY_IN_SECONDS);
        $to = self::scalar($fields, 'to', 10) ?: $today;
        if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $from)
            || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $to)) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $utc);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $utc);
        $event = self::scalar($fields, 'event', 40);
        if (!$start || !$end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to
            || $end < $start || $end->getTimestamp() - $start->getTimestamp() > 30 * DAY_IN_SECONDS
            || ($event !== '' && !preg_match('/\A[a-z0-9_.]+\z/D', $event))) {
            return null;
        }
        return [$from, $to, $event, self::positive($fields, 'actor'), $start->format('Y-m-d H:i:s'), $end->modify('+1 day')->format('Y-m-d H:i:s')];
    }

    private static function export(): string
    {
        if (!current_user_can('wpus_export_audit') || (wp_get_environment_type() === 'production' && !is_ssl())) {
            return 'denied';
        }
        $filter = self::auditFilter($_POST);
        if (!$filter || !Audit::record('audit.exported', 'audit')) {
            return 'invalid';
        }
        [, , $event, $actor, $from, $until] = $filter;
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Content-Disposition: attachment; filename="wpus-audit-' . gmdate('Y-m-d') . '.jsonl"');
        header('X-Content-Type-Options: nosniff');
        $before = 0;
        for ($batch = 0; $batch < 20; $batch++) {
            $rows = Audit::search($from, $until, $event, $actor, 500, $before);
            foreach ($rows as $row) {
                $row['details'] = Db::decode($row['details_json']);
                unset($row['details_json']);
                echo wp_json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                $before = (int) $row['id'];
            }
            if (count($rows) < 500) {
                break;
            }
        }
        exit;
    }

    private static function saveLifecycle(): never
    {
        if (!current_user_can('manage_options') || !current_user_can('wpus_manage_modules') || !Lifecycle::ready()) {
            wp_die(esc_html__('You cannot change lifecycle settings.', 'wp-utility-suite'), '', ['response' => 403]);
        }
        $paused = self::scalar($_POST, 'paused', 1) === '1';
        $purge = self::scalar($_POST, 'purge', 1) === '1';
        $audited = Audit::record('lifecycle.changed', 'settings');
        if ($paused || $audited) {
            update_option('wpus_outbound_paused', $paused, false);
        }
        $confirmed = Policy::sitePaused(true) === $paused;
        $saved = $audited && $confirmed;
        if ($saved && !is_multisite()) {
            update_option('wpus_purge', $purge, false);
            $saved = (bool) get_option('wpus_purge', false) === $purge;
        }
        $status = $saved ? 'saved' : ($paused && $confirmed && !$audited ? 'paused_unlogged' : 'error');
        wp_safe_redirect(self::url('', ['wpus_status' => $status]), 303);
        exit;
    }

    public static function networkPage(): void
    {
        if (!current_user_can('manage_network_options')) {
            return;
        }
        $policy = NetworkPolicy::get();
        echo '<div class="wrap"><h1>' . esc_html__('Utility Suite network policy', 'wp-utility-suite') . '</h1>';
        self::notice();
        echo '<p>' . esc_html__('These restrictions apply to all sites in this network. Existing site preferences are preserved.', 'wp-utility-suite') . '</p>';
        self::open('network', '', ['revision' => $policy['revision']]);
        foreach (self::labels() as $module => $label) {
            printf('<p><label><input type="checkbox" name="disabled[]" value="%s" %s> %s</label></p>', esc_attr($module),
                checked(in_array($module, $policy['disabled'], true), true, false),
                esc_html(sprintf(__('Pause %s across the network', 'wp-utility-suite'), $label)));
        }
        Panels::checkbox('outbound', __('Stop outbound traffic across the network', 'wp-utility-suite'), $policy['outbound']);
        if (!get_sites(['network_id' => get_current_network_id(), 'number' => 1, 'offset' => 20])) {
            Panels::checkbox('purge', __('Purge Utility Suite data on deletion for this network', 'wp-utility-suite'), $policy['purge']);
        }
        self::button(__('Save network policy', 'wp-utility-suite'), 'primary');
        Panels::networkSites(self::positive($_GET, 'offset'));
        echo '</div>';
    }

    private static function saveNetwork(): never
    {
        if (!is_multisite() || !current_user_can('manage_network_options')) {
            wp_die(esc_html__('You cannot change network settings.', 'wp-utility-suite'), '', ['response' => 403]);
        }
        try {
            $policy = [
                'disabled' => self::choices('disabled', array_keys(self::labels())),
                'outbound' => self::scalar($_POST, 'outbound', 1) === '1',
                'purge' => self::scalar($_POST, 'purge', 1) === '1' &&
                    !get_sites(['network_id' => get_current_network_id(), 'number' => 1, 'offset' => 20]),
            ];
            $result = self::scalar($_POST, 'revision', 19) === '' ? 'invalid'
                : NetworkPolicy::save($policy, self::optionalId('revision'));
        } catch (\InvalidArgumentException) {
            $result = 'invalid';
        }
        wp_safe_redirect(add_query_arg(['page' => 'wpus-network', 'wpus_status' => $result], network_admin_url('admin.php')), 303);
        exit;
    }

    private static function notice(): void
    {
        $code = self::scalar($_GET, 'wpus_status', 24);
        $messages = [
            'saved' => __('Changes saved.', 'wp-utility-suite'),
            'queued' => __('Work queued. Refresh this page for the latest status.', 'wp-utility-suite'),
            'conflict' => __('The record changed or already has pending work. Reload it before retrying.', 'wp-utility-suite'),
            'invalid' => __('Check the submitted values and try again.', 'wp-utility-suite'),
            'denied' => __('Your current permissions or module policy prevent this action.', 'wp-utility-suite'),
            'unavailable' => __('A required feature, dependency, or approved configuration is unavailable.', 'wp-utility-suite'),
            'busy' => __('A worker is busy. Try again shortly.', 'wp-utility-suite'),
            'limit' => __('The configured limit has been reached.', 'wp-utility-suite'),
            'error' => __('Utility Suite could not save the change. Retry and check Site Health if the problem persists.', 'wp-utility-suite'),
            'paused_unlogged' => __('Outbound is paused. The audit entry could not be saved.', 'wp-utility-suite'),
        ];
        if (isset($messages[$code])) {
            $ok = in_array($code, ['saved', 'queued'], true);
            printf('<div class="notice %s" role="status"><p>%s</p></div>', $ok ? 'notice-success' : 'notice-error', esc_html($messages[$code]));
        }
    }
}
