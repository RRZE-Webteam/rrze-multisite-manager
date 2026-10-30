<?php

namespace RRZE\MultisiteManager;

use RRZE\MultisiteManager\Metrics\DashboardMetricsHistoryStore;

defined('ABSPATH') || exit;

class WebsiteStatusReportService {
    public const CRON_HOOK = 'rrze_msm_send_website_status_report';
    protected const META_ARCHIVED_AT = 'rrze_msm_archived_at';
    protected const META_DELETED_AT = 'rrze_msm_deleted_at';
    protected const META_OPERATIONAL_STATUS = 'rrze_msm_operational_status';
    protected const META_PREVIOUS_OPERATIONAL_STATUS = 'rrze_msm_previous_operational_status';
    protected const META_OPERATIONAL_STATUS_CHANGED_AT = 'rrze_msm_operational_status_changed_at';
    protected const LAST_REPORT_SENT_OPTION = 'rrze_msm_website_status_report_last_sent_at';
    protected Config $config;

    public function __construct(?Config $config = null) {
        $this->config = $config ?? new Config();
    }

    public function onLoaded(): void {
        add_action(self::CRON_HOOK, [$this, 'runScheduledReport']);
        add_action('init', [$this, 'ensureScheduledReport'], 30);
    }

    public function ensureScheduledReport(): void {
        if (!$this->isMainSiteContext()) {
            return;
        }

        if (!$this->isEnabled()) {
            $this->clearScheduledReport();
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $this->scheduleNextReport();
        }
    }

    public function reschedule(): void {
        $this->runOnMainSite(function (): void {
            $this->clearScheduledReport();

            if ($this->isEnabled()) {
                $this->scheduleNextReport();
            }
        });
    }

    public function runScheduledReport(): void {
        if (!$this->isMainSiteContext() || !$this->isEnabled()) {
            return;
        }

        $this->sendReport();
        $this->clearScheduledReport();
        $this->scheduleNextReport();
    }

    public function sendReport(): bool {
        $options = $this->getOptions();
        $recipient = $this->getRecipient($options);

        if (!is_email($recipient)) {
            return false;
        }

        $subject = $this->replaceSubjectPlaceholders((string)$options['reporting_website_status_report_subject']);
        $subject = trim((string)preg_replace('/[\r\n]+/', '', wp_strip_all_tags($subject)));

        if ($subject === '') {
            $subject = $this->replaceSubjectPlaceholders('MSM Report: Website-Status $serverdomain');
        }

        $bodies = $this->buildReportBodies();
        $configureMailer = static function (object $phpmailer) use ($bodies): void {
            $phpmailer->isHTML(true);
            $phpmailer->AltBody = $bodies['text'];
        };

        add_action('phpmailer_init', $configureMailer);

        try {
            $sent = wp_mail(
                $recipient,
                $subject,
                $bodies['html'],
                ['Content-Type: text/html; charset=UTF-8']
            );

            if ($sent) {
                update_site_option(self::LAST_REPORT_SENT_OPTION, time());
            }

            return $sent;
        } finally {
            remove_action('phpmailer_init', $configureMailer);
        }
    }

    protected function getOptions(): array {
        $stored = get_site_option($this->config->getOptionName(), []);
        $stored = is_array($stored) ? $stored : [];

        return wp_parse_args($stored, [
            'reporting_website_status_report_enabled' => false,
            'reporting_website_status_report_frequency' => 'weekly',
            'reporting_website_status_report_weekday' => '1',
            'reporting_website_status_report_time' => '09:00',
            'reporting_website_status_report_recipient' => '',
            'reporting_website_status_report_subject' => 'MSM Report: Website-Status $serverdomain',
        ]);
    }

    protected function isEnabled(): bool {
        return (bool)($this->getOptions()['reporting_website_status_report_enabled'] ?? false);
    }

