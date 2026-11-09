<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Panels
{
    public static function overview(bool $controls = false): void
    {
        echo '<ul>';
        foreach (Admin::visible() as $module => $label) {
            printf('<li><a href="%s">%s</a> — %s</li>', esc_url(Admin::url($module)), esc_html($label),
                esc_html(Admin::stateLabel(Policy::state($module))));
        }
        echo '</ul>';
        if (current_user_can('wpus_read_health')) {
            $health = Health::snapshot();
            self::table(['Check', 'State'], [
                [__('Background scheduler', 'wp-utility-suite'), $health['checks']['scheduler'] ?? 'setup'],
                [__('Last maintenance (UTC)', 'wp-utility-suite'), $health['last_maintenance_utc'] ?? '—'],
                [__('Waiting deliveries', 'wp-utility-suite'), $health['queue']['waiting'] ?? '—'],
                [__('Failed deliveries', 'wp-utility-suite'), $health['queue']['dead'] ?? '—'],
                [__('Dropped captures today', 'wp-utility-suite'), $health['queue']['dropped_today'] ?? '—'],
                [__('Outbound policy', 'wp-utility-suite'), $health['checks']['outbound'] ?? '—'],
            ]);
        }
        if ($controls && current_user_can('manage_options') && current_user_can('wpus_manage_modules')) {
            echo '<h2>' . esc_html__('Lifecycle controls', 'wp-utility-suite') . '</h2>';
            Admin::open('lifecycle', '');
            self::checkbox('paused', __('Pause all Utility Suite outbound traffic on this site', 'wp-utility-suite'), (bool) get_option('wpus_outbound_paused', false));
            if (!is_multisite()) {
                self::checkbox('purge', __('Delete Utility Suite data when WordPress uninstalls the plugin', 'wp-utility-suite'), (bool) get_option('wpus_purge', false));
            }
            Admin::button(__('Save lifecycle controls', 'wp-utility-suite'), 'primary');
        }
    }

    public static function networkSites(int $offset): void
    {
        echo '<h2>' . esc_html__('Site setup status', 'wp-utility-suite') . '</h2>';
        $sites = Lifecycle::sites($offset);
        $rows = [];
        foreach ($sites as $id) {
            $site = get_site($id);
            if ($site) {
                $ready = (int) get_blog_option((int) $id, 'wpus_schema_version', 0) === Schema::VERSION;
                $rows[] = [$site->domain . $site->path, $ready ? __('Ready', 'wp-utility-suite') : __('Setup pending', 'wp-utility-suite')];
            }
        }
        self::table(['Site', 'Setup'], $rows);
        $url = add_query_arg('page', 'wpus-network', network_admin_url('admin.php'));
        if ($offset) {
            self::link(add_query_arg('offset', max(0, $offset - 20), $url), __('Previous sites', 'wp-utility-suite'));
        }
        if (count($sites) === 20 && $offset < PHP_INT_MAX - 20) {
            self::link(add_query_arg('offset', $offset + 20, $url), __('More sites', 'wp-utility-suite'));
        }
    }

    public static function settings(string $module): void
    {
        $settings = Settings::get($module);
        $config = $settings['config'];
        $page = 'wpus-' . $module;
        $canToggle = current_user_can('wpus_manage_modules');
        $labels = [
            'enabled' => __('Select this module', 'wp-utility-suite'),
            'logins' => __('Audit privileged logins', 'wp-utility-suite'),
            'roles' => __('Audit role changes', 'wp-utility-suite'),
            'plugins' => __('Audit plugin activation, deactivation and removal', 'wp-utility-suite'),
            'retention' => __('Audit retention in days (1–90)', 'wp-utility-suite'),
            'login_alert' => __('Email login alerts to the site administration address', 'wp-utility-suite'),
            'slow_http' => __('Sample WordPress HTTP calls taking at least one second', 'wp-utility-suite'),
            'capture' => __('Capture and deliver subscribed events', 'wp-utility-suite'),
            'dashboard' => __('Show the status widget on the WordPress dashboard', 'wp-utility-suite'),
            'expiry' => __('Allow scheduled moves from Published to Draft', 'wp-utility-suite'),
            'expiry_types' => __('Post types eligible for expiration', 'wp-utility-suite'),
            'orders' => __('Publish order status events through CRM', 'wp-utility-suite'),
            'stock' => __('Publish low-stock transitions through CRM', 'wp-utility-suite'),
            'free_shipping' => __('Show an eligibility notice on classic cart and checkout', 'wp-utility-suite'),
            'record_404' => __('Aggregate eligible 404 requests', 'wp-utility-suite'),
            'redirects' => __('Apply same-site redirects on eligible 404 requests', 'wp-utility-suite'),
            'links' => __('Allow anonymous same-origin link checks', 'wp-utility-suite'),
        ];
        add_settings_section('wpus-options', __('Module settings', 'wp-utility-suite'), '__return_empty_string', $page);
        foreach (Settings::defaults()[$module] as $key => $default) {
            add_settings_field($key, $labels[$key], static function () use ($key, $config, $default, $canToggle): void {
                if (is_bool($default)) {
                    self::checkbox($key, __('Enabled', 'wp-utility-suite'), (bool) $config[$key], !$canToggle);
                } elseif ($key === 'expiry_types') {
                    foreach (['post' => __('Posts', 'wp-utility-suite'), 'page' => __('Pages', 'wp-utility-suite')] as $type => $label) {
                        printf('<label><input type="checkbox" name="expiry_types[]" value="%s" %s> %s</label> ',
                            esc_attr($type), checked(in_array($type, $config[$key], true), true, false), esc_html($label));
                    }
                } else {
                    self::input($key, __('Days', 'wp-utility-suite'), (string) $config[$key], 'number', true, 2);
                }
            }, $page, 'wpus-options');
        }
        Admin::open('settings', $module, ['revision' => $settings['revision']]);
        do_settings_sections($page);
        Admin::button(__('Save module settings', 'wp-utility-suite'), 'primary');
    }

    public static function audit(): void
    {
        $view = current_user_can('wpus_view_audit');
        $export = current_user_can('wpus_export_audit');
        if (!$view && !$export) {
            return;
        }
        $filter = Admin::auditFilter($_GET);
        if (!$filter) {
            echo '<p>' . esc_html__('Use a valid date range of at most 31 days.', 'wp-utility-suite') . '</p>';
            $filter = Admin::auditFilter([]);
        }
        [$from, $to, $event, $actor, $start, $until] = $filter;
        echo '<h2>' . esc_html__('Audit activity', 'wp-utility-suite') . '</h2><form method="get" action="' .
            esc_url(admin_url('admin.php')) . '"><input type="hidden" name="page" value="wpus"><input type="hidden" name="tab" value="security">';
        self::input('from', __('From (UTC)', 'wp-utility-suite'), $from, 'date', true);
        self::input('to', __('Through (UTC)', 'wp-utility-suite'), $to, 'date', true);
        self::input('event', __('Event code', 'wp-utility-suite'), $event, 'text', false, 40);
        self::input('actor', __('Actor user ID', 'wp-utility-suite'), $actor ? (string) $actor : '', 'number');
        Admin::button(__('Apply filters', 'wp-utility-suite'));
        if ($export) {
            Admin::open('audit_export', 'security', ['from' => $from, 'to' => $to, 'event' => $event, 'actor' => $actor]);
            Admin::button(__('Download filtered JSON Lines (up to 10,000 rows)', 'wp-utility-suite'));
        }
        if (!$view) {
            return;
        }
        $rows = Audit::search($start, $until, $event, $actor, 51, Admin::positive($_GET, 'before'));
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        self::table(['UTC', 'Event', 'Actor ID', 'Subject ID', 'Object', 'Details'], array_map(static fn (array $row): array => [
            $row['occurred_at'], $row['event_type'], $row['actor_user_id'], $row['subject_user_id'],
            trim(($row['object_kind'] ?? '') . ' ' . ($row['object_id'] ?? '')), $row['details_json'],
        ], $rows));
        if ($more) {
            self::link(Admin::url('security', ['from' => $from, 'to' => $to, 'event' => $event, 'actor' => $actor, 'before' => end($rows)['id']]),
                __('Older audit activity', 'wp-utility-suite'));
        }
    }

    public static function performance(): void
    {
        if (Policy::state('performance') !== 'active') {
            return;
        }
        $data = Performance::diagnostics();
        echo '<h2>' . esc_html__('Performance diagnostics', 'wp-utility-suite') . '</h2><p>' .
            esc_html(sprintf(__('Cron jobs more than five minutes overdue: %d', 'wp-utility-suite'), $data['overdue'])) . '</p>';
        self::table(['Autoloaded option', 'Bytes'], array_map(static fn (array $row): array => [$row['option_name'], $row['bytes']], $data['options']));
        $samples = Db::rows("SELECT details_json, observed_at FROM %i WHERE check_key = 'slow_http' ORDER BY observed_at DESC LIMIT 20", Db::table('monitoring_events'));
        echo '<h3>' . esc_html__('Recent slow HTTP samples', 'wp-utility-suite') . '</h3>';
        self::table(['UTC', 'Milliseconds', 'Result'], array_map(static function (array $row): array {
            $details = Db::decode($row['details_json']);
            return [$row['observed_at'], $details['milliseconds'] ?? '', $details['class'] ?? ''];
        }, $samples));
        echo '<h2>' . esc_html__('Cleanup of expired Utility Suite records', 'wp-utility-suite') . '</h2>';
        Admin::open('cleanup_preview', 'performance');
        Admin::button(__('Preview expired audit and monitoring records', 'wp-utility-suite'));
        if ($preview = Performance::cleanupPreview()) {
            $counts = array_map('count', Db::decode($preview['args_json']));
            echo '<p>' . esc_html(sprintf(__('Preview contains %1$d audit records and %2$d monitoring records. It expires at %3$s UTC.', 'wp-utility-suite'),
                $counts['audit_logs'] ?? 0, $counts['monitoring_events'] ?? 0, $preview['expires_at'])) . '</p>';
            Admin::open('cleanup_apply', 'performance', ['id' => $preview['id'], 'revision' => $preview['revision']]);
            Admin::button(__('Apply this cleanup preview', 'wp-utility-suite'));
        }
        self::table(['Recent operation', 'State', 'Result'], array_map(static fn (array $row): array => [
            $row['public_id'], $row['state'], $row['result_code'],
        ], Operations::recent('cleanup')));
    }

    public static function notes(): void
    {
        if (Policy::state('admin_tools') !== 'active') {
            return;
        }
        echo '<h2>' . esc_html__('Private technical notes', 'wp-utility-suite') . '</h2><p>' .
            esc_html__('Keep credentials and customer information out of notes. A linked note also requires permission to edit its post or page.', 'wp-utility-suite') . '</p>';
        $write = Policy::canManage('admin_tools');
        if ($write) {
            echo '<details><summary>' . esc_html__('Add a note', 'wp-utility-suite') . '</summary>';
            self::noteForm([]);
            echo '</details>';
        }
        $notes = Notes::list(Admin::positive($_GET, 'before') ?: PHP_INT_MAX);
        foreach ($notes['items'] as $note) {
            echo '<details><summary><strong>' . esc_html($note['title']) . '</strong> — ' . esc_html($note['updated_at']) . ' UTC</summary>';
            echo '<p style="white-space:pre-wrap">' . esc_html($note['body_text']) . '</p>';
            if ($write) {
                self::noteForm($note);
                Admin::open('note_delete', 'admin_tools', ['id' => $note['id'], 'revision' => $note['revision']]);
                Admin::button(__('Delete this note', 'wp-utility-suite'));
            }
            echo '</details>';
        }
        if ($notes['next']) {
            self::link(Admin::url('admin_tools', ['before' => $notes['next']]), __('Older notes', 'wp-utility-suite'));
        }
        if (!$write) {
            return;
        }
        echo '<h2>' . esc_html__('Content expiration', 'wp-utility-suite') . '</h2>';
        if (Policy::feature('admin_tools', 'expiry') && Queue::available()) {
            echo '<p>' . esc_html(sprintf(__('Time zone: %s. An unchanged, eligible published post will move to Draft.', 'wp-utility-suite'), wp_timezone_string())) . '</p>';
            Admin::open('expire', 'admin_tools');
            self::input('post_id', __('Post or page ID', 'wp-utility-suite'), '', 'number', true);
            self::input('when', __('Local expiration time', 'wp-utility-suite'), '', 'datetime-local', true);
            Admin::button(__('Schedule expiration', 'wp-utility-suite'));
        }
        foreach (Operations::recent('expiry') as $operation) {
            printf('<p><code>%s</code> — %s — %s — %s UTC</p>', esc_html($operation['public_id']),
                esc_html((string) $operation['object_id']), esc_html($operation['state'] . ' ' . $operation['result_code']), esc_html($operation['run_at']));
            if ($operation['state'] === 'pending') {
                Admin::open('expiry_cancel', 'admin_tools', ['id' => $operation['id'], 'revision' => $operation['revision']]);
                Admin::button(__('Cancel scheduled expiration', 'wp-utility-suite'));
            }
        }
    }

    private static function noteForm(array $note): void
    {
        Admin::open('note_save', 'admin_tools', $note ? ['id' => $note['id'], 'revision' => $note['revision']] : []);
        self::input('title', __('Title', 'wp-utility-suite'), $note['title'] ?? '', 'text', true, 160);
        self::input('post_id', __('Linked post or page ID (optional)', 'wp-utility-suite'), (string) ($note['post_id'] ?? ''), 'number');
        self::textarea('body', __('Note', 'wp-utility-suite'), $note['body_text'] ?? '', 16384, true);
        Admin::button($note ? __('Update note', 'wp-utility-suite') : __('Create note', 'wp-utility-suite'));
    }

    public static function monitoring(): void
    {
        if (current_user_can('wpus_read_health')) {
            echo '<h2>' . esc_html__('Health endpoint', 'wp-utility-suite') . '</h2><p><code>' . esc_html(rest_url('wpus/v1/health')) . '</code></p>';
        }
        if (!Policy::canManage('monitoring') || Policy::state('monitoring') !== 'active') {
            return;
        }
        echo '<h2>' . esc_html__('Daily 404 aggregates', 'wp-utility-suite') . '</h2>';
        self::table(['UTC day', 'Hits', 'Buckets', 'Budget'], array_map(static fn (array $row): array => [
            $row['day_utc'], $row['hits'], $row['buckets'],
            !empty($row['hit_limit_reached']) ? __('Hit limit reached', 'wp-utility-suite')
                : (!empty($row['path_overflow']) ? __('Path overflow', 'wp-utility-suite') : '—'),
        ], Monitoring::aggregates()));
        echo '<h2>' . esc_html__('Exact-path redirects', 'wp-utility-suite') . '</h2>';
        if (Monitoring::conflict()) {
            echo '<p>' . esc_html__('A redirect owner was detected. Utility Suite redirects are paused.', 'wp-utility-suite') . '</p>';
        }
        echo '<p>' . esc_html__('Enter paths including the site’s base path. Rules apply to front-end 404 requests after WordPress canonical handling. Clear affected page caches after changes.', 'wp-utility-suite') . '</p>';
        echo '<details><summary>' . esc_html__('Add a redirect', 'wp-utility-suite') . '</summary>';
        self::ruleForm([]);
        echo '</details>';
        foreach (Monitoring::rules() as $rule) {
            printf('<details><summary>%s → %s (%d)</summary>', esc_html($rule['source_path']), esc_html($rule['target_path']), (int) $rule['status_code']);
            self::ruleForm($rule);
            Admin::open('redirect_delete', 'monitoring', ['id' => $rule['id'], 'revision' => $rule['revision']]);
            Admin::button(__('Delete this redirect', 'wp-utility-suite'));
            echo '</details>';
        }
        echo '<h2>' . esc_html__('Internal link checks', 'wp-utility-suite') . '</h2>';
        if (Policy::feature('monitoring', 'links')) {
            Admin::open('crawl', 'monitoring');
            Admin::button(__('Check up to 20 links from recent published content', 'wp-utility-suite'));
        }
        self::table(['Run', 'State', 'Result'], array_map(static fn (array $row): array => [$row['public_id'], $row['state'], $row['result_code']], Operations::recent('crawl')));
        $last = Db::row("SELECT details_json, observed_at FROM %i WHERE check_key = 'links' ORDER BY observed_at DESC LIMIT 1", Db::table('monitoring_events'));
        if ($last) {
            self::table(['Latest report (UTC)', 'Counts'], [[$last['observed_at'], $last['details_json']]]);
        }
    }

    private static function ruleForm(array $rule): void
    {
        Admin::open('redirect_save', 'monitoring', $rule ? ['id' => $rule['id'], 'revision' => $rule['revision']] : []);
        self::input('source_path', __('Source path', 'wp-utility-suite'), $rule['source_path'] ?? '', 'text', true, 1024);
        self::input('target_path', __('Target path', 'wp-utility-suite'), $rule['target_path'] ?? '', 'text', true, 1024);
        printf('<p><label>%s <select name="status_code"><option value="302" %s>302</option><option value="301" %s>301</option></select></label></p>',
            esc_html__('HTTP status', 'wp-utility-suite'), selected((int) ($rule['status_code'] ?? 302), 302, false),
            selected((int) ($rule['status_code'] ?? 302), 301, false));
        Admin::button($rule ? __('Update redirect', 'wp-utility-suite') : __('Create redirect', 'wp-utility-suite'));
    }

    public static function commerce(): void
    {
        echo '<p>' . esc_html(Policy::commerceReady()
            ? __('Order and stock events require an active CRM subscription. The optional shipping notice uses current rates on classic cart and checkout.', 'wp-utility-suite')
            : __('Install and activate WooCommerce 11.1 or newer to use this module.', 'wp-utility-suite')) . '</p>';
    }

    public static function crm(): void
    {
        if (!Crm::available()) {
            echo '<p>' . esc_html__('Configure a host keyring, an active key and approved HTTPS origins. Enable cURL and libsodium and verify Action Scheduler is available. See the plugin readme.', 'wp-utility-suite') . '</p>';
        }
        if (!Policy::outbound()) {
            echo '<p>' . esc_html__('Outbound traffic is paused by site, network or host policy.', 'wp-utility-suite') . '</p>';
        }
        $manage = Policy::canManage('crm');
        $active = Policy::state('crm') === 'active';
        $rotate = current_user_can('wpus_rotate_secrets');
        if ($manage) {
            echo '<h2>' . esc_html__('Destinations', 'wp-utility-suite') . '</h2>';
            if ($active && $rotate) {
                echo '<details><summary>' . esc_html__('Add a destination', 'wp-utility-suite') . '</summary>';
                self::destinationForm([], true);
                echo '</details>';
            }
            foreach (Crm::destinations() as $row) {
                $verified = $row['verified_generation'] === $row['generation'];
                printf('<details><summary>%s — %s — %s — %s</summary>', esc_html($row['label']),
                    esc_html($row['endpoint_origin']), esc_html($row['state']),
                    esc_html($verified ? __('Verified', 'wp-utility-suite') : __('Test required', 'wp-utility-suite')));
                if ($active) {
                    self::destinationForm($row, $rotate);
                    Admin::open('destination_test', 'crm', ['id' => $row['id'], 'revision' => $row['revision']]);
                    Admin::button(__('Queue a signed test delivery', 'wp-utility-suite'));
                }
                if ($active && $verified && $row['state'] !== 'active') {
                    self::destinationState($row, 'active', __('Enable delivery', 'wp-utility-suite'));
                }
                if ($row['state'] === 'active') {
                    self::destinationState($row, 'paused', __('Pause this destination', 'wp-utility-suite'));
                }
                if ($rotate) {
                    self::destinationState($row, 'revoked', __('Revoke and remove stored credentials', 'wp-utility-suite'));
                }
                echo '</details>';
            }
        }
        if (!current_user_can('wpus_view_deliveries')) {
            return;
        }
        echo '<h2>' . esc_html__('Recent deliveries', 'wp-utility-suite') . '</h2>';
        $rows = Crm::deliveries();
        if (!$rows) {
            echo '<p>' . esc_html__('No deliveries recorded.', 'wp-utility-suite') . '</p>';
        }
        foreach ($rows as $row) {
            printf('<details><summary>%s — %s — %s (%d)</summary><p><code>%s</code> — %s UTC</p>',
                esc_html($row['label']), esc_html($row['event_type']), esc_html($row['state']), (int) $row['attempt_count'],
                esc_html($row['delivery_uuid']), esc_html($row['created_at']));
            self::table(['Attempt', 'UTC', 'Result', 'HTTP status', 'Milliseconds'], array_map(static fn (array $attempt): array => [
                $attempt['attempt_no'], $attempt['started_at'], $attempt['result'], $attempt['http_status'], $attempt['latency_ms'],
            ], Crm::attempts((int) $row['id'])));
            if ($manage && $active && $row['state'] === 'dead' && (int) $row['cycle_count'] === 1) {
                Admin::open('delivery_retry', 'crm', ['id' => $row['id']]);
                Admin::button(__('Redrive eligible delivery', 'wp-utility-suite'));
            }
            echo '</details>';
        }
    }

    private static function destinationForm(array $row, bool $rotate): void
    {
        Admin::open('destination_save', 'crm', $row ? ['id' => $row['id'], 'revision' => $row['revision']] : []);
        self::input('label', __('Label', 'wp-utility-suite'), $row['label'] ?? '', 'text', true, 80);
        if ($rotate) {
            self::input('endpoint', $row ? __('Replacement HTTPS URL (optional)', 'wp-utility-suite') : __('Approved HTTPS URL', 'wp-utility-suite'), '', 'password', !$row, 2048);
            self::input('signing', $row ? __('Replacement signing secret (optional)', 'wp-utility-suite') : __('Signing secret (32–128 URL-safe characters)', 'wp-utility-suite'), '', 'password', !$row, 128);
        }
        $events = Db::decode($row['event_types_json'] ?? null);
        foreach (array_keys(Crm::EVENTS) as $event) {
            printf('<p><label><input type="checkbox" name="events[]" value="%s" %s> <code>%s</code></label></p>',
                esc_attr($event), checked(in_array($event, $events, true), true, false), esc_html($event));
        }
        self::textarea('mapping', __('Optional JSON field aliases, such as {"content_id":"post_id"}', 'wp-utility-suite'), $row['mapping_json'] ?? '', 4096);
        Admin::button($row ? __('Save destination changes', 'wp-utility-suite') : __('Create disabled destination', 'wp-utility-suite'));
    }

    private static function destinationState(array $row, string $state, string $label): void
    {
        Admin::open('destination_state', 'crm', ['id' => $row['id'], 'revision' => $row['revision'], 'next_state' => $state]);
        Admin::button($label);
    }

    public static function checkbox(string $name, string $label, bool $value, bool $disabled = false): void
    {
        printf('<p><label><input type="checkbox" name="%s" value="1" %s %s> %s</label></p>', esc_attr($name),
            checked($value, true, false), disabled($disabled, true, false), esc_html($label));
    }

    private static function input(string $name, string $label, string $value, string $type = 'text', bool $required = false, int $length = 0): void
    {
        printf('<p><label>%s <input type="%s" name="%s" value="%s"%s%s%s></label></p>', esc_html($label), esc_attr($type),
            esc_attr($name), esc_attr($value), $required ? ' required' : '', $length ? ' maxlength="' . (int) $length . '"' : '',
            $type === 'password' ? ' autocomplete="new-password"' : '');
    }

    private static function textarea(string $name, string $label, string $value, int $length, bool $required = false): void
    {
        printf('<p><label>%s<br><textarea name="%s" rows="4" class="large-text" maxlength="%d"%s>%s</textarea></label></p>',
            esc_html($label), esc_attr($name), $length, $required ? ' required' : '', esc_textarea($value));
    }

    private static function table(array $headings, array $rows): void
    {
        if (!$rows) {
            echo '<p>' . esc_html__('No records to display.', 'wp-utility-suite') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr>';
        foreach ($headings as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . esc_html((string) $cell) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function link(string $url, string $text): void
    {
        printf('<p><a href="%s">%s</a></p>', esc_url($url), esc_html($text));
    }
}
