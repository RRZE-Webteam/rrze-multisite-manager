<?php

namespace RRZE\MultisiteManager\Metrics;

use RRZE\MultisiteManager\Analysis\AnalysisStore;
use RRZE\MultisiteManager\Config;
use RRZE\MultisiteManager\Settings;
use RRZE\MultisiteManager\Support\ScheduleFrequency;
use RRZE\MultisiteManager\Support\WorkerBudget;

defined('ABSPATH') || exit;

/**
 * Maintains the installation-wide plugin and theme usage index.
 *
 * The index deliberately contains only relations. Presentation code can join
 * it with current site data without scanning every site's options on request.
 */
class PluginThemeUsageMetricsService {
    use \RRZE\MultisiteManager\Support\ProcessLocks;

    public const HOOK = 'rrze_msm_collect_plugin_theme_usage_metrics';
    public const SCHEDULE = 'rrze_msm_plugin_theme_usage_metrics_cycle';
    protected const STATE_OPTION = 'rrze_msm_plugin_theme_usage_metrics_state';
    protected const LOCK_OPTION = 'rrze_msm_plugin_theme_usage_metrics_lock';
    protected const CONTINUATION_ARGS = ['rrze_msm_plugin_theme_usage_metrics_batch' => true];
    // A source analysis that ends the PHP request (timeout, memory) is
    // retried once; afterwards the asset is skipped for this run.
    protected const MAX_SOURCE_ATTEMPTS = 2;

    protected Config $config;
    protected ?Settings $settings;
    protected AnalysisStore $store;
    protected ScheduleFrequency $scheduleFrequency;
    protected $pluginAnalyzer = null;
    protected $themeAnalyzer = null;
    protected $pluginFingerprint = null;
    protected $themeFingerprint = null;
    protected $sourceAnalysisChanged = null;

    public function __construct(?Settings $settings = null, ?Config $config = null) {
        $this->settings = $settings;
        $this->config = $config ?? new Config();
        $this->store = new AnalysisStore();
        $this->scheduleFrequency = new ScheduleFrequency($this->config);
    }

    public function register(): void {
        add_action(self::HOOK, [$this, 'handleScheduledRun'], 10, 1);
        add_filter('cron_schedules', [$this, 'registerSchedules']);
        add_action('init', [$this, 'ensureSchedule'], 25);
        add_action('update_option_active_plugins', [$this, 'syncCurrentSiteAfterPluginChange'], 30, 3);
        add_action('add_option_active_plugins', [$this, 'syncCurrentSiteAfterPluginChange'], 30, 2);
        add_action('delete_option_active_plugins', [$this, 'syncCurrentSiteAfterPluginChange'], 30, 1);
        add_action('switch_theme', [$this, 'syncCurrentSiteAfterThemeChange'], 30, 3);
        add_action('update_option_stylesheet', [$this, 'syncCurrentSiteAfterThemeChange'], 30, 3);
        add_action('update_option_template', [$this, 'syncCurrentSiteAfterThemeChange'], 30, 3);
        add_action('update_site_option_active_sitewide_plugins', [$this, 'startRun'], 30, 4);
        add_action('wpmu_new_blog', [$this, 'syncNewSite'], 30, 6);
        add_action('delete_blog', [$this, 'removeSite'], 30, 2);
        add_action('wp_delete_site', [$this, 'removeDeletedSite'], 30, 1);
    }

    public function setSourceAnalysisProviders(callable $pluginAnalyzer, callable $themeAnalyzer, callable $pluginFingerprint, callable $themeFingerprint, ?callable $sourceAnalysisChanged = null): void {
        $this->pluginAnalyzer = $pluginAnalyzer;
        $this->themeAnalyzer = $themeAnalyzer;
        $this->pluginFingerprint = $pluginFingerprint;
        $this->themeFingerprint = $themeFingerprint;
        $this->sourceAnalysisChanged = $sourceAnalysisChanged;
    }

    public function registerSchedules(array $schedules): array {
        $schedules[self::SCHEDULE] = [
            'interval' => $this->getIntervalSeconds(),
            'display' => $this->getScheduleLabel(),
        ];

        return $schedules;
    }

