<?php

namespace RRZE\MultisiteManager\Analysis;

use RRZE\MultisiteManager\Support\BoundedArray;
use RRZE\MultisiteManager\Support\SafeSerializedValue;

defined('ABSPATH') || exit;

/** Installation-wide derived data. Every query is network-scoped; no object-cache payloads. */
class AnalysisStore {
    public const SCHEMA_VERSION = 3;
    // Destructive legacy-data replacement is independent of additive schema repairs.
    public const DATA_VERSION = 1;
    public const CHECKPOINT_BYTES = 1048576;
    public const FINDING_BYTES = 8192;
    public const PAGE_SIZE = 50;

    public function table(string $kind): string {
        global $wpdb;
        if (!in_array($kind, ['runs', 'shortcodes', 'blocks', 'mediaindex'], true)) {
            throw new \InvalidArgumentException('Invalid analysis table.');
        }
        return $wpdb->base_prefix . 'rrze_msm_analysis_' . $kind;
    }

    /** Explicitly requested only during confirmed network plugin deactivation. */
    public function dropTables(): void {
        global $wpdb;
        foreach (['shortcodes', 'blocks', 'mediaindex', 'runs'] as $kind) {
            $table = $this->table($kind);
            $this->check($wpdb->query("DROP TABLE IF EXISTS $table"), 'drop-' . $kind);
        }
        delete_site_option('rrze_msm_analysis_schema');
    }

