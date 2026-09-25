<?php

namespace RRZE\MultisiteManager;

defined('ABSPATH') || exit;

class ShortcodeBlockAnalysisSchedulerService {
    protected const HOOK_FALLBACK = 'rrze_msm_run_shortcode_block_analysis';
    protected const STATUS_OPTION = 'rrze_msm_shortcode_block_analysis_status';
    protected const RESULT_OPTION = 'rrze_msm_shortcode_block_analysis_result';
    protected const LOCK_OPTION_PREFIX = 'rrze_msm_shortcode_block_analysis_lock_';
    protected const SCHEDULE_SIGNATURE_OPTION = 'rrze_msm_shortcode_block_analysis_schedule_signature';
    protected const GLOBAL_INITIALIZATION_OPTION = 'rrze_msm_shortcode_block_analysis_global_initialization';
    protected const TASK_REMOVAL_OPTION = 'rrze_msm_shortcode_block_analysis_tasks_removed';
    protected const BATCH_SIZE = 20;
    protected const SHORTCODE_PHASE = 'shortcodes';
    protected const BLOCK_PHASE = 'blocks';

    protected Config $config;
    /** @var array<int, array<string, array{name: string, plugin_file: string}>> */
    protected array $activeSiteShortcodeRegistrations = [];

    public function __construct(?Config $config = null) {
        $this->config = $config ?? new Config();
    }

    public function onLoaded(): void {
        add_action($this->getHook(), [$this, 'runScheduledAnalysis'], 10, 1);
        add_filter('cron_schedules', [$this, 'registerSchedules']);
        add_action('init', [$this, 'ensureRecurringSchedules'], 20);
    }

    public function registerSchedules(array $schedules): array {
        $schedules['rrze_msm_shortcode_block_weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display' => __('Once weekly', 'rrze-multisite-manager'),
        ];
        $schedules['rrze_msm_shortcode_block_twice_weekly'] = [
            'interval' => (int)(WEEK_IN_SECONDS / 2),
            'display' => __('Twice weekly', 'rrze-multisite-manager'),
        ];
        $schedules['rrze_msm_shortcode_block_daily'] = [
            'interval' => DAY_IN_SECONDS,
            'display' => __('Once daily', 'rrze-multisite-manager'),
        ];
        $schedules['rrze_msm_shortcode_block_twice_daily'] = [
            'interval' => 12 * HOUR_IN_SECONDS,
            'display' => __('Twice daily', 'rrze-multisite-manager'),
        ];
        $schedules['rrze_msm_shortcode_block_four_times_daily'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Four times daily', 'rrze-multisite-manager'),
        ];

        return $schedules;
    }

    public function ensureRecurringSchedules(): void {
        $signature = $this->getScheduleSignature();
        $storedSignature = get_site_option(self::SCHEDULE_SIGNATURE_OPTION, null);

        if ($storedSignature === null || $storedSignature === false) {
            $this->markScheduleConfigurationCurrent();
            return;
        }

        if ((string)$storedSignature === $signature) {
            return;
        }

        $this->syncRecurringSchedules();
    }