    public function reschedule(): void {
        if (!$this->isCentralNetworkCronSite()) {
            return;
        }

        $this->clearEvents();
        $this->ensureSchedule();
    }

    public function ensureSchedule(): void {
        if (!$this->isCentralNetworkCronSite() || !$this->isEnabled()) {
            return;
        }

        // A timeout or uncaught error in an earlier continuation must not leave
        // the process permanently marked as running. The next main-site admin
        // or Cron request restores the missing continuation.
        if ($this->isRunning()) {
            $this->scheduleContinuation(5);
            return;
        }

        if (wp_next_scheduled(self::HOOK)) {
            return;
        }

        wp_schedule_event(time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK);
    }

    public function startRun(...$unused): void {
        if (!$this->isCentralNetworkCronSite() || !$this->isEnabled() || $this->isRunning()) {
            return;
        }

        $this->clearContinuationEvents();
        $this->clearStagingTables();
        $previousState = get_site_option(self::STATE_OPTION, []);
        $previousState = is_array($previousState) ? $previousState : [];
        update_site_option(self::STATE_OPTION, [
            'status' => 'running',
            'run_id' => wp_generate_uuid4(),
            'offset' => 0,
            'total' => (int)get_sites(['count' => true, 'number' => 1]),
            'started_at' => time(),
            // Keep the last published snapshot readable until its replacement
            // has been collected and promoted in full.
            'finished_at' => max(0, (int)($previousState['finished_at'] ?? 0)),
            'phase' => 'plugin_usage',
            'last_activity_at' => time(),
            'source_offset' => 0,
            'source_total' => 0,
            'source_errors' => 0,
            'last_error' => '',
        ]);
        $this->scheduleContinuation(5);
    }

    public function startRunFromNow(): string {
        if (!$this->isCentralNetworkCronSite() || !$this->isEnabled()) {
            return 'unavailable';
        }
        if ($this->isRunning()) {
            return 'running';
        }

        $this->startRun();
        return 'started';
    }

    /**
     * Cancels a running collection. The last completely published index
     * stays visible; unpublished staging rows are discarded by the next run.
     * A worker that is still executing its current step notices the changed
     * state before it writes progress, publishes a table or schedules a
     * continuation.
     */
    public function cancelRun(): string {
        $state = [];

        if (!$this->isCentralNetworkCronSite()) {
            return 'unavailable';
        }

        $state = $this->readFreshState();

        if ((string)($state['status'] ?? '') !== 'running') {
            return 'not-running';
        }

        $state['status'] = 'cancelled';
        $state['cancelled_at'] = time();
        update_site_option(self::STATE_OPTION, $state);
        $this->clearContinuationEvents();
        WorkerBudget::clearRetries(self::HOOK);

        return 'cancelled';
    }

    public function handleScheduledRun(...$args): void {
        if (!$this->isCentralNetworkCronSite() || !$this->isEnabled()) {
            return;
        }

        if (!in_array(true, $args, true) && !$this->isRunning()) {
            $this->startRun();
            return;
        }

        $budget = new WorkerBudget();
        if (!$budget->acquire()) {
            WorkerBudget::retry(self::HOOK, self::CONTINUATION_ARGS);
            return;
        }

        $token = '';
        try {
            $token = $this->acquireProcessLock(self::LOCK_OPTION);
            if ($token === '') {
                WorkerBudget::retry(self::HOOK, self::CONTINUATION_ARGS);
                return;
            }
            WorkerBudget::clearRetries(self::HOOK);
            $this->runBatch();
        } finally {
            if ($token !== '') {
                $this->releaseProcessLock(self::LOCK_OPTION, $token);
            }
            $budget->release();
        }
    }