    protected function clearScheduledReport(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    protected function scheduleNextReport(): void {
        $timestamp = $this->getNextScheduledTimestamp();

        if ($timestamp > time()) {
            wp_schedule_single_event($timestamp, self::CRON_HOOK);
        }
    }

    protected function getNextScheduledTimestamp(): int {
        $options = $this->getOptions();
        $frequency = (string)($options['reporting_website_status_report_frequency'] ?? 'weekly');
        $weekday = min(6, max(0, (int)($options['reporting_website_status_report_weekday'] ?? 1)));
        $time = (string)($options['reporting_website_status_report_time'] ?? '09:00');
        [$hour, $minute] = $this->parseTime($time);
        $now = new \DateTimeImmutable('now', wp_timezone());

        if ($frequency === 'monthly') {
            $candidate = $this->getFirstWeekdayOfMonth($now, $weekday, $hour, $minute);

            if ($candidate <= $now) {
                $candidate = $this->getFirstWeekdayOfMonth($now->modify('first day of next month'), $weekday, $hour, $minute);
            }

            return $candidate->getTimestamp();
        }

        $candidate = $now->setTime($hour, $minute);
        $daysUntilWeekday = ($weekday - (int)$candidate->format('w') + 7) % 7;
        $candidate = $candidate->modify('+' . $daysUntilWeekday . ' days');

        if ($candidate <= $now) {
            $candidate = $candidate->modify('+' . ($frequency === 'biweekly' ? 14 : 7) . ' days');
        }

        return $candidate->getTimestamp();
    }

    protected function getFirstWeekdayOfMonth(\DateTimeImmutable $date, int $weekday, int $hour, int $minute): \DateTimeImmutable {
        $candidate = $date->modify('first day of this month')->setTime($hour, $minute);
        $daysUntilWeekday = ($weekday - (int)$candidate->format('w') + 7) % 7;

        return $candidate->modify('+' . $daysUntilWeekday . ' days');
    }

    /** @return array{0: int, 1: int} */
    protected function parseTime(string $time): array {
        if (preg_match('/^(?<hour>[01][0-9]|2[0-3]):(?<minute>[0-5][0-9])$/', $time, $matches) !== 1) {
            return [9, 0];
        }

        return [(int)$matches['hour'], (int)$matches['minute']];
    }

    /** @return array{html: string, text: string} */
    protected function buildReportBodies(): array {
        $now = new \DateTimeImmutable('now', wp_timezone());
        $periodStart = $this->getReportPeriodStart($now);
        $sites = get_sites(['number' => 0]);
        $history = new DashboardMetricsHistoryStore();
        $currentSnapshots = [];
        $previousSnapshots = [];

        try {
            $currentSnapshots = $history->getCurrentSnapshots();
            $previousSnapshots = $history->getLatestStatisticSnapshotsAtOrBefore($periodStart->getTimestamp());
        } catch (\RuntimeException) {
            // The report remains useful when metric storage is being repaired.
        }
        $statusCounts = [
            'active' => 0,
            'archived' => 0,
            'blocked' => 0,
            'deleted' => 0,
            'dns_missing' => 0,
        ];
        $newSites = [];
        $archivedSites = [];
        $deletedSites = [];
        $dnsMissingSites = [];
        $newAvailabilityProblems = [];
        $resolvedAvailabilityProblems = [];
        $missingMetricSites = [];
        $capacitySites = [];

        foreach ($sites as $site) {
            if (!$site instanceof \WP_Site) {
                continue;
            }

            $siteId = (int)$site->blog_id;
            $url = get_home_url($siteId, '/');
            $siteData = $this->getReportSiteData($site, $url);
            $currentSnapshot = (array)($currentSnapshots[$siteId] ?? []);
            $previousSnapshot = (array)($previousSnapshots[$siteId] ?? []);
            $siteData = $this->addCapacityData($siteData, $currentSnapshot, $previousSnapshot);
            $registeredAt = $this->getUtcTimestamp((string)$site->registered);
            $archivedAt = $this->getUtcTimestamp((string)get_site_meta($siteId, self::META_ARCHIVED_AT, true));
            $deletedAt = $this->getUtcTimestamp((string)get_site_meta($siteId, self::META_DELETED_AT, true));
            $operationalStatus = (string)get_site_meta($siteId, self::META_OPERATIONAL_STATUS, true);
            $previousOperationalStatus = (string)get_site_meta($siteId, self::META_PREVIOUS_OPERATIONAL_STATUS, true);
            $operationalStatusChangedAt = $this->getUtcTimestamp((string)get_site_meta($siteId, self::META_OPERATIONAL_STATUS_CHANGED_AT, true));

            if ((int)$site->deleted === 1) {
                $statusCounts['deleted']++;
            } elseif ((int)$site->spam === 1) {
                $statusCounts['blocked']++;
            } elseif ((int)$site->archived === 1) {
                $statusCounts['archived']++;
            } else {
                $statusCounts['active']++;
            }

            if ($operationalStatus === 'dns_missing') {
                $statusCounts['dns_missing']++;
                $dnsMissingSites[] = $siteData;
            }

            if ($registeredAt >= $periodStart->getTimestamp()) {
                $newSites[] = $siteData;
            }

            if ((int)$site->archived === 1 && $archivedAt >= $periodStart->getTimestamp()) {
                $archivedSites[] = $siteData;
            }

            if ((int)$site->deleted === 1 && $deletedAt >= $periodStart->getTimestamp()) {
                $deletedSites[] = $siteData;
            }

            if (empty($currentSnapshot)) {
                $missingMetricSites[] = $siteData;
            } elseif ($siteData['total_bytes'] !== null) {
                $capacitySites[] = $siteData;
            }

            if ($operationalStatusChangedAt < $periodStart->getTimestamp() || $previousOperationalStatus === $operationalStatus) {
                continue;
            }

            $siteData['status_change'] = $this->getOperationalStatusLabel($previousOperationalStatus) . ' → ' . $this->getOperationalStatusLabel($operationalStatus);
            if (in_array($operationalStatus, ['dns_missing', 'unreachable'], true)) {
                $newAvailabilityProblems[] = $siteData;
            } elseif ($operationalStatus === 'healthy' && in_array($previousOperationalStatus, ['dns_missing', 'unreachable'], true)) {
                $resolvedAvailabilityProblems[] = $siteData;
            }
        }

        $overviewUrl = network_admin_url('admin.php?page=' . $this->config->getMenuSettings()['site_overview_slug']);
        $statusRows = [
            __('Active websites', 'rrze-multisite-manager') => $statusCounts['active'],
            __('Archived websites', 'rrze-multisite-manager') => $statusCounts['archived'],
            __('Blocked websites', 'rrze-multisite-manager') => $statusCounts['blocked'],
            __('Sites marked for deletion', 'rrze-multisite-manager') => $statusCounts['deleted'],
            __('Websites with missing DNS', 'rrze-multisite-manager') => $statusCounts['dns_missing'],
        ];

        $periodLabel = sprintf(
            /* translators: 1: start date, 2: end date. */
            __('Reporting period: %1$s to %2$s', 'rrze-multisite-manager'),
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), $periodStart->getTimestamp()),
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), $now->getTimestamp())
        );
        $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f1f3f5;color:#1d2327;font-family:Arial,sans-serif;font-size:15px;line-height:1.5">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:24px 12px">';
        $html .= '<table role="presentation" width="680" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:680px;background:#ffffff;border:1px solid #dcdcde;border-radius:8px">';
        $html .= '<tr><td style="padding:28px 30px 12px;background:#135e96;color:#ffffff;border-radius:8px 8px 0 0">';
        $html .= '<h1 style="margin:0;font-size:26px;line-height:1.25;color:#ffffff">' . esc_html__('MSM website status report', 'rrze-multisite-manager') . '</h1>';
        $html .= '<p style="margin:8px 0 0;color:#e5f3ff">' . esc_html($periodLabel) . '</p></td></tr>';
        $html .= '<tr><td style="padding:8px 30px 30px">';
        $html .= '<h2 style="margin:24px 0 12px;font-size:20px;line-height:1.3;color:#1d2327">' . esc_html__('Status', 'rrze-multisite-manager') . '</h2>';
        $html .= $this->getEmailTableOpen() . '<thead><tr>' . $this->getEmailTableHeader(__('Status', 'rrze-multisite-manager')) . $this->getEmailTableHeader(__('Websites', 'rrze-multisite-manager'), true) . '</tr></thead><tbody>';
        $text = __('MSM website status report', 'rrze-multisite-manager') . "\n" . $periodLabel . "\n\n";
        $text .= __('Status', 'rrze-multisite-manager') . "\n";

        foreach ($statusRows as $label => $count) {
            $html .= '<tr><td style="padding:9px 10px;border-bottom:1px solid #e5e7eb">' . esc_html($label) . '</td><td style="padding:9px 10px;border-bottom:1px solid #e5e7eb;text-align:right;font-variant-numeric:tabular-nums">' . esc_html(number_format_i18n($count)) . '</td></tr>';
            $text .= $label . ': ' . number_format_i18n($count) . "\n";
        }

        $html .= '</tbody></table>';
        $html .= '<p style="margin:18px 0 0"><a href="' . esc_url($overviewUrl) . '" style="display:inline-block;padding:9px 14px;background:#135e96;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold">' . esc_html__('Open MSM website overview', 'rrze-multisite-manager') . '</a></p>';
        $text .= "\n" . __('Open MSM website overview', 'rrze-multisite-manager') . ': ' . $overviewUrl . "\n";

        if (!empty($newAvailabilityProblems)) {
            $html .= $this->buildOperationalChangeTable(__('New availability problems', 'rrze-multisite-manager'), $newAvailabilityProblems);
            $text .= $this->buildOperationalChangeTextTable(__('New availability problems', 'rrze-multisite-manager'), $newAvailabilityProblems);
        }

        if (!empty($resolvedAvailabilityProblems)) {
            $html .= $this->buildOperationalChangeTable(__('Resolved availability problems', 'rrze-multisite-manager'), $resolvedAvailabilityProblems);
            $text .= $this->buildOperationalChangeTextTable(__('Resolved availability problems', 'rrze-multisite-manager'), $resolvedAvailabilityProblems);
        }

        if (!empty($missingMetricSites)) {
            $html .= $this->buildHtmlSiteTable(__('Websites without current dashboard metrics', 'rrze-multisite-manager'), $missingMetricSites, true);
            $text .= $this->buildTextSiteTable(__('Websites without current dashboard metrics', 'rrze-multisite-manager'), $missingMetricSites, true);
        }

        if (!empty($capacitySites)) {
            $html .= $this->buildCapacitySection($capacitySites);
            $text .= $this->buildCapacityTextSection($capacitySites);
        }

        foreach ([
            [__('Newly registered websites', 'rrze-multisite-manager'), $newSites, true],
            [__('Websites archived during the reporting period', 'rrze-multisite-manager'), $archivedSites, true],
            [__('Sites marked for deletion during the reporting period', 'rrze-multisite-manager'), $deletedSites, true],
            [__('Websites with missing DNS', 'rrze-multisite-manager'), $dnsMissingSites, false],
        ] as [$title, $sectionSites, $includeLogin]) {
            if (empty($sectionSites)) {
                continue;
            }

            $html .= $this->buildHtmlSiteTable($title, $sectionSites, $includeLogin);
            $text .= $this->buildTextSiteTable($title, $sectionSites, $includeLogin);
        }

        return [
            'html' => $html . '</td></tr></table></td></tr></table></body></html>',
            'text' => $text,
        ];
    }

    protected function getReportPeriodStart(\DateTimeImmutable $now): \DateTimeImmutable {
        $lastReportTimestamp = max(0, (int)get_site_option(self::LAST_REPORT_SENT_OPTION, 0));
        if ($lastReportTimestamp > 0 && $lastReportTimestamp < $now->getTimestamp()) {
            return (new \DateTimeImmutable('@' . $lastReportTimestamp))->setTimezone(wp_timezone());
        }

        return $this->getPeriodStart($now);
    }

    protected function getPeriodStart(\DateTimeImmutable $now): \DateTimeImmutable {
        $frequency = (string)($this->getOptions()['reporting_website_status_report_frequency'] ?? 'weekly');

        if ($frequency === 'monthly') {
            return $now->modify('-1 month');
        }

        return $now->modify($frequency === 'biweekly' ? '-14 days' : '-7 days');
    }

    /** @param array{title: string, url: string, admin_email: string, dashboard_url: string} $site */
    protected function addCapacityData(array $site, array $currentSnapshot, array $previousSnapshot): array {
        $mediaBytes = is_numeric($currentSnapshot['media_bytes'] ?? null) ? max(0, (int)$currentSnapshot['media_bytes']) : null;
        $databaseBytes = is_numeric($currentSnapshot['database_bytes'] ?? null) ? max(0, (int)$currentSnapshot['database_bytes']) : null;
        $previousMediaBytes = is_numeric($previousSnapshot['media_bytes'] ?? null) ? max(0, (int)$previousSnapshot['media_bytes']) : null;
        $previousDatabaseBytes = is_numeric($previousSnapshot['database_bytes'] ?? null) ? max(0, (int)$previousSnapshot['database_bytes']) : null;
        $totalBytes = $mediaBytes === null && $databaseBytes === null ? null : (int)$mediaBytes + (int)$databaseBytes;
        $previousTotalBytes = $previousMediaBytes === null && $previousDatabaseBytes === null
            ? null
            : (int)$previousMediaBytes + (int)$previousDatabaseBytes;

        $site['media_bytes'] = $mediaBytes;
        $site['database_bytes'] = $databaseBytes;
        $site['total_bytes'] = $totalBytes;
        $site['growth_bytes'] = $totalBytes !== null && $previousTotalBytes !== null ? $totalBytes - $previousTotalBytes : null;

        return $site;
    }

    /** @param array<int, array<string, mixed>> $sites */
    protected function buildCapacitySection(array $sites): string {
        $mediaBytes = array_sum(array_map(static fn(array $site): int => (int)($site['media_bytes'] ?? 0), $sites));
        $databaseBytes = array_sum(array_map(static fn(array $site): int => (int)($site['database_bytes'] ?? 0), $sites));
        $growthSites = array_values(array_filter($sites, static fn(array $site): bool => is_int($site['growth_bytes'] ?? null)));
        $growthBytes = array_sum(array_map(static fn(array $site): int => (int)$site['growth_bytes'], $growthSites));
        usort($sites, static fn(array $left, array $right): int => (int)$right['total_bytes'] <=> (int)$left['total_bytes']);
        usort($growthSites, static fn(array $left, array $right): int => (int)$right['growth_bytes'] <=> (int)$left['growth_bytes']);

        $html = $this->getEmailSectionHeading(__('Capacity', 'rrze-multisite-manager'));
        $html .= $this->getEmailTableOpen() . '<thead><tr>' . $this->getEmailTableHeader(__('Media', 'rrze-multisite-manager'), true) . $this->getEmailTableHeader(__('Database', 'rrze-multisite-manager'), true) . $this->getEmailTableHeader(__('Total', 'rrze-multisite-manager'), true);
        if (!empty($growthSites)) {
            $html .= $this->getEmailTableHeader(__('Change since the previous report', 'rrze-multisite-manager'), true);
        }
        $html .= '</tr></thead><tbody><tr><td style="padding:11px 10px;text-align:right;font-weight:bold">' . esc_html(size_format($mediaBytes)) . '</td><td style="padding:11px 10px;text-align:right;font-weight:bold">' . esc_html(size_format($databaseBytes)) . '</td><td style="padding:11px 10px;text-align:right;font-weight:bold">' . esc_html(size_format($mediaBytes + $databaseBytes)) . '</td>';
        if (!empty($growthSites)) {
            $html .= '<td style="padding:11px 10px;text-align:right;font-weight:bold">' . esc_html($this->formatSignedSize($growthBytes)) . '</td>';
        }
        $html .= '</tr></tbody></table>';
        $html .= $this->buildCapacitySiteTable(__('Top 10 websites by storage usage', 'rrze-multisite-manager'), array_slice($sites, 0, 10), false);

        if (!empty($growthSites)) {
            $html .= $this->buildCapacitySiteTable(__('Top 10 websites by storage growth', 'rrze-multisite-manager'), array_slice($growthSites, 0, 10), true);
        }

        return $html;
    }

    /** @param array<int, array<string, mixed>> $sites */
    protected function buildCapacityTextSection(array $sites): string {
        $mediaBytes = array_sum(array_map(static fn(array $site): int => (int)($site['media_bytes'] ?? 0), $sites));
        $databaseBytes = array_sum(array_map(static fn(array $site): int => (int)($site['database_bytes'] ?? 0), $sites));
        $growthSites = array_values(array_filter($sites, static fn(array $site): bool => is_int($site['growth_bytes'] ?? null)));
        usort($sites, static fn(array $left, array $right): int => (int)$right['total_bytes'] <=> (int)$left['total_bytes']);
        usort($growthSites, static fn(array $left, array $right): int => (int)$right['growth_bytes'] <=> (int)$left['growth_bytes']);
        $text = "\n" . __('Capacity', 'rrze-multisite-manager') . "\n";
        $text .= __('Media', 'rrze-multisite-manager') . ': ' . size_format($mediaBytes) . "\n";
        $text .= __('Database', 'rrze-multisite-manager') . ': ' . size_format($databaseBytes) . "\n";
        $text .= __('Total', 'rrze-multisite-manager') . ': ' . size_format($mediaBytes + $databaseBytes) . "\n";
        $text .= "\n" . __('Top 10 websites by storage usage', 'rrze-multisite-manager') . "\n";

        foreach (array_slice($sites, 0, 10) as $site) {
            $text .= '- ' . $site['title'] . ': ' . size_format((int)$site['total_bytes']) . ' (' . __('Media', 'rrze-multisite-manager') . ': ' . size_format((int)$site['media_bytes']) . ', ' . __('Database', 'rrze-multisite-manager') . ': ' . size_format((int)$site['database_bytes']) . ")\n";
        }

        if (!empty($growthSites)) {
            $text .= "\n" . __('Top 10 websites by storage growth', 'rrze-multisite-manager') . "\n";
            foreach (array_slice($growthSites, 0, 10) as $site) {
                $text .= '- ' . $site['title'] . ': ' . $this->formatSignedSize((int)$site['growth_bytes']) . "\n";
            }
        }

        return $text;
    }

    /** @param array<int, array<string, mixed>> $sites */
    protected function buildCapacitySiteTable(string $title, array $sites, bool $includeGrowth): string {
        $html = '<h3 style="margin:26px 0 10px;font-size:17px;line-height:1.35;color:#1d2327">' . esc_html($title) . '</h3>' . $this->getEmailTableOpen() . '<thead><tr>' . $this->getEmailTableHeader(__('Title', 'rrze-multisite-manager')) . $this->getEmailTableHeader(__('Media', 'rrze-multisite-manager'), true) . $this->getEmailTableHeader(__('Database', 'rrze-multisite-manager'), true) . $this->getEmailTableHeader(__('Total', 'rrze-multisite-manager'), true);
        if ($includeGrowth) {
            $html .= $this->getEmailTableHeader(__('Growth', 'rrze-multisite-manager'), true);
        }
        $html .= '</tr></thead><tbody>';
        foreach ($sites as $site) {
            $html .= '<tr><td style="padding:9px 10px;border-bottom:1px solid #e5e7eb"><a href="' . esc_url((string)$site['url']) . '" style="color:#135e96;text-decoration:none">' . esc_html((string)$site['title']) . '</a></td><td style="padding:9px 10px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap">' . esc_html(size_format((int)$site['media_bytes'])) . '</td><td style="padding:9px 10px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap">' . esc_html(size_format((int)$site['database_bytes'])) . '</td><td style="padding:9px 10px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap">' . esc_html(size_format((int)$site['total_bytes'])) . '</td>';
            if ($includeGrowth) {
                $html .= '<td style="padding:9px 10px;border-bottom:1px solid #e5e7eb;text-align:right;white-space:nowrap">' . esc_html($this->formatSignedSize((int)$site['growth_bytes'])) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    protected function formatSignedSize(int $bytes): string {
        return ($bytes > 0 ? '+' : ($bytes < 0 ? '−' : '')) . size_format(abs($bytes));
    }

    protected function getEmailSectionHeading(string $title): string {
        return '<h2 style="margin:30px 0 12px;padding-top:20px;border-top:1px solid #dcdcde;font-size:20px;line-height:1.3;color:#1d2327">' . esc_html($title) . '</h2>';
    }

    protected function getEmailTableOpen(): string {
        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-collapse:separate;border-spacing:0;border:1px solid #dcdcde;border-radius:5px;overflow:hidden;font-size:14px">';
    }

    protected function getEmailTableHeader(string $label, bool $numeric = false): string {
        return '<th style="padding:9px 10px;background:#eaf2f8;border-bottom:1px solid #c3d4e3;color:#1d2327;font-size:12px;line-height:1.3;text-align:' . ($numeric ? 'right' : 'left') . ';text-transform:uppercase;letter-spacing:.02em">' . esc_html($label) . '</th>';
    }

    /** @param array<int, array<string, mixed>> $sites */
    protected function buildOperationalChangeTable(string $title, array $sites): string {
        usort($sites, static fn(array $left, array $right): int => strnatcasecmp((string)$left['title'], (string)$right['title']));
        $html = $this->getEmailSectionHeading($title . ' (' . number_format_i18n(count($sites)) . ')');
        $html .= $this->getEmailTableOpen() . '<thead><tr>' . $this->getEmailTableHeader(__('Title', 'rrze-multisite-manager')) . $this->getEmailTableHeader(__('URL', 'rrze-multisite-manager')) . $this->getEmailTableHeader(__('Status change', 'rrze-multisite-manager')) . '</tr></thead><tbody>';
        foreach ($sites as $site) {
            $html .= '<tr><td style="padding:9px 10px;border-bottom:1px solid #e5e7eb">' . esc_html((string)$site['title']) . '</td><td style="padding:9px 10px;border-bottom:1px solid #e5e7eb"><a href="' . esc_url((string)$site['url']) . '" style="color:#135e96;text-decoration:none">' . esc_html((string)$site['url']) . '</a></td><td style="padding:9px 10px;border-bottom:1px solid #e5e7eb">' . esc_html((string)$site['status_change']) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /** @param array<int, array<string, mixed>> $sites */
    protected function buildOperationalChangeTextTable(string $title, array $sites): string {
        usort($sites, static fn(array $left, array $right): int => strnatcasecmp((string)$left['title'], (string)$right['title']));
        $text = "\n" . $title . ' (' . number_format_i18n(count($sites)) . ")\n";
        foreach ($sites as $site) {
            $text .= '- ' . $site['title'] . ': ' . $site['status_change'] . ' — ' . $site['url'] . "\n";
        }

        return $text;
    }

    protected function getOperationalStatusLabel(string $status): string {
        $labels = [
            'healthy' => __('Technically reachable', 'rrze-multisite-manager'),
            'dns_missing' => __('DNS missing', 'rrze-multisite-manager'),
            'unreachable' => __('Technically unreachable', 'rrze-multisite-manager'),
            'provisioning' => __('Provisioning in progress', 'rrze-multisite-manager'),
            'retired' => __('Out of service', 'rrze-multisite-manager'),
        ];

        return $labels[$status] ?? ($status !== '' ? $status : __('Not set', 'rrze-multisite-manager'));
    }

    /** @return array{title: string, url: string, admin_email: string, dashboard_url: string} */
    protected function getReportSiteData(\WP_Site $site, string $url): array {
        $siteId = (int)$site->blog_id;
        $title = trim((string)get_blog_option($siteId, 'blogname', ''));

        return [
            'title' => $title !== '' ? $title : $site->domain . $site->path,
            'url' => $url,
            'admin_email' => sanitize_email((string)get_blog_option($siteId, 'admin_email', '')),
            'dashboard_url' => get_admin_url($siteId),
        ];
    }

    /** @param array<int, array{title: string, url: string, admin_email: string, dashboard_url: string}> $sites */
    protected function buildHtmlSiteTable(string $title, array $sites, bool $includeLogin): string {
        usort($sites, static fn(array $left, array $right): int => strnatcasecmp($left['title'], $right['title']));
        $html = $this->getEmailSectionHeading($title . ' (' . number_format_i18n(count($sites)) . ')');
        $html .= $this->getEmailTableOpen() . '<thead><tr>';
        $html .= $this->getEmailTableHeader(__('Title', 'rrze-multisite-manager'));
        $html .= $this->getEmailTableHeader(__('URL', 'rrze-multisite-manager'));
        $html .= $this->getEmailTableHeader(__('Email', 'rrze-multisite-manager'));
        if ($includeLogin) {
            $html .= $this->getEmailTableHeader(__('Login', 'rrze-multisite-manager'));
        }
        $html .= '</tr></thead><tbody>';

        foreach ($sites as $site) {
            $html .= '<tr><td style="padding:9px 10px;border-bottom:1px solid #e5e7eb">' . esc_html($site['title']) . '</td><td style="padding:9px 10px;border-bottom:1px solid #e5e7eb">';
            $html .= $includeLogin
                ? '<a href="' . esc_url($site['url']) . '" style="color:#135e96;text-decoration:none">' . esc_html($site['url']) . '</a>'
                : esc_html($site['url']);
            $html .= '</td><td style="padding:9px 10px;border-bottom:1px solid #e5e7eb">' . esc_html($site['admin_email']) . '</td>';
            if ($includeLogin) {
                $html .= '<td style="padding:9px 10px;border-bottom:1px solid #e5e7eb"><a href="' . esc_url($site['dashboard_url']) . '" style="color:#135e96;text-decoration:none;font-weight:bold">' . esc_html__('Dashboard', 'rrze-multisite-manager') . '</a></td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /** @param array<int, array{title: string, url: string, admin_email: string, dashboard_url: string}> $sites */
    protected function buildTextSiteTable(string $title, array $sites, bool $includeLogin): string {
        usort($sites, static fn(array $left, array $right): int => strnatcasecmp($left['title'], $right['title']));
        $text = "\n" . $title . ' (' . number_format_i18n(count($sites)) . ")\n";

        foreach ($sites as $site) {
            $text .= '- ' . $site['title'] . "\n";
            $text .= '  ' . __('URL', 'rrze-multisite-manager') . ': ' . $site['url'] . "\n";
            $text .= '  ' . __('Email', 'rrze-multisite-manager') . ': ' . $site['admin_email'] . "\n";
            if ($includeLogin) {
                $text .= '  ' . __('Login', 'rrze-multisite-manager') . ': ' . __('Dashboard', 'rrze-multisite-manager') . ' (' . $site['dashboard_url'] . ")\n";
            }
        }

        return $text;
    }

    protected function getUtcTimestamp(string $value): int {
        $timestamp = strtotime($value . ' UTC');

        return $timestamp === false ? 0 : $timestamp;
    }

    protected function getRecipient(array $options): string {
        $recipient = sanitize_email((string)($options['reporting_website_status_report_recipient'] ?? ''));

        if ($recipient === '') {
            $recipient = sanitize_email((string)get_site_option('admin_email', ''));
        }

        return $recipient;
    }

    protected function replaceSubjectPlaceholders(string $subject): string {
        $network = get_network();
        $domain = $network instanceof \WP_Network ? $network->domain : wp_parse_url(network_home_url('/'), PHP_URL_HOST);

        return str_replace('$serverdomain', (string)$domain, $subject);
    }

    protected function isMainSiteContext(): bool {
        return get_current_blog_id() === (int)get_main_site_id();
    }

    protected function runOnMainSite(callable $callback): void {
        $mainSiteId = (int)get_main_site_id();

        if ($this->isMainSiteContext()) {
            $callback();
            return;
        }

        switch_to_blog($mainSiteId);
        try {
            $callback();
        } finally {
            restore_current_blog();
        }
    }
}