    /**
     * Marks the current scheduler configuration without creating site-specific tasks.
     */
    public function markScheduleConfigurationCurrent(): void {
        update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());
    }

    public function syncRecurringSchedules(): void {
        $siteIds = get_sites(['fields' => 'ids', 'number' => 0, 'orderby' => 'id', 'order' => 'ASC']);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if (!$this->isSiteActive($siteId)) {
                $this->deactivateSite($siteId);
                continue;
            }

            $this->recoverInterruptedAnalysis($siteId);

            if ($this->isRunning($siteId)) {
                continue;
            }

            // A removed schedule is an explicit administrator decision. Frequency
            // changes may reschedule existing tasks, but must never recreate them.
            if (!$this->hasRecurringScheduledAnalysis($siteId)) {
                continue;
            }

            $this->unschedule($siteId);
            $this->scheduleRecurringAnalysis($siteId);
        }

        update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());
    }

    public static function clearScheduledEvents(?Config $config = null): int {
        $scheduler = new self($config);
        $cron = _get_cron_array();
        $removed = 0;

        foreach ((array)$cron as $timestamp => $events) {
            foreach ((array)($events[$scheduler->getHook()] ?? []) as $event) {
                if (wp_unschedule_event((int)$timestamp, $scheduler->getHook(), (array)($event['args'] ?? []))) {
                    $removed++;
                }
            }
        }

        delete_site_option(self::SCHEDULE_SIGNATURE_OPTION);
        // Removing tasks is an explicit administrator decision. Do not make the
        // automatic initialization action reappear and recreate these tasks.
        update_site_option(self::GLOBAL_INITIALIZATION_OPTION, 1);
        update_site_option(self::TASK_REMOVAL_OPTION, 1);

        return $removed;
    }

    public function requestAnalysis(int $siteId): bool {
        if ($this->isAnalysisTimedOut($siteId)) {
            $this->markTimedOut($siteId);
        }

        $this->recoverInterruptedAnalysis($siteId);

        if (!$this->isSiteActive($siteId) || $this->isRunning($siteId)) {
            return false;
        }

        $this->unschedule($siteId);
        $this->markScheduled($siteId);
        $this->scheduleRecurringAnalysisAt($siteId, time());

        return true;
    }

    public function deactivateSite(int $siteId): void {
        $this->unschedule($siteId);
        $status = $this->getStatus($siteId);

        if (empty($status)) {
            return;
        }

        $status['status'] = 'inactive';
        update_blog_option($siteId, self::STATUS_OPTION, $status);
    }

    public function runScheduledAnalysis(int $siteId): void {
        if (MetricsService::isFullDataCleanupInProgress()) {
            return;
        }

        if ($siteId <= 0 || !get_site($siteId)) {
            return;
        }

        if (!$this->isSiteActive($siteId)) {
            $this->deactivateSite($siteId);
            return;
        }

        if (!$this->acquireLock($siteId)) {
            return;
        }

        try {
            $this->runAnalysis($siteId);
        } catch (\Throwable $exception) {
            if ($this->isAnalysisTimedOut($siteId)) {
                $this->markTimedOut($siteId);
            } else {
                $this->markFailed($siteId, $exception->getMessage());
            }
            do_action(
                'rrze.log.error',
                'RRZE-MSM: Shortcode and block analysis failed',
                [
                    'site_id' => $siteId,
                    'site_url' => get_home_url($siteId, '/'),
                    'message' => $exception->getMessage(),
                ]
            );
        } finally {
            $this->releaseLock($siteId);
        }
    }

    public function getStatus(int $siteId): array {
        $status = get_blog_option($siteId, self::STATUS_OPTION, []);

        return is_array($status) ? $status : [];
    }

    public function getResult(int $siteId): array {
        $result = get_blog_option($siteId, self::RESULT_OPTION, []);

        return is_array($result) ? $result : [];
    }

    public function getNextScheduledRunTimestamp(int $siteId): int {
        return $siteId > 0 ? $this->getNextRecurringScheduledTimestamp($siteId) : 0;
    }

    public function getSiteProcesses(): array {
        $siteIds = get_sites(['fields' => 'ids', 'number' => 0, 'orderby' => 'id', 'order' => 'ASC']);
        $processes = [];

        foreach ($siteIds as $siteId) {
            $processes[] = $this->getSiteProcess((int)$siteId);
        }

        return $processes;
    }

    /**
     * Returns one bounded page for the monitoring table.  This deliberately
     * includes unscheduled sites so pagination never requires scanning every
     * site merely to find rows with an existing analysis record.
     *
     * @return array{processes: array<int, array<string, mixed>>, has_more: bool}
     */
    public function getSiteProcessesPage(int $page, int $perPage): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $siteIds = get_sites([
            'fields' => 'ids',
            'number' => $perPage + 1,
            'offset' => ($page - 1) * $perPage,
            'orderby' => 'id',
            'order' => 'ASC',
        ]);
        $hasMore = count($siteIds) > $perPage;
        $processes = [];

        foreach (array_slice($siteIds, 0, $perPage) as $siteId) {
            $processes[] = $this->getSiteProcess((int)$siteId);
        }

        return [
            'processes' => $processes,
            'has_more' => $hasMore,
        ];
    }

    /** @return array<string, mixed> */
    protected function getSiteProcess(int $siteId): array {
        $status = $this->getStatus($siteId);
        $result = $this->getResult($siteId);
        $nextRun = $this->getNextRecurringScheduledTimestamp($siteId);

        $this->recoverInterruptedAnalysis($siteId, $status);
        $status = $this->getStatus($siteId);
        $isActive = $this->isSiteActive($siteId);
        $isRunning = $this->isRunning($siteId);
        $lastFinishedAt = (string)($result['generated_at'] ?? '');

        // Older completed runs may not yet have stored a separate result.
        if ($lastFinishedAt === '' && (string)($status['status'] ?? '') === 'complete' && empty($status['last_error'])) {
            $lastFinishedAt = (string)($status['last_finished_at'] ?? '');
        }

        $statusKey = $this->getProcessStatusKey($isActive, $isRunning, $nextRun, $status, $lastFinishedAt);

        return [
            'site_id' => $siteId,
            'url' => get_home_url($siteId, '/'),
            'status' => $this->getProcessStatusLabel($statusKey),
            'status_key' => $statusKey,
            'is_active' => $isActive,
            'website_status_key' => $isActive ? 'active' : 'inactive',
            'is_running' => $isRunning,
            'last_started_at' => (string)($status['last_started_at'] ?? ''),
            // Only a persisted result represents a successfully completed analysis.
            'last_finished_at' => $lastFinishedAt,
            'next_run_timestamp' => $nextRun,
            'cycle' => $isActive ? $this->getScheduleLabel() : '',
            'processed_posts' => (int)($status['processed_posts'] ?? 0),
            'total_posts' => (int)($status['total_posts'] ?? 0),
            'phases' => $this->getProcessPhases($status, $lastFinishedAt),
        ];
    }

    protected function getProcessStatusKey(bool $isActive, bool $isRunning, int $nextRun, array $status, string $lastFinishedAt): string {
        if (!$isActive) {
            return 'inactive';
        }

        if ($isRunning) {
            return 'running';
        }

        if (!empty($status['last_error'])) {
            return 'error';
        }

        if ($nextRun > 0 && $nextRun <= time()) {
            return 'waiting_for_cron';
        }

        if ($lastFinishedAt !== '' && $nextRun > time()) {
            return 'ok';
        }

        return $nextRun > 0 ? 'scheduled' : 'not_scheduled';
    }

    protected function getProcessStatusLabel(string $statusKey): string {
        $labels = [
            'inactive' => __('Inactive', 'rrze-multisite-manager'),
            'running' => __('Running', 'rrze-multisite-manager'),
            'error' => __('Error', 'rrze-multisite-manager'),
            'waiting_for_cron' => __('Waiting for cron', 'rrze-multisite-manager'),
            'ok' => __('Ok', 'rrze-multisite-manager'),
            'scheduled' => __('Scheduled', 'rrze-multisite-manager'),
            'not_scheduled' => __('Not scheduled', 'rrze-multisite-manager'),
        ];

        return $labels[$statusKey] ?? $labels['not_scheduled'];
    }

    protected function getProcessPhases(array $status, string $lastFinishedAt): array {
        $phases = is_array($status['phases'] ?? null) ? $status['phases'] : $this->getDefaultPhaseStatuses();

        if ($lastFinishedAt === '') {
            return $phases;
        }

        foreach ([self::SHORTCODE_PHASE, self::BLOCK_PHASE] as $phase) {
            $phaseStatus = is_array($phases[$phase] ?? null) ? $phases[$phase] : [];

            if (in_array((string)($phaseStatus['status'] ?? ''), ['idle', 'scheduled'], true) && empty($phaseStatus['started_at'])) {
                $phaseStatus['status'] = 'complete';
                $phaseStatus['started_at'] = $lastFinishedAt;
                $phaseStatus['finished_at'] = $lastFinishedAt;
                $phases[$phase] = $phaseStatus;
            }
        }

        return $phases;
    }

    public function getUnscheduledActiveSiteCount(): int {
        /*
         * This value only controls whether the initialization action is shown.
         * A complete per-site status scan here would make the paginated
         * monitoring page load all websites again.
         */
        if ((bool)get_site_option(self::TASK_REMOVAL_OPTION, false)) {
            return 0;
        }

        if (!(bool)get_site_option(self::GLOBAL_INITIALIZATION_OPTION, false)) {
            return 1;
        }

        return $this->hasAnyRecurringScheduledAnalysis() ? 0 : 1;
    }

    public function initializeUnscheduledActiveSites(): int {
        $initialized = 0;
        $siteIds = get_sites(['fields' => 'ids', 'number' => 0]);

        delete_site_option(self::TASK_REMOVAL_OPTION);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if ($this->isSiteUnscheduled($siteId)) {
                if ($this->scheduleRecurringAnalysis($siteId)) {
                    $initialized++;
                }
            }
        }

        update_site_option(self::GLOBAL_INITIALIZATION_OPTION, 1);
        update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());

        return $initialized;
    }

    public function resetAllSiteAnalyses(): int {
        $scheduled = 0;
        $siteIds = get_sites(['fields' => 'ids', 'number' => 0]);

        foreach ($siteIds as $siteId) {
            $siteId = (int)$siteId;

            if (!$this->isSiteActive($siteId) || $this->isRunning($siteId)) {
                continue;
            }

            $this->unschedule($siteId);
            $this->scheduleRecurringAnalysis($siteId);
            $scheduled++;
        }

        update_site_option(self::GLOBAL_INITIALIZATION_OPTION, 1);
        update_site_option(self::SCHEDULE_SIGNATURE_OPTION, $this->getScheduleSignature());

        return $scheduled;
    }

    protected function runAnalysis(int $siteId): void {
        $deadline = time() + $this->config->getShortcodeBlockAnalysisTimeoutSeconds();
        $state = $this->createState($siteId);

        $this->markStarted($siteId, $state);

        $this->extendRuntimeLimit($deadline);
        switch_to_blog($siteId);

        try {
            foreach ([self::SHORTCODE_PHASE, self::BLOCK_PHASE] as $phase) {
                $this->runPhaseToCompletion($siteId, $phase, $state, $deadline);
            }
        } finally {
            restore_current_blog();
        }

        $this->finish($siteId, $state);
    }

    protected function runPhaseToCompletion(int $siteId, string $phase, array &$state, int $deadline): void {
        $this->markPhaseStarted($siteId, $phase, $state);

        while (time() < $deadline) {
            if ($phase === self::SHORTCODE_PHASE) {
                $completed = $this->runShortcodeBatch($state);
            } else {
                $completed = $this->runBlockBatch($state);
            }

            $this->markProgress($siteId, $phase, $state);

            if ($completed) {
                $this->markPhaseFinished($siteId, $phase, $state);
                return;
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception message is not HTML output and is escaped when rendered.
        throw new \RuntimeException(__('The shortcode and block analysis was aborted because it exceeded the configured runtime limit.', 'rrze-multisite-manager'));
    }

    protected function getPostIds(int $offset): array {
        return get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => self::BATCH_SIZE,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
        ]);
    }

    protected function runShortcodeBatch(array &$state): bool {
        $postIds = $this->getPostIds((int)($state['shortcode_offset'] ?? 0));

        foreach ($postIds as $postId) {
            $this->analysePostShortcodes((int)$postId, $state);
            $state['shortcode_offset'] = (int)($state['shortcode_offset'] ?? 0) + 1;
            $state['shortcode_processed_posts'] = (int)($state['shortcode_processed_posts'] ?? 0) + 1;
        }

        return count($postIds) < self::BATCH_SIZE;
    }

    protected function runBlockBatch(array &$state): bool {
        $postIds = $this->getPostIds((int)($state['block_offset'] ?? 0));

        foreach ($postIds as $postId) {
            $this->analysePostBlocks((int)$postId, $state);
            $state['block_offset'] = (int)($state['block_offset'] ?? 0) + 1;
            $state['block_processed_posts'] = (int)($state['block_processed_posts'] ?? 0) + 1;
        }

        return count($postIds) < self::BATCH_SIZE;
    }

    protected function createState(int $siteId): array {
        switch_to_blog($siteId);

        try {
            $counts = wp_count_posts('post');
            $pageCounts = wp_count_posts('page');
            $total = array_sum((array)$counts) + array_sum((array)$pageCounts);
        } finally {
            restore_current_blog();
        }

        return [
            'site_id' => $siteId,
            'shortcode_offset' => 0,
            'block_offset' => 0,
            'shortcode_processed_posts' => 0,
            'block_processed_posts' => 0,
            'total_posts' => $total,
            'shortcodes' => [],
            'blocks' => [],
        ];
    }

    protected function analysePostShortcodes(int $postId, array &$state): void {
        $post = get_post($postId);

        if (!$post instanceof \WP_Post) {
            return;
        }

        $location = [
            'post_id' => $postId,
            'post_title' => get_the_title($postId),
            'post_type' => $post->post_type,
            'post_url' => get_permalink($postId),
        ];
        $content = (string)$post->post_content;
        $this->collectShortcodes($content, $location, $state['shortcodes']);

        // Unregistered tags are only meaningful when authors deliberately used a Shortcode block.
        foreach ($this->flattenBlocks(parse_blocks($content)) as $block) {
            if ((string)($block['blockName'] ?? '') !== 'core/shortcode') {
                continue;
            }

            $this->collectShortcodes((string)($block['innerHTML'] ?? ''), $location, $state['shortcodes'], true);
        }

        foreach (get_post_meta($postId) as $metaKey => $values) {
            foreach ((array)$values as $value) {
                $metaLocation = $location;
                $metaLocation['meta_key'] = (string)$metaKey;
                $this->collectShortcodesFromValue($value, $metaLocation, $state['shortcodes']);
            }
        }
    }

    protected function analysePostBlocks(int $postId, array &$state): void {
        $post = get_post($postId);

        if (!$post instanceof \WP_Post) {
            return;
        }

        $location = [
            'post_id' => $postId,
            'post_title' => get_the_title($postId),
            'post_type' => $post->post_type,
            'post_url' => get_permalink($postId),
        ];
        $this->collectBlocks((string)$post->post_content, $location, $state['blocks']);
    }

    protected function collectShortcodesFromValue($value, array $location, array &$shortcodes): void {
        $value = maybe_unserialize($value);

        if (is_string($value)) {
            $this->collectShortcodes($value, $location, $shortcodes);
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $childValue) {
            $this->collectShortcodesFromValue($childValue, $location, $shortcodes);
        }
    }

    protected function collectShortcodes(string $content, array $location, array &$shortcodes, bool $allowUnregistered = false): void {
        if ($content === '' || !preg_match_all('/(?<!\\[)\\[(?!\\[)([A-Za-z][A-Za-z0-9_-]*)(?=\\s|\\])(?:\\s[^\\]]*)?\\]/', $content, $matches)) {
            return;
        }

        $matchesByRawShortcode = [];

        foreach ((array)($matches[0] ?? []) as $index => $rawShortcode) {
            $tag = (string)($matches[1][$index] ?? '');
            $rawShortcode = (string)$rawShortcode;

            if ($tag === '' || $rawShortcode === '') {
                continue;
            }

            $matchesByRawShortcode[$tag . "\0" . $rawShortcode] = [
                'tag' => $tag,
                'raw_shortcode' => $rawShortcode,
            ];
        }

        foreach ($matchesByRawShortcode as $match) {
            $tag = (string)$match['tag'];
            $registration = $this->getShortcodeRegistration($tag);
            $isRegistered = !empty($registration);

            if (!$isRegistered && !$allowUnregistered) {
                continue;
            }

            $shortcodes[] = array_merge(
                [
                    'shortcode' => $tag,
                    'raw_shortcode' => (string)$match['raw_shortcode'],
                    'registered' => $isRegistered,
                    'provider' => (string)($registration['name'] ?? __('Unknown', 'rrze-multisite-manager')),
                    'provider_plugin_file' => (string)($registration['plugin_file'] ?? ''),
                ],
                $location
            );
        }
    }

    protected function collectBlocks(string $content, array $location, array &$blocks): void {
        foreach ($this->flattenBlocks(parse_blocks($content)) as $block) {
            $blockName = (string)($block['blockName'] ?? '');

            if ($blockName === '') {
                continue;
            }

            $blocks[] = array_merge(['block' => $blockName], $this->getBlockDetails($blockName), $location);
        }
    }

    protected function getBlockDetails(string $blockName): array {
        $details = [
            'block_title' => $blockName,
            'origin' => str_starts_with($blockName, 'core/')
                ? __('WordPress Core', 'rrze-multisite-manager')
                : __('Unknown', 'rrze-multisite-manager'),
            'description' => '',
        ];
        $blockType = \WP_Block_Type_Registry::get_instance()->get_registered($blockName);

        if (!$blockType instanceof \WP_Block_Type) {
            return $details;
        }

        $details['block_title'] = (string)($blockType->title ?: $blockName);
        $details['description'] = (string)($blockType->description ?? '');
        $details['category'] = $this->getBlockCategoryLabel((string)($blockType->category ?? ''));

        if (str_starts_with($blockName, 'core/')) {
            return $details;
        }

        $details['origin'] = $this->getBlockOrigin($blockType);

        return $details;
    }

    protected function getBlockCategoryLabel(string $category): string {
        if ($category === '') {
            return '';
        }

        if (function_exists('get_block_categories_all') && class_exists('\WP_Block_Editor_Context')) {
            try {
                $categories = get_block_categories_all(new \WP_Block_Editor_Context());

                foreach ((array)$categories as $categoryData) {
                    if ((string)($categoryData['slug'] ?? '') === $category) {
                        return (string)($categoryData['title'] ?? $category);
                    }
                }
            } catch (\Throwable $exception) {
                return $category;
            }
        }

        return $category;
    }

    protected function getBlockOrigin(\WP_Block_Type $blockType): string {
        $assetHandles = array_merge(
            (array)($blockType->editor_script_handles ?? []),
            (array)($blockType->script_handles ?? []),
            (array)($blockType->view_script_handles ?? []),
            (array)($blockType->editor_style_handles ?? []),
            (array)($blockType->style_handles ?? []),
            (array)($blockType->view_style_handles ?? [])
        );
        $scripts = wp_scripts();
        $styles = wp_styles();

        foreach (array_unique(array_filter($assetHandles, 'is_string')) as $handle) {
            $source = '';

            if (isset($scripts->registered[$handle])) {
                $source = (string)($scripts->registered[$handle]->src ?? '');
            } elseif (isset($styles->registered[$handle])) {
                $source = (string)($styles->registered[$handle]->src ?? '');
            }

            $origin = $this->getAssetOrigin($source);

            if ($origin !== '') {
                return $origin;
            }
        }

        return __('Unknown', 'rrze-multisite-manager');
    }

    protected function getAssetOrigin(string $source): string {
        if ($source === '') {
            return '';
        }

        $path = (string)wp_parse_url($source, PHP_URL_PATH);

        if ($path === '') {
            return '';
        }

        $pluginsPath = (string)wp_parse_url(plugins_url(), PHP_URL_PATH);
        $muPluginsPath = (string)wp_parse_url(WPMU_PLUGIN_URL, PHP_URL_PATH);
        $themesPath = (string)wp_parse_url(get_theme_root_uri(), PHP_URL_PATH);

        if (($pluginsPath !== '' && str_starts_with($path, $pluginsPath)) || ($muPluginsPath !== '' && str_starts_with($path, $muPluginsPath))) {
            return __('Plugin', 'rrze-multisite-manager');
        }

        if ($themesPath !== '' && str_starts_with($path, $themesPath)) {
            return __('Theme', 'rrze-multisite-manager');
        }

        return '';
    }

    protected function flattenBlocks(array $blocks): array {
        $flat = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $flat[] = $block;
            $flat = array_merge($flat, $this->flattenBlocks((array)($block['innerBlocks'] ?? [])));
        }

        return $flat;
    }

    protected function getShortcodeProviderDetails(string $tag): array {
        global $shortcode_tags;

        $callback = is_array($shortcode_tags) ? ($shortcode_tags[$tag] ?? null) : null;

        if ($callback === null) {
            return [];
        }

        try {
            $reflection = is_array($callback)
                ? new \ReflectionMethod($callback[0], $callback[1])
                : new \ReflectionFunction($callback);
            $file = (string)$reflection->getFileName();
        } catch (\Throwable $exception) {
            return [];
        }

        if ($file === '') {
            return [];
        }

        $pluginDirectory = wp_normalize_path(WP_PLUGIN_DIR) . '/';
        $muPluginDirectory = wp_normalize_path(WPMU_PLUGIN_DIR) . '/';
        $stylesheetDirectory = wp_normalize_path(get_stylesheet_directory()) . '/';
        $file = wp_normalize_path($file);

        if (str_starts_with($file, $pluginDirectory)) {
            $pluginFile = ltrim(substr($file, strlen($pluginDirectory)), '/');

            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            foreach ((array)get_plugins() as $installedPluginFile => $pluginData) {
                $directory = dirname((string)$installedPluginFile);

                if ($pluginFile === (string)$installedPluginFile || ($directory !== '.' && str_starts_with($pluginFile, $directory . '/'))) {
                    return [
                        'name' => (string)($pluginData['Name'] ?? $pluginFile),
                        'plugin_file' => (string)$installedPluginFile,
                    ];
                }
            }

            return ['name' => $pluginFile];
        }

        if (str_starts_with($file, $muPluginDirectory)) {
            return ['name' => __('MU-plugin', 'rrze-multisite-manager')];
        }

        if (str_starts_with($file, $stylesheetDirectory)) {
            return ['name' => __('Theme', 'rrze-multisite-manager')];
        }

        return ['name' => __('Unknown', 'rrze-multisite-manager')];
    }

    /**
     * Checks the runtime registry and shortcode declarations of plugins active on the current site.
     *
     * switch_to_blog() does not load plugins that are active only on the target site. Inspecting
     * their add_shortcode() declarations prevents those shortcodes from being reported as missing.
     *
     * @return array{name: string, plugin_file: string}
     */
    protected function getShortcodeRegistration(string $tag): array {
        if (shortcode_exists($tag)) {
            $provider = $this->getShortcodeProviderDetails($tag);

            return [
                'name' => (string)($provider['name'] ?? __('Unknown', 'rrze-multisite-manager')),
                'plugin_file' => (string)($provider['plugin_file'] ?? ''),
            ];
        }

        $registrations = $this->getActiveSiteShortcodeRegistrations();

        return $registrations[$tag] ?? [];
    }

    /**
     * @return array<string, array{name: string, plugin_file: string}>
     */
    protected function getActiveSiteShortcodeRegistrations(): array {
        $siteId = get_current_blog_id();

        if (isset($this->activeSiteShortcodeRegistrations[$siteId])) {
            return $this->activeSiteShortcodeRegistrations[$siteId];
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $activePluginFiles = array_merge(
            array_values(array_filter((array)get_option('active_plugins', []), 'is_string')),
            array_keys((array)get_site_option('active_sitewide_plugins', []))
        );
        $availablePlugins = get_plugins();
        $registrations = [];
        $pluginFile = '';

        foreach (array_unique($activePluginFiles) as $pluginFile) {
            if (!isset($availablePlugins[$pluginFile]) || !is_array($availablePlugins[$pluginFile])) {
                continue;
            }

            foreach ($this->getPluginShortcodeTags($pluginFile) as $shortcodeTag) {
                if (!isset($registrations[$shortcodeTag])) {
                    $registrations[$shortcodeTag] = [
                        'name' => (string)($availablePlugins[$pluginFile]['Name'] ?? $pluginFile),
                        'plugin_file' => $pluginFile,
                    ];
                }
            }
        }

        $this->activeSiteShortcodeRegistrations[$siteId] = $registrations;

        return $registrations;
    }

    /**
     * @return string[]
     */
    protected function getPluginShortcodeTags(string $pluginFile): array {
        $mainFilePath = trailingslashit(WP_PLUGIN_DIR) . ltrim($pluginFile, '/');
        $pluginDirectory = is_file($mainFilePath) ? dirname($mainFilePath) : '';
        $files = [];
        $iterator = null;
        $current = null;
        $source = '';
        $matches = [];
        $tags = [];

        if ($mainFilePath === '' || !is_readable($mainFilePath)) {
            return [];
        }

        $files[] = $mainFilePath;

        if ($pluginDirectory !== '' && is_dir($pluginDirectory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pluginDirectory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $current) {
                if (!$current instanceof \SplFileInfo || !$current->isFile() || strtolower($current->getExtension()) !== 'php') {
                    continue;
                }

                $files[] = (string)$current->getPathname();
            }
        }

        foreach (array_unique($files) as $file) {
            if (!is_readable($file)) {
                continue;
            }

            $source = (string)file_get_contents($file);

            if ($source === '' || !preg_match_all('/\badd_shortcode\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/m', $source, $matches)) {
                continue;
            }

            foreach ((array)($matches[1] ?? []) as $tag) {
                $tag = (string)$tag;

                if ($tag !== '') {
                    $tags[$tag] = $tag;
                }
            }
        }

        return array_values($tags);
    }

    protected function markStarted(int $siteId, array $state): void {
        update_blog_option($siteId, self::STATUS_OPTION, [
            'status' => 'running',
            'last_started_at' => current_time('mysql', true),
            'last_finished_at' => '',
            'last_error' => '',
            'phase' => self::SHORTCODE_PHASE,
            'processed_posts' => 0,
            'total_posts' => (int)($state['total_posts'] ?? 0),
            'phases' => $this->getScheduledPhaseStatuses(),
        ]);
        LoggingService::info($this->config, 'RRZE-MSM: Shortcode and block analysis started', ['site_id' => $siteId, 'site_url' => get_home_url($siteId, '/')]);
    }

    protected function markPhaseStarted(int $siteId, string $phase, array $state): void {
        $status = $this->getStatus($siteId);
        $status['status'] = 'running';
        $status['phase'] = $phase;
        $status['total_posts'] = (int)($state['total_posts'] ?? 0);
        $status['phases'] = is_array($status['phases'] ?? null) ? $status['phases'] : $this->getScheduledPhaseStatuses();
        $status['phases'][$phase] = [
            'status' => 'running',
            'started_at' => current_time('mysql', true),
            'finished_at' => '',
            'processed_posts' => 0,
            'total_posts' => (int)($state['total_posts'] ?? 0),
        ];
        update_blog_option($siteId, self::STATUS_OPTION, $status);
    }

    protected function markProgress(int $siteId, string $phase, array $state): void {
        $status = $this->getStatus($siteId);
        $processedKey = $phase === self::SHORTCODE_PHASE ? 'shortcode_processed_posts' : 'block_processed_posts';
        $processed = (int)($state[$processedKey] ?? 0);

        $status['status'] = 'running';
        $status['phase'] = $phase;
        $status['processed_posts'] = $processed;
        $status['total_posts'] = (int)($state['total_posts'] ?? 0);
        $status['phases'] = is_array($status['phases'] ?? null) ? $status['phases'] : $this->getScheduledPhaseStatuses();
        $status['phases'][$phase] = array_merge(
            (array)($status['phases'][$phase] ?? []),
            [
                'status' => 'running',
                'processed_posts' => $processed,
                'total_posts' => (int)($state['total_posts'] ?? 0),
            ]
        );
        update_blog_option($siteId, self::STATUS_OPTION, $status);
    }

    protected function markPhaseFinished(int $siteId, string $phase, array $state): void {
        $status = $this->getStatus($siteId);
        $processedKey = $phase === self::SHORTCODE_PHASE ? 'shortcode_processed_posts' : 'block_processed_posts';

        $status['phases'] = is_array($status['phases'] ?? null) ? $status['phases'] : $this->getScheduledPhaseStatuses();
        $status['phases'][$phase] = array_merge(
            (array)($status['phases'][$phase] ?? []),
            [
                'status' => 'complete',
                'finished_at' => current_time('mysql', true),
                'processed_posts' => (int)($state[$processedKey] ?? 0),
                'total_posts' => (int)($state['total_posts'] ?? 0),
            ]
        );
        update_blog_option($siteId, self::STATUS_OPTION, $status);
    }

    protected function finish(int $siteId, array $state): void {
        $result = [
            'generated_at' => current_time('mysql', true),
            'processed_posts' => (int)($state['block_processed_posts'] ?? 0),
            'total_posts' => (int)($state['total_posts'] ?? 0),
            'shortcodes' => array_values((array)($state['shortcodes'] ?? [])),
            'blocks' => array_values((array)($state['blocks'] ?? [])),
        ];
        update_blog_option($siteId, self::RESULT_OPTION, $result);
        $status = $this->getStatus($siteId);
        $status = array_merge($status, [
            'status' => 'complete',
            'last_started_at' => (string)($status['last_started_at'] ?? ''),
            'last_finished_at' => current_time('mysql', true),
            'last_error' => '',
            'phase' => 'complete',
            'processed_posts' => (int)($state['block_processed_posts'] ?? 0),
            'total_posts' => (int)($state['total_posts'] ?? 0),
        ]);
        update_blog_option($siteId, self::STATUS_OPTION, $status);
        LoggingService::info($this->config, 'RRZE-MSM: Shortcode and block analysis finished', ['site_id' => $siteId, 'site_url' => get_home_url($siteId, '/'), 'shortcodes' => count($result['shortcodes']), 'blocks' => count($result['blocks'])]);
    }

    protected function markFailed(int $siteId, string $message): void {
        $status = $this->getStatus($siteId);
        $phase = (string)($status['phase'] ?? self::SHORTCODE_PHASE);

        $status['status'] = 'error';
        $status['last_finished_at'] = current_time('mysql', true);
        $status['last_error'] = $message;
        $status['phases'] = is_array($status['phases'] ?? null) ? $status['phases'] : $this->getDefaultPhaseStatuses();
        $status['phases'][$phase] = array_merge(
            (array)($status['phases'][$phase] ?? []),
            [
                'status' => 'error',
                'finished_at' => current_time('mysql', true),
            ]
        );
        update_blog_option($siteId, self::STATUS_OPTION, $status);
    }

    protected function markTimedOut(int $siteId): void {
        $message = __('The shortcode and block analysis was aborted because it exceeded the configured runtime limit.', 'rrze-multisite-manager');
        $status = $this->getStatus($siteId);
        $phase = (string)($status['phase'] ?? self::SHORTCODE_PHASE);

        $status['status'] = 'error';
        $status['last_finished_at'] = current_time('mysql', true);
        $status['last_error'] = $message;
        $status['phases'] = is_array($status['phases'] ?? null) ? $status['phases'] : $this->getDefaultPhaseStatuses();
        $status['phases'][$phase] = array_merge(
            (array)($status['phases'][$phase] ?? []),
            [
                'status' => 'error',
                'finished_at' => current_time('mysql', true),
            ]
        );
        update_blog_option($siteId, self::STATUS_OPTION, $status);
        $this->releaseLock($siteId);
        do_action(
            'rrze.log.error',
            'RRZE-MSM: Shortcode and block analysis exceeded its runtime limit',
            [
                'site_id' => $siteId,
                'site_url' => get_home_url($siteId, '/'),
                'timeout_seconds' => $this->config->getShortcodeBlockAnalysisTimeoutSeconds(),
            ]
        );
    }

    protected function getDefaultPhaseStatuses(): array {
        return [
            self::SHORTCODE_PHASE => ['status' => 'idle', 'started_at' => '', 'finished_at' => '', 'processed_posts' => 0, 'total_posts' => 0],
            self::BLOCK_PHASE => ['status' => 'idle', 'started_at' => '', 'finished_at' => '', 'processed_posts' => 0, 'total_posts' => 0],
        ];
    }

    protected function getScheduledPhaseStatuses(): array {
        $phases = $this->getDefaultPhaseStatuses();

        foreach (array_keys($phases) as $phase) {
            $phases[$phase]['status'] = 'scheduled';
        }

        return $phases;
    }

    protected function extendRuntimeLimit(int $deadline): void {
        if (!function_exists('set_time_limit')) {
            return;
        }

        @set_time_limit(max(1, $deadline - time() + MINUTE_IN_SECONDS));
    }

    protected function getLockTtl(): int {
        return $this->config->getShortcodeBlockAnalysisTimeoutSeconds() + MINUTE_IN_SECONDS;
    }

    protected function unschedule(int $siteId): void {
        $timestamp = (int)wp_next_scheduled($this->getHook(), [$siteId]);

        while ($timestamp > 0) {
            wp_unschedule_event($timestamp, $this->getHook(), [$siteId]);
            $timestamp = (int)wp_next_scheduled($this->getHook(), [$siteId]);
        }
    }

    public function reconcileSiteSchedule(int $siteId): void {
        if (!$this->isSiteActive($siteId)) {
            $this->deactivateSite($siteId);
        }
    }

    public function scheduleNewSiteRecurringAnalysis(int $siteId): void {
        // Scheduling is deliberately only initiated by an explicit user action.
        // A newly active site must not recreate a removed recurring event.
    }

    protected function scheduleRecurringAnalysis(int $siteId): bool {
        $delay = MINUTE_IN_SECONDS + ($siteId % (5 * MINUTE_IN_SECONDS));
        return $this->scheduleRecurringAnalysisAt($siteId, time() + $delay);
    }

    protected function scheduleRecurringAnalysisAt(int $siteId, int $timestamp): bool {
        if (!$this->isSiteActive($siteId) || $this->getNextRecurringScheduledTimestamp($siteId) > 0) {
            return false;
        }

        // Replace one-off events from older plugin versions before adding the recurring event.
        $this->unschedule($siteId);
        $this->markScheduled($siteId);
        return (bool)wp_schedule_event(max(time(), $timestamp), $this->getScheduleKey(), $this->getHook(), [$siteId]);
    }

    protected function getNextRecurringScheduledTimestamp(int $siteId): int {
        $cron = _get_cron_array();
        $expectedSchedule = $this->getScheduleKey();

        if (!is_array($cron)) {
            return 0;
        }

        foreach ($cron as $timestamp => $events) {
            foreach ((array)($events[$this->getHook()] ?? []) as $event) {
                if ((array)($event['args'] ?? []) === [$siteId] && (string)($event['schedule'] ?? '') === $expectedSchedule) {
                    return (int)$timestamp;
                }
            }
        }

        return 0;
    }

    protected function hasRecurringScheduledAnalysis(int $siteId): bool {
        $cron = _get_cron_array();
        $events = [];
        $event = [];

        if (!is_array($cron)) {
            return false;
        }

        foreach ($cron as $events) {
            foreach ((array)($events[$this->getHook()] ?? []) as $event) {
                if ((array)($event['args'] ?? []) === [$siteId] && !empty($event['schedule'])) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function hasAnyRecurringScheduledAnalysis(): bool {
        $cron = _get_cron_array();

        foreach ((array)$cron as $events) {
            foreach ((array)($events[$this->getHook()] ?? []) as $event) {
                if (!empty($event['schedule'])) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function getScheduleKey(): string {
        $options = get_site_option($this->config->getOptionName(), []);
        $frequency = is_array($options) ? (string)($options['monitoring_shortcode_block_analysis_frequency'] ?? 'twiceweekly') : 'twiceweekly';
        $keys = [
            'weekly' => 'rrze_msm_shortcode_block_weekly',
            'twiceweekly' => 'rrze_msm_shortcode_block_twice_weekly',
            'daily' => 'rrze_msm_shortcode_block_daily',
            'twicedaily' => 'rrze_msm_shortcode_block_twice_daily',
            'fourtimesdaily' => 'rrze_msm_shortcode_block_four_times_daily',
        ];

        return $keys[$frequency] ?? $keys['twiceweekly'];
    }

    protected function getScheduleSignature(): string {
        return $this->getScheduleKey() . ':single-process-v1';
    }

    protected function getScheduleLabel(): string {
        $labels = [
            'rrze_msm_shortcode_block_weekly' => __('Once weekly', 'rrze-multisite-manager'),
            'rrze_msm_shortcode_block_twice_weekly' => __('Twice weekly', 'rrze-multisite-manager'),
            'rrze_msm_shortcode_block_daily' => __('Once daily', 'rrze-multisite-manager'),
            'rrze_msm_shortcode_block_twice_daily' => __('Twice daily', 'rrze-multisite-manager'),
            'rrze_msm_shortcode_block_four_times_daily' => __('Four times daily', 'rrze-multisite-manager'),
        ];

        return $labels[$this->getScheduleKey()] ?? $labels['rrze_msm_shortcode_block_twice_weekly'];
    }

    protected function markScheduled(int $siteId): void {
        $status = $this->getStatus($siteId);
        $hasCompletedResult = !empty($this->getResult($siteId)['generated_at']);
        $status = array_merge(
            $status,
            [
                'status' => $hasCompletedResult ? 'complete' : 'scheduled',
                'last_error' => '',
                'phase' => $hasCompletedResult ? 'complete' : self::SHORTCODE_PHASE,
                'processed_posts' => $hasCompletedResult ? (int)($status['processed_posts'] ?? 0) : 0,
                'total_posts' => $hasCompletedResult ? (int)($status['total_posts'] ?? 0) : 0,
            ]
        );

        // Keep the previous successful phase timestamps visible until a new run starts.
        if (!$hasCompletedResult || !is_array($status['phases'] ?? null)) {
            $status['phases'] = $this->getScheduledPhaseStatuses();
        }

        update_blog_option($siteId, self::STATUS_OPTION, $status);
    }

    protected function isSiteUnscheduled(int $siteId): bool {
        if (!$this->isSiteActive($siteId)) {
            return false;
        }

        return !$this->isRunning($siteId)
            && $this->getNextRecurringScheduledTimestamp($siteId) <= 0
            && empty($this->getResult($siteId)['generated_at']);
    }

    protected function isRunning(int $siteId): bool {
        return (string)($this->getStatus($siteId)['status'] ?? '') === 'running';
    }

    protected function hasActiveLock(int $siteId): bool {
        $startedAt = (int)get_site_option(self::LOCK_OPTION_PREFIX . $siteId, 0);

        return $startedAt > 0 && (time() - $startedAt) <= $this->getLockTtl();
    }

    protected function recoverInterruptedAnalysis(int $siteId, array $status = []): bool {
        if ($siteId <= 0) {
            return false;
        }

        if (empty($status)) {
            $status = $this->getStatus($siteId);
        }

        if ((string)($status['status'] ?? '') !== 'running' || $this->hasActiveLock($siteId)) {
            return false;
        }

        $this->markFailed(
            $siteId,
            __('The shortcode and block analysis was interrupted before it could finish.', 'rrze-multisite-manager')
        );
        do_action(
            'rrze.log.error',
            'RRZE-MSM: Shortcode and block analysis interrupted unexpectedly',
            [
                'site_id' => $siteId,
                'site_url' => get_home_url($siteId, '/'),
            ]
        );

        return true;
    }

    protected function isAnalysisTimedOut(int $siteId): bool {
        $status = $this->getStatus($siteId);
        $startedAt = (string)($status['last_started_at'] ?? '');
        $startedTimestamp = $startedAt !== '' ? (int)strtotime($startedAt . ' UTC') : 0;

        return (string)($status['status'] ?? '') === 'running'
            && $startedTimestamp > 0
            && (time() - $startedTimestamp) >= $this->config->getShortcodeBlockAnalysisTimeoutSeconds();
    }

    protected function acquireLock(int $siteId): bool {
        $key = self::LOCK_OPTION_PREFIX . $siteId;
        $existing = (int)get_site_option($key, 0);

        if ($existing > 0 && (time() - $existing) > $this->getLockTtl()) {
            delete_site_option($key);
        }

        return add_site_option($key, time());
    }

    protected function releaseLock(int $siteId): void {
        delete_site_option(self::LOCK_OPTION_PREFIX . $siteId);
    }

    protected function getHook(): string {
        return $this->config->getShortcodeBlockAnalysisHook();
    }

    public function isSiteActive(int $siteId): bool {
        $site = $siteId > 0 ? get_site($siteId) : null;

        return $site instanceof \WP_Site
            && (int)$site->archived === 0
            && (int)$site->spam === 0
            && (int)$site->deleted === 0;
    }
}