    /** @return array<string, int|bool|string> */
    public function getStatus(): array {
        $state = get_site_option(self::STATE_OPTION, []);
        $state = is_array($state) ? $state : [];
        $total = max(0, (int)($state['total'] ?? 0));
        $offset = min($total, max(0, (int)($state['offset'] ?? 0)));
        $running = (string)($state['status'] ?? '') === 'running';
        $phase = (string)($state['phase'] ?? '');
        $sourceTotal = max(0, (int)($state['source_total'] ?? 0));
        $sourceOffset = min($sourceTotal, max(0, (int)($state['source_offset'] ?? 0)));

        return [
            'has_data' => (int)($state['finished_at'] ?? 0) > 0,
            'is_running' => $running,
            'last_run_timestamp' => (int)($state['finished_at'] ?? 0),
            'started_at_timestamp' => (int)($state['started_at'] ?? 0),
            'next_run_timestamp' => (int)wp_next_scheduled(self::HOOK),
            'batch_offset' => $offset,
            'batch_total' => $total,
            'checked_sites' => $offset,
            'remaining_sites' => max(0, $total - $offset),
            'progress_percent' => $total > 0 ? (int)round(($offset / $total) * 100) : 0,
            'last_site_count' => $total,
            'phase' => $phase,
            'source_offset' => $sourceOffset,
            'source_total' => $sourceTotal,
            'source_errors' => max(0, (int)($state['source_errors'] ?? 0)),
            'last_error' => (string)($state['last_error'] ?? ''),
            'is_cancelled' => (string)($state['status'] ?? '') === 'cancelled',
            'cancelled_at_timestamp' => max(0, (int)($state['cancelled_at'] ?? 0)),
        ];
    }

    public function hasCompletedSnapshot(): bool {
        return !empty($this->getStatus()['has_data']);
    }

    public function getIntervalHours(): int {
        return $this->scheduleFrequency->toHours($this->getFrequency());
    }

    public function getIntervalLabel(): string {
        return $this->getScheduleLabel();
    }

    /** @return array<int, int> */
    public function getPluginSiteIds(string $pluginFile): array {
        return $this->getAssetSiteIds('plugin_metrics', 'plugin_file', $pluginFile);
    }

    /** @return array<int, int> */
    public function getThemeSiteIds(string $stylesheet): array {
        return $this->getAssetSiteIds('theme_metrics', 'stylesheet', $stylesheet);
    }

    /** @return array<string, int> */
    public function getPluginCounts(): array {
        return $this->getAssetCounts('plugin_metrics', 'plugin_file');
    }

    /** @return array<string, int> */
    public function getThemeCounts(): array {
        return $this->getAssetCounts('theme_metrics', 'stylesheet');
    }

    public function getPluginCountForSite(int $siteId): int {
        global $wpdb;
        if (!$this->hasCompletedSnapshot() || $siteId <= 0) {
            return 0;
        }
        $table = $this->store->table('plugin_metrics');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One site's indexed relation for the dashboard snapshot.
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE network_id = %d AND blog_id = %d", get_current_network_id(), $siteId));
    }

    public function getPluginSourceAnalysis(string $pluginFile): array {
        return $this->getSourceAnalysis('plugin_source_metrics', 'plugin_file', $pluginFile);
    }

    /**
     * Stored source findings of several plugins in one query.
     *
     * @param string[] $pluginFiles
     * @return array<string, array<string, mixed>>
     */
    public function getPluginSourceAnalyses(array $pluginFiles): array {
        global $wpdb;
        $results = [];
        $row = [];
        $analysis = null;
        $pluginFiles = array_values(array_unique(array_filter(array_map('strval', $pluginFiles))));

        if (empty($pluginFiles) || !$this->hasCompletedSnapshot()) {
            return [];
        }

        $table = $this->store->table('plugin_source_metrics');
        $placeholders = implode(',', array_fill(0, count($pluginFiles), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded lookup of stored source findings; placeholders are generated above.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT plugin_file, payload FROM $table WHERE network_id = %d AND analysis_status = 'complete' AND plugin_file IN ($placeholders)",
            get_current_network_id(),
            ...$pluginFiles
        ), ARRAY_A);

        foreach ((array)$rows as $row) {
            $analysis = json_decode((string)($row['payload'] ?? ''), true);
            if (is_array($analysis)) {
                $results[(string)$row['plugin_file']] = $analysis;
            }
        }

        return $results;
    }

    public function getThemeSourceAnalysis(string $stylesheet): array {
        return $this->getSourceAnalysis('theme_source_metrics', 'stylesheet', $stylesheet);
    }