    public function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $runs = $this->table('runs');
        dbDelta("CREATE TABLE $runs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            network_id bigint(20) unsigned NOT NULL,
            blog_id bigint(20) unsigned NOT NULL,
            run_id varchar(36) NOT NULL,
            slot varchar(191) NOT NULL,
            kind varchar(24) NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'running',
            payload longtext NOT NULL,
            summary text NOT NULL,
            updated_at bigint(20) unsigned NOT NULL,
            expires_at bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slot (network_id,blog_id,slot),
            UNIQUE KEY run (network_id,blog_id,run_id),
            KEY completed (network_id,blog_id,kind,status,id),
            KEY expiry (network_id,expires_at,id)
        ) ENGINE=InnoDB $charset;");
        foreach (['shortcodes', 'blocks', 'mediaindex'] as $kind) {
            $table = $this->table($kind);
            $contentColumns = $kind === 'mediaindex' ? '' : "registered tinyint(1) NOT NULL DEFAULT 1,
                provider varchar(191) NOT NULL DEFAULT '',
                category varchar(191) NOT NULL DEFAULT '',
                origin varchar(191) NOT NULL DEFAULT '',
                description varchar(1024) NOT NULL DEFAULT '',";
            dbDelta("CREATE TABLE $table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                network_id bigint(20) unsigned NOT NULL,
                blog_id bigint(20) unsigned NOT NULL,
                run_id varchar(36) NOT NULL,
                entry_key varchar(64) NOT NULL,
                name varchar(191) NOT NULL DEFAULT '',
                post_id bigint(20) unsigned NOT NULL DEFAULT 0,
                payload longtext NOT NULL,
                payload_bytes int(10) unsigned NOT NULL,
                $contentColumns
                PRIMARY KEY  (id),
                UNIQUE KEY entry (network_id,blog_id,run_id,entry_key),
                KEY names (network_id,blog_id,run_id,name,id)
            ) ENGINE=InnoDB $charset;");
        }
        $this->verifySchema();
        update_site_option('rrze_msm_analysis_schema', self::SCHEMA_VERSION);
        if ((int)get_site_option('rrze_msm_analysis_schema', 0) !== self::SCHEMA_VERSION) {
            $this->fail('schema-install');
        }
    }

    /** Never mark a partial dbDelta installation as current merely because its tables exist. */
    protected function verifySchema(): void {
        global $wpdb;
        $common = ['id', 'network_id', 'blog_id', 'run_id'];
        foreach (['runs', 'shortcodes', 'blocks', 'mediaindex'] as $kind) {
            $table = $this->table($kind);
            $definition = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)));
            if (!$definition || strtolower((string)$definition->Engine) !== 'innodb') {
                $this->fail('schema-engine-' . $kind);
            }
            $required = $kind === 'runs'
                ? ['slot', 'kind', 'status', 'payload', 'summary', 'updated_at', 'expires_at']
                : ['entry_key', 'name', 'post_id', 'payload', 'payload_bytes'];
            if ($kind === 'shortcodes' || $kind === 'blocks') {
                $required = array_merge($required, ['registered', 'provider', 'category', 'origin', 'description']);
            }
            // Table identifiers come only from table()'s fixed allowlist and the WP base prefix.
            $columns = $wpdb->get_col("SHOW COLUMNS FROM $table");
            if ($wpdb->last_error || array_diff(array_merge($common, $required), (array)$columns)) {
                $this->fail('schema-columns-' . $kind);
            }
        }
    }

    public function active(int $siteId, string $slot): ?object {
        global $wpdb;
        $table = $this->table('runs');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE network_id = %d AND blog_id = %d AND slot = %s AND status = 'running' AND expires_at > %d", get_current_network_id(), $siteId, $slot, time()));
        if ($wpdb->last_error) {
            $this->fail('run-read');
        }
        return $row;
    }

    public function ensureRun(int $siteId, string $slot, string $kind, int $ttl = DAY_IN_SECONDS): object {
        global $wpdb;
        $row = $this->active($siteId, $slot);
        if ($row) {
            return $row;
        }
        $this->retire($siteId, $slot);
        $table = $this->table('runs');
        $runId = wp_generate_uuid4();
        $this->check($wpdb->insert($table, ['network_id' => get_current_network_id(), 'blog_id' => $siteId, 'run_id' => $runId, 'slot' => $slot, 'kind' => $kind, 'status' => 'running', 'payload' => serialize([]), 'summary' => serialize([]), 'updated_at' => time(), 'expires_at' => time() + $ttl]));
        $row = $this->active($siteId, $slot);
        if (!$row || $row->run_id !== $runId) {
            $this->fail('run-create');
        }
        return $row;
    }

    public function decode(string $payload, int $limit = self::CHECKPOINT_BYTES): array {
        $value = (new SafeSerializedValue())->decode($payload, $limit);
        if (empty($value['valid']) || !is_array($value['value'])) {
            $this->fail('payload-decode');
        }
        return $value['value'];
    }

    public function checkpoint(int $siteId, string $slot): array {
        $row = $this->active($siteId, $slot);
        return $row ? $this->decode($row->payload) : [];
    }

    public function saveCheckpoint(int $siteId, string $slot, string $kind, array $state, int $ttl = DAY_IN_SECONDS): void {
        global $wpdb;
        $this->assertSize($state, self::CHECKPOINT_BYTES);
        $run = $this->ensureRun($siteId, $slot, $kind, $ttl);
        $payload = serialize($state);
        $changed = $wpdb->update($this->table('runs'), ['payload' => $payload, 'updated_at' => time(), 'expires_at' => time() + $ttl], ['network_id' => get_current_network_id(), 'blog_id' => $siteId, 'run_id' => $run->run_id, 'status' => 'running']);
        $this->check($changed, 'checkpoint-write');
        if ($changed === 0) {
            $current = $this->active($siteId, $slot);
            if (!$current || $current->run_id !== $run->run_id || $current->payload !== $payload) {
                $this->fail('checkpoint-save');
            }
        }
    }

    public function retire(int $siteId, string $slot): void {
        global $wpdb;
        $table = $this->table('runs');
        $this->check($wpdb->query($wpdb->prepare("UPDATE $table SET slot = run_id, status = 'obsolete', expires_at = %d WHERE network_id = %d AND blog_id = %d AND slot = %s AND status = 'running'", time(), get_current_network_id(), $siteId, $slot)));
    }

    /** Hide all generations; lifecycle cleanup deletes their large child rows in bounded batches. */
    public function removeSiteAnalysis(int $siteId, string $analysis): void {
        global $wpdb;
        if (!in_array($analysis, ['content', 'storage'], true)) {
            throw new \InvalidArgumentException('Invalid analysis kind.');
        }
        $table = $this->table('runs');
        $where = $analysis === 'content'
            ? "kind = 'content'"
            : "kind IN ('storage_base','storage_orphan','storage_metadata','mediaindex')";
        $this->check($wpdb->query($wpdb->prepare("UPDATE $table SET slot = run_id, status = 'obsolete', expires_at = %d WHERE network_id = %d AND blog_id = %d AND $where", time(), get_current_network_id(), $siteId)), $analysis . '-remove-runs');
        AnalysisLifecycle::scheduleMaintenanceSoon();
    }

    public function writeEntry(string $kind, int $siteId, string $runId, string $key, array $value, string $name = '', int $postId = 0): void {
        global $wpdb;
        $limit = $kind === 'mediaindex' ? 131072 : self::FINDING_BYTES;
        $this->assertSize($value, $limit);
        $payload = serialize($value);
        $table = $this->table($kind);
        $runs = $this->table('runs');
        $columns = '';
        $values = '';
        $updates = '';
        $arguments = [$key, wp_check_invalid_utf8(substr($name, 0, 191), true), $postId, $payload, strlen($payload)];
        if ($kind !== 'mediaindex') {
            foreach (['registered', 'provider', 'category', 'origin', 'description'] as $column) {
                $columns .= ',' . $column;
                $field = $column === 'registered' ? (int)!empty($value[$column]) : wp_check_invalid_utf8(substr((string)($value[$column] ?? ''), 0, $column === 'description' ? 1024 : 191), true);
                $values .= ',%s';
                $arguments[] = $field;
                $updates .= ", $column = VALUES($column)";
            }
        }
        $this->check($wpdb->query($wpdb->prepare("INSERT INTO $table (network_id,blog_id,run_id,entry_key,name,post_id,payload,payload_bytes$columns) SELECT network_id,blog_id,run_id,%s,%s,%d,%s,%d$values FROM $runs WHERE network_id = %d AND blog_id = %d AND run_id = %s AND status = 'running' AND expires_at > %d ON DUPLICATE KEY UPDATE payload = VALUES(payload), payload_bytes = VALUES(payload_bytes), name = VALUES(name)$updates", ...array_merge($arguments, [get_current_network_id(), $siteId, $runId, time()]))), $kind . '-entry-write');
        if (!$wpdb->get_var($wpdb->prepare("SELECT 1 FROM $runs WHERE network_id = %d AND blog_id = %d AND run_id = %s AND status = 'running' AND expires_at > %d", get_current_network_id(), $siteId, $runId, time()))) {
            $this->fail('entry-run-validation');
        }
    }

    public function readEntry(string $kind, int $siteId, string $runId, string $key): ?array {
        global $wpdb;
        $table = $this->table($kind);
        $value = $wpdb->get_var($wpdb->prepare("SELECT payload FROM $table WHERE network_id = %d AND blog_id = %d AND run_id = %s AND entry_key = %s", get_current_network_id(), $siteId, $runId, $key));
        if ($wpdb->last_error) {
            $this->fail('entry-read');
        }
        return $value === null ? null : $this->decode($value, $kind === 'mediaindex' ? 131072 : self::FINDING_BYTES);
    }

    /** Only completed generations are visible. Monitoring never loads findings. */
    public function summary(int $siteId): array {
        global $wpdb;
        $table = $this->table('runs');
        $row = $wpdb->get_row($wpdb->prepare("SELECT run_id, summary FROM $table WHERE network_id = %d AND blog_id = %d AND kind = 'content' AND status = 'complete' ORDER BY id DESC LIMIT 1", get_current_network_id(), $siteId));
        if ($wpdb->last_error) {
            $this->fail('summary-read');
        }
        return $row ? $this->decode($row->summary, 16384) + ['run_id' => $row->run_id] : [];
    }

    public function publish(int $siteId, string $runId, array $summary): void {
        global $wpdb;
        $this->assertSize($summary, 16384);
        $table = $this->table('runs');
        $this->check($wpdb->query('START TRANSACTION'));
        try {
            $this->check($wpdb->query($wpdb->prepare("UPDATE $table SET status = 'obsolete', expires_at = %d WHERE network_id = %d AND blog_id = %d AND kind = 'content' AND status = 'complete' AND run_id <> %s", time(), get_current_network_id(), $siteId, $runId)));
            $changed = $wpdb->query($wpdb->prepare("UPDATE $table SET status = 'complete', slot = run_id, payload = %s, summary = %s, expires_at = 0, updated_at = %d WHERE network_id = %d AND blog_id = %d AND run_id = %s AND status = 'running' AND expires_at > %d", serialize([]), serialize($summary), time(), get_current_network_id(), $siteId, $runId, time()));
            if ($changed !== 1) {
                $this->fail('run-publish');
            }
            $this->check($wpdb->query('COMMIT'));
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    /** Filtering and paging happen in SQL, before any payload is decoded. */
    public function findings(int $siteId, string $runId, string $kind, int $page = 1, string $filter = '', string $sort = 'name', string $order = 'asc'): array {
        global $wpdb;
        if (!in_array($kind, ['shortcodes', 'blocks'], true)) {
            throw new \InvalidArgumentException('Invalid finding kind.');
        }
        $page = max(1, min(100000, $page));
        $table = $this->table($kind);
        $sort = in_array($sort, ['registered', 'provider', 'category', 'origin', 'description'], true) ? $sort : 'name';
        $order = $order === 'desc' ? 'DESC' : 'ASC';
        $where = $wpdb->prepare('network_id = %d AND blog_id = %d AND run_id = %s AND post_id > 0', get_current_network_id(), $siteId, $runId);
        $terms = array_slice(array_filter(array_map('trim', explode(',', substr($filter, 0, 1024)))), 0, 20);
        if ($terms) {
            $clauses = [];
            foreach ($terms as $term) {
                $clauses[] = $wpdb->prepare('name LIKE %s', '%' . $wpdb->esc_like($term) . '%');
            }
            $where .= ' AND (' . implode(' OR ', $clauses) . ')';
        }
        $rows = $wpdb->get_col($wpdb->prepare("SELECT payload FROM $table WHERE $where ORDER BY $sort $order, name ASC, id ASC LIMIT %d OFFSET %d", self::PAGE_SIZE + 1, (max(1, $page) - 1) * self::PAGE_SIZE));
        if ($wpdb->last_error) {
            $this->fail('findings-read');
        }
        $hasMore = count($rows) > self::PAGE_SIZE;
        $entries = [];
        foreach (array_slice($rows, 0, self::PAGE_SIZE) as $payload) {
            $entries[] = $this->decode($payload, self::FINDING_BYTES);
        }
        return ['entries' => $entries, 'has_more' => $hasMore, 'page' => max(1, $page)];
    }

    /**
     * Retrieves complete finding groups for one result-table page. Paging by
     * the displayed name, rather than individual occurrences, prevents a
     * "next page" link when all visible rows already fit on the first page.
     */
    public function groupedFindings(int $siteId, string $runId, string $kind, int $page = 1, string $filter = '', string $sort = 'name', string $order = 'asc'): array {
        global $wpdb;
        if (!in_array($kind, ['shortcodes', 'blocks'], true)) {
            throw new \InvalidArgumentException('Invalid finding kind.');
        }

        $page = max(1, min(100000, $page));
        $table = $this->table($kind);
        $sort = in_array($sort, ['registered', 'provider', 'category', 'origin', 'description'], true) ? $sort : 'name';
        $order = $order === 'desc' ? 'DESC' : 'ASC';
        $where = $wpdb->prepare('network_id = %d AND blog_id = %d AND run_id = %s AND post_id > 0', get_current_network_id(), $siteId, $runId);
        $terms = array_slice(array_filter(array_map('trim', explode(',', substr($filter, 0, 1024)))), 0, 20);

        if ($terms) {
            $clauses = [];
            foreach ($terms as $term) {
                $clauses[] = $wpdb->prepare('name LIKE %s', '%' . $wpdb->esc_like($term) . '%');
            }
            $where .= ' AND (' . implode(' OR ', $clauses) . ')';
        }

        $total = (int)$wpdb->get_var("SELECT COUNT(DISTINCT name) FROM $table WHERE $where");
        if ($wpdb->last_error) {
            $this->fail('finding-groups-count');
        }

        $limit = self::PAGE_SIZE;
        $offset = ($page - 1) * self::PAGE_SIZE;
        $names = $wpdb->get_col("SELECT name FROM $table WHERE $where GROUP BY name ORDER BY MIN($sort) $order, name ASC LIMIT $limit OFFSET $offset");
        if ($wpdb->last_error) {
            $this->fail('finding-groups-read');
        }
        if (!$names) {
            return ['entries' => [], 'total' => $total, 'page' => $page, 'per_page' => self::PAGE_SIZE];
        }

        $quotedNames = implode(',', array_map(static fn(string $name): string => $wpdb->prepare('%s', $name), array_map('strval', $names)));
        $rows = $wpdb->get_col("SELECT payload FROM $table WHERE $where AND name IN ($quotedNames) ORDER BY name ASC, id ASC");
        if ($wpdb->last_error) {
            $this->fail('finding-group-entries-read');
        }

        $entries = [];
        foreach ($rows as $payload) {
            $entries[] = $this->decode($payload, self::FINDING_BYTES);
        }

        return ['entries' => $entries, 'total' => $total, 'page' => $page, 'per_page' => self::PAGE_SIZE];
    }

    public function assertSize(array $value, int $limit): void {
        if (!BoundedArray::fits($value, $limit)) {
            throw new \RuntimeException(__('The analysis data exceeded its storage budget.', 'rrze-multisite-manager'));
        }
    }

    public function check(int|bool $result, string $operation = 'database-write'): void {
        if ($result === false) {
            $this->fail($operation);
        }
    }

    private function fail(string $operation): never {
        AnalysisStorageException::raise($operation);
    }
}