    public function hasPluginSourceAnalysis(string $pluginFile): bool {
        return $this->hasSourceAnalysis('plugin_source_metrics', 'plugin_file', $pluginFile);
    }

    public function hasThemeSourceAnalysis(string $stylesheet): bool {
        return $this->hasSourceAnalysis('theme_source_metrics', 'stylesheet', $stylesheet);
    }

    public function syncCurrentSiteAfterPluginChange(...$unused): void {
        if ($this->hasCompletedSnapshot()) {
            $this->syncSitePlugins(get_current_blog_id());
            $this->notifyDetailsChanged();
        }
        if ($this->isRunning()) {
            $this->syncSitePlugins(get_current_blog_id(), true);
        }
    }

    public function syncCurrentSiteAfterThemeChange(...$unused): void {
        if ($this->hasCompletedSnapshot()) {
            $this->syncSiteTheme(get_current_blog_id());
            $this->notifyDetailsChanged();
        }
        if ($this->isRunning()) {
            $this->syncSiteTheme(get_current_blog_id(), true);
        }
    }

    public function syncNewSite(int $siteId, ...$unused): void {
        if ($this->hasCompletedSnapshot()) {
            $this->syncSite($siteId);
            $this->notifyDetailsChanged();
        }
        if ($this->isRunning()) {
            $this->syncSite($siteId, true);
        }
    }

    public function removeSite(int $siteId, ...$unused): void {
        if ($siteId <= 0) {
            return;
        }
        if ($this->hasCompletedSnapshot()) {
            $this->deleteSiteRows('plugin_metrics', $siteId);
            $this->deleteSiteRows('theme_metrics', $siteId);
        }
        if ($this->isRunning()) {
            $this->deleteSiteRows('plugin_metrics_staging', $siteId);
            $this->deleteSiteRows('theme_metrics_staging', $siteId);
        }
        $this->notifyDetailsChanged();
    }

    public function removeDeletedSite(\WP_Site $site): void {
        $this->removeSite((int)$site->blog_id);
    }

    protected function runBatch(): void {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $state = get_site_option(self::STATE_OPTION, []);
        $state = is_array($state) ? $state : [];
        if ((string)($state['status'] ?? '') !== 'running') {
            return;
        }

        $phase = (string)($state['phase'] ?? 'plugin_usage');
        if ($phase === 'usage') {
            // Upgrade a run started by the earlier combined usage phase. It
            // has no safe staging snapshot, so restart its unpublished work.
            $this->clearStagingTables();
            $state['phase'] = 'plugin_usage';
            $state['offset'] = 0;
            $state['source_offset'] = 0;
            $state['source_total'] = 0;
            $state['last_activity_at'] = time();
            if (!$this->persistRunState($state)) {
                return;
            }
            $this->scheduleContinuation(5);
            return;
        }
        if (!in_array($phase, ['plugin_usage', 'theme_usage'], true)) {
            $this->runSourceBatch($state);
            return;
        }

        $total = max(0, (int)($state['total'] ?? 0));
        $offset = max(0, (int)($state['offset'] ?? 0));
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => $this->getBatchSize(),
            'offset' => $offset,
            'orderby' => 'id',
            'order' => 'ASC',
        ]);

        foreach ($siteIds as $siteId) {
            if ($phase === 'plugin_usage') {
                $this->syncSitePlugins((int)$siteId, true);
            } else {
                $this->syncSiteTheme((int)$siteId, true);
            }
        }

        $offset += count($siteIds);
        if (empty($siteIds) || $offset >= $total) {
            if (!$this->isActiveRun($state)) {
                return;
            }
            if (!$this->publishStagingTable(
                $phase === 'plugin_usage' ? 'plugin_metrics' : 'theme_metrics',
                $phase === 'plugin_usage' ? 'plugin_metrics_staging' : 'theme_metrics_staging'
            )) {
                $this->scheduleContinuation(60);
                return;
            }
            $state['offset'] = 0;
            $state['phase'] = $phase === 'plugin_usage' ? 'theme_usage' : 'plugin_sources';
            $state['source_offset'] = 0;
            $state['source_total'] = $phase === 'plugin_usage' ? 0 : count(get_plugins());
            $state['last_activity_at'] = time();
            if (!$this->persistRunState($state)) {
                return;
            }
            $this->notifyDetailsChanged();
            $this->scheduleContinuation(5);
            return;
        }

        $state['offset'] = $offset;
        $state['last_activity_at'] = time();
        if (!$this->persistRunState($state)) {
            return;
        }
        $this->scheduleContinuation(20);
    }

    /** @param array<string, mixed> $state */
    protected function runSourceBatch(array $state): void {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $phase = (string)($state['phase'] ?? 'plugin_sources');
        $isPlugin = $phase === 'plugin_sources';
        $assets = $isPlugin ? array_keys(get_plugins()) : array_keys(wp_get_themes());
        sort($assets, SORT_NATURAL | SORT_FLAG_CASE);
        $offset = max(0, (int)($state['source_offset'] ?? 0));
        $asset = (string)($assets[$offset] ?? '');

        if ($asset !== '') {
            $attempts = (string)($state['source_attempt_asset'] ?? '') === $asset
                ? max(0, (int)($state['source_attempts'] ?? 0))
                : 0;

            if ($attempts >= self::MAX_SOURCE_ATTEMPTS) {
                $state['source_errors'] = max(0, (int)($state['source_errors'] ?? 0)) + 1;
                $state['last_error'] = substr(sprintf(
                    /* translators: %s: plugin file or theme stylesheet. */
                    __('The source analysis of %s was skipped after repeated aborted attempts.', 'rrze-multisite-manager'),
                    $asset
                ), 0, 500);
            } else {
                // Persist the attempt before scanning. A fatal error ends the
                // request without reaching the offset update below; the
                // restored continuation must not retry the same asset forever.
                $state['source_attempt_asset'] = $asset;
                $state['source_attempts'] = $attempts + 1;
                $state['last_activity_at'] = time();
                if (!$this->persistRunState($state)) {
                    return;
                }

                try {
                    $this->storeSourceAnalysis($isPlugin, $asset, true);
                } catch (\Throwable $error) {
                    $state['source_errors'] = max(0, (int)($state['source_errors'] ?? 0)) + 1;
                    $state['last_error'] = substr(sanitize_text_field($error->getMessage()), 0, 500);
                }
            }
            unset($state['source_attempt_asset'], $state['source_attempts']);
            $state['source_offset'] = $offset + 1;
            $state['last_activity_at'] = time();
            if (!$this->persistRunState($state)) {
                return;
            }
            $this->scheduleContinuation(20);
            return;
        }

        if ($isPlugin) {
            if (!$this->isActiveRun($state)) {
                return;
            }
            if (!$this->publishStagingTable('plugin_source_metrics', 'plugin_source_metrics_staging')) {
                $this->scheduleContinuation(60);
                return;
            }
            $this->notifyDetailsChanged();
            $state['phase'] = 'theme_sources';
            $state['source_offset'] = 0;
            $state['source_total'] = count(wp_get_themes());
            $state['last_activity_at'] = time();
            if (!$this->persistRunState($state)) {
                return;
            }
            $this->scheduleContinuation(5);
            return;
        }

        if (!$this->isActiveRun($state)) {
            return;
        }
        if (!$this->publishStagingTable('theme_source_metrics', 'theme_source_metrics_staging')) {
            $this->scheduleContinuation(60);
            return;
        }
        $this->notifyDetailsChanged();

        if (!$this->isActiveRun($state)) {
            return;
        }
        update_site_option(self::STATE_OPTION, [
            'status' => 'completed',
            'offset' => max(0, (int)($state['total'] ?? 0)),
            'total' => max(0, (int)($state['total'] ?? 0)),
            'started_at' => (int)($state['started_at'] ?? time()),
            'finished_at' => time(),
            'phase' => 'complete',
        ]);
    }

    protected function syncSite(int $siteId, bool $staging = false): void {
        if ($siteId <= 0 || !get_site($siteId)) {
            return;
        }
        $this->syncSitePlugins($siteId, $staging);
        $this->syncSiteTheme($siteId, $staging);
    }

    protected function syncSitePlugins(int $siteId, bool $staging = false): void {
        $networkPlugins = array_keys((array)get_site_option('active_sitewide_plugins', []));
        $localPlugins = get_blog_option($siteId, 'active_plugins', []);
        $plugins = array_values(array_unique(array_merge(
            array_filter($networkPlugins, 'is_string'),
            is_array($localPlugins) ? array_filter($localPlugins, 'is_string') : []
        )));
        $this->replaceSiteAssets($staging ? 'plugin_metrics_staging' : 'plugin_metrics', 'plugin_file', $siteId, $plugins);
    }

    protected function syncSiteTheme(int $siteId, bool $staging = false): void {
        $stylesheet = (string)get_blog_option($siteId, 'stylesheet', '');
        if ($stylesheet === '') {
            $stylesheet = (string)get_blog_option($siteId, 'template', '');
        }
        $this->replaceSiteAssets($staging ? 'theme_metrics_staging' : 'theme_metrics', 'stylesheet', $siteId, $stylesheet === '' ? [] : [$stylesheet]);
    }

    /** @param array<int, string> $assets */
    protected function replaceSiteAssets(string $kind, string $column, int $siteId, array $assets): void {
        global $wpdb;
        $this->deleteSiteRows($kind, $siteId);
        if (empty($assets)) {
            return;
        }

        $table = $this->store->table($kind);
        $networkId = get_current_network_id();
        $now = time();
        foreach ($assets as $asset) {
            $asset = substr(sanitize_text_field($asset), 0, 191);
            if ($asset === '') {
                continue;
            }
            // Identifiers are selected from a fixed internal allowlist.
            $sql = $wpdb->prepare(
                "INSERT INTO $table (network_id, blog_id, $column, recorded_at) VALUES (%d, %d, %s, %d)",
                $networkId,
                $siteId,
                $asset,
                $now
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Central derived index, updated only on state changes or scheduled batches.
            $wpdb->query($sql);
        }
    }

    protected function deleteSiteRows(string $kind, int $siteId): void {
        global $wpdb;
        $table = $this->store->table($kind);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Central derived index, one site only.
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE network_id = %d AND blog_id = %d", get_current_network_id(), $siteId));
    }

    /** @return array<int, int> */
    protected function getAssetSiteIds(string $kind, string $column, string $asset): array {
        global $wpdb;
        if (!$this->hasCompletedSnapshot() || $asset === '') {
            return [];
        }
        $table = $this->store->table($kind);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Detail page reads a narrow indexed relation.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT blog_id FROM $table WHERE network_id = %d AND $column = %s ORDER BY blog_id ASC", get_current_network_id(), $asset));
        return array_values(array_unique(array_filter(array_map('absint', (array)$ids))));
    }

    /** @return array<string, int> */
    protected function getAssetCounts(string $kind, string $column): array {
        global $wpdb;
        if (!$this->hasCompletedSnapshot()) {
            return [];
        }
        $table = $this->store->table($kind);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregated central derived index.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT $column AS asset, COUNT(*) AS site_count FROM $table WHERE network_id = %d GROUP BY $column", get_current_network_id()));
        $counts = [];
        foreach ((array)$rows as $row) {
            $asset = is_object($row) ? (string)($row->asset ?? '') : '';
            if ($asset !== '') {
                $counts[$asset] = is_object($row) ? (int)($row->site_count ?? 0) : 0;
            }
        }
        return $counts;
    }

    protected function storeSourceAnalysis(bool $isPlugin, string $asset, bool $staging = false): void {
        global $wpdb;
        $analyzer = $isPlugin ? $this->pluginAnalyzer : $this->themeAnalyzer;
        $fingerprintProvider = $isPlugin ? $this->pluginFingerprint : $this->themeFingerprint;
        if (!is_callable($analyzer) || !is_callable($fingerprintProvider)) {
            return;
        }
        $kind = $isPlugin ? 'plugin_source_metrics' : 'theme_source_metrics';
        if ($staging) {
            $kind .= '_staging';
        }
        $column = $isPlugin ? 'plugin_file' : 'stylesheet';
        $fingerprint = hash('sha256', (string)$fingerprintProvider($asset));
        $table = $this->store->table($kind);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One central source-analysis row per installed asset.
        $known = $wpdb->get_var($wpdb->prepare("SELECT fingerprint FROM $table WHERE network_id = %d AND $column = %s", get_current_network_id(), $asset));
        if ($fingerprint !== '' && hash_equals($fingerprint, (string)$known)) {
            return;
        }
        if ($staging && $fingerprint !== '') {
            $publishedKind = $isPlugin ? 'plugin_source_metrics' : 'theme_source_metrics';
            $published = $this->store->table($publishedKind);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reuse an unchanged, already published result in this run's unpublished staging snapshot.
            $copied = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (network_id,$column,fingerprint,analysis_status,payload,payload_bytes,recorded_at) SELECT network_id,$column,fingerprint,analysis_status,payload,payload_bytes,recorded_at FROM $published WHERE network_id = %d AND $column = %s AND fingerprint = %s AND analysis_status = 'complete'",
                get_current_network_id(),
                $asset,
                $fingerprint
            ));
            if ($copied !== false && $copied > 0) {
                return;
            }
        }
        $analysis = $analyzer($asset);
        $payload = is_array($analysis) ? wp_json_encode($analysis) : false;
        if (!is_string($payload) || strlen($payload) > AnalysisStore::CHECKPOINT_BYTES) {
            return;
        }
        $sql = $wpdb->prepare(
            "INSERT INTO $table (network_id,$column,fingerprint,analysis_status,payload,payload_bytes,recorded_at) VALUES (%d,%s,%s,'complete',%s,%d,%d) ON DUPLICATE KEY UPDATE fingerprint = VALUES(fingerprint), analysis_status = VALUES(analysis_status), payload = VALUES(payload), payload_bytes = VALUES(payload_bytes), recorded_at = VALUES(recorded_at)",
            get_current_network_id(), $asset, $fingerprint, $payload, strlen($payload), time()
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Central derived source-analysis cache.
        $written = $wpdb->query($sql);
    }

    /** @return array<string, mixed> */
    protected function getSourceAnalysis(string $kind, string $column, string $asset): array {
        global $wpdb;
        if (!$this->hasCompletedSnapshot() || $asset === '') {
            return [];
        }
        $table = $this->store->table($kind);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One cached source-analysis row for a detail page.
        $payload = $wpdb->get_var($wpdb->prepare("SELECT payload FROM $table WHERE network_id = %d AND $column = %s AND analysis_status = 'complete'", get_current_network_id(), $asset));
        $analysis = is_string($payload) ? json_decode($payload, true) : null;
        return is_array($analysis) ? $analysis : [];
    }

    protected function hasSourceAnalysis(string $kind, string $column, string $asset): bool {
        global $wpdb;
        if ($asset === '') {
            return false;
        }
        $table = $this->store->table($kind);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Narrow source-analysis state lookup.
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE network_id = %d AND $column = %s AND analysis_status = 'complete' LIMIT 1", get_current_network_id(), $asset));
    }

    /** Clear only unpublished work from an abandoned or newly started run. */
    protected function clearStagingTables(): void {
        global $wpdb;
        foreach (['plugin_metrics_staging', 'theme_metrics_staging', 'plugin_source_metrics_staging', 'theme_source_metrics_staging'] as $kind) {
            $table = $this->store->table($kind);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Only unpublished staging rows of this network are cleared before a new complete run.
            $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE network_id = %d", get_current_network_id()));
        }
    }

    /**
     * Atomically promotes a fully collected staging table. Readers therefore
     * either get the previous complete snapshot or the new complete snapshot.
     */
    protected function publishStagingTable(string $publishedKind, string $stagingKind): bool {
        global $wpdb;
        $published = $this->store->table($publishedKind);
        $staging = $this->store->table($stagingKind);
        $isSource = str_contains($publishedKind, '_source_');
        $assetColumn = str_starts_with($publishedKind, 'plugin_') ? 'plugin_file' : 'stylesheet';
        $columns = $isSource
            ? "network_id, $assetColumn, fingerprint, analysis_status, payload, payload_bytes, recorded_at"
            : "network_id, blog_id, $assetColumn, recorded_at";
        $networkId = get_current_network_id();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The replacement is a short transaction between two MSM-owned central tables.
        if ($wpdb->query('START TRANSACTION') === false) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- See transaction above.
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $published WHERE network_id = %d", $networkId));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- See transaction above.
        $inserted = $deleted === false ? false : $wpdb->query($wpdb->prepare("INSERT INTO $published ($columns) SELECT $columns FROM $staging WHERE network_id = %d", $networkId));
        if ($inserted === false) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Roll back the failed publication.
            $wpdb->query('ROLLBACK');
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Commit the complete replacement.
        if ($wpdb->query('COMMIT') !== false) {
            return true;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Leave no uncertain transaction open after a failed commit.
        $wpdb->query('ROLLBACK');
        return false;
    }

    protected function notifyDetailsChanged(): void {
        if (is_callable($this->sourceAnalysisChanged)) {
            ($this->sourceAnalysisChanged)();
        }
    }

    protected function scheduleContinuation(int $delay): void {
        if (!wp_next_scheduled(self::HOOK, self::CONTINUATION_ARGS)) {
            wp_schedule_single_event(time() + max(5, $delay), self::HOOK, self::CONTINUATION_ARGS);
        }
    }

    protected function clearEvents(): void {
        foreach ((array)_get_cron_array() as $timestamp => $events) {
            foreach ((array)($events[self::HOOK] ?? []) as $event) {
                wp_unschedule_event((int)$timestamp, self::HOOK, (array)($event['args'] ?? []));
            }
        }
    }

    protected function clearContinuationEvents(): void {
        foreach ((array)_get_cron_array() as $timestamp => $events) {
            foreach ((array)($events[self::HOOK] ?? []) as $event) {
                if ((array)($event['args'] ?? []) === self::CONTINUATION_ARGS) {
                    wp_unschedule_event((int)$timestamp, self::HOOK, self::CONTINUATION_ARGS);
                }
            }
        }
    }

    /** @return array<string, mixed> */
    protected function readFreshState(): array {
        // A cancellation written by another request must not be hidden by
        // this request's option cache.
        wp_cache_delete(get_current_network_id() . ':' . self::STATE_OPTION, 'site-options');
        $state = get_site_option(self::STATE_OPTION, []);

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    protected function isActiveRun(array $state): bool {
        $current = $this->readFreshState();

        // Runs started by older releases have no run ID; their start time
        // identifies them instead.
        return (string)($current['status'] ?? '') === 'running'
            && (string)($current['run_id'] ?? '') === (string)($state['run_id'] ?? '')
            && (int)($current['started_at'] ?? 0) === (int)($state['started_at'] ?? 0);
    }

    /**
     * Writes run progress only while this run is still the active one.
     *
     * @param array<string, mixed> $state
     */
    protected function persistRunState(array $state): bool {
        if (!$this->isActiveRun($state)) {
            return false;
        }

        update_site_option(self::STATE_OPTION, $state);

        return true;
    }

    protected function isRunning(): bool {
        $state = get_site_option(self::STATE_OPTION, []);
        return is_array($state) && (string)($state['status'] ?? '') === 'running';
    }

    protected function getBatchSize(): int {
        return max(5, min(100, (int)($this->settings?->getOption('monitoring', 'batch_size', 25) ?? 25)));
    }

    protected function getFrequency(): string {
        $frequency = (string)($this->settings?->getOption('monitoring', 'plugin_theme_usage_frequency', 'twiceweekly') ?? 'twiceweekly');
        return $this->config->hasSchedulerFrequency($frequency) ? $frequency : 'twiceweekly';
    }

    protected function isEnabled(): bool {
        return $this->getFrequency() !== 'disabled';
    }

    protected function getIntervalSeconds(): int {
        return $this->scheduleFrequency->toHours($this->getFrequency()) * HOUR_IN_SECONDS;
    }

    protected function getScheduleLabel(): string {
        return $this->scheduleFrequency->label($this->getFrequency());
    }

    protected function isCentralNetworkCronSite(): bool {
        return get_current_blog_id() === (int)get_main_site_id(get_current_network_id());
    }
}
