<?php
/** SQLite exercises persistence/transactions in isolated tests; it does not validate MySQL/dbDelta. */
class AnalysisDatabase {
    private static ?PDO $database = null;
    public string $base_prefix = 'wp_';
    public string $last_error = '';
    public string $failOn = '';
    private ?object $delegate;

    public function __construct(?object $delegate = null) {
        $this->delegate = $delegate;
        if (self::$database) {
            return;
        }
        self::$database = new PDO('sqlite::memory:');
        self::$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$database->exec("CREATE TABLE wp_rrze_msm_analysis_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, network_id INTEGER, blog_id INTEGER, run_id TEXT, slot TEXT, kind TEXT, status TEXT, payload TEXT, summary TEXT, updated_at INTEGER, expires_at INTEGER, UNIQUE(network_id,blog_id,slot), UNIQUE(network_id,blog_id,run_id))");
        foreach (['shortcodes', 'blocks', 'mediaindex'] as $kind) {
            self::$database->exec("CREATE TABLE wp_rrze_msm_analysis_$kind (id INTEGER PRIMARY KEY AUTOINCREMENT, network_id INTEGER, blog_id INTEGER, run_id TEXT, entry_key TEXT, name TEXT DEFAULT '', post_id INTEGER DEFAULT 0, payload TEXT, payload_bytes INTEGER, registered INTEGER DEFAULT 1, provider TEXT DEFAULT '', category TEXT DEFAULT '', origin TEXT DEFAULT '', description TEXT DEFAULT '', UNIQUE(network_id,blog_id,run_id,entry_key))");
        }
        self::$database->exec("CREATE TABLE wp_rrze_msm_analysis_dashboard_metrics (id INTEGER PRIMARY KEY AUTOINCREMENT, network_id INTEGER, blog_id INTEGER, entry_key TEXT, snapshot_status TEXT, recorded_at INTEGER, site_status TEXT DEFAULT '', websites_total INTEGER DEFAULT 0, websites_active INTEGER DEFAULT 0, websites_archived INTEGER DEFAULT 0, websites_blocked INTEGER DEFAULT 0, websites_deleted INTEGER DEFAULT 0, media_bytes INTEGER NULL, database_bytes INTEGER NULL, plugin_count INTEGER DEFAULT 0, theme_count INTEGER DEFAULT 0, user_count INTEGER DEFAULT 0, page_count INTEGER DEFAULT 0, post_count INTEGER DEFAULT 0, media_count INTEGER DEFAULT 0, custom_post_type_count INTEGER DEFAULT 0, UNIQUE(network_id,blog_id,entry_key))");
    }

    public function &__get(string $key): mixed { return $this->delegate->$key; }
    public function __set(string $key, mixed $value): void { $this->delegate->$key = $value; }
    private function central(string $sql): bool { return str_contains($sql, 'rrze_msm_analysis_') || in_array($sql, ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true); }
    public function prepare(string $query, ...$args): string {
        if (!$this->central($query) && $this->delegate) {
            return $this->delegate->prepare($query, ...$args);
        }
        $position = 0;
        return preg_replace_callback('/%[sdf]/', function ($match) use (&$position, $args): string {
            $value = $args[$position++];
            return $match[0] === '%s' ? self::$database->quote((string)$value) : (string)(int)$value;
        }, $query);
    }
    public function esc_like(string $value): string { return addcslashes($value, '_%'); }
    private function sql(string $sql): string {
        $sql = str_replace(['START TRANSACTION', ' FOR UPDATE'], ['BEGIN TRANSACTION', ''], $sql);
        $sql = str_replace(' ON DUPLICATE KEY UPDATE ', ' ON CONFLICT(network_id,blog_id,run_id,entry_key) DO UPDATE SET ', $sql);
        $sql = preg_replace('/VALUES\((\w+)\)/', 'excluded.$1', $sql);
        // SQLite builds need not support DELETE LIMIT.
        $sql = preg_replace('/^DELETE FROM (\w+) WHERE (.*) LIMIT (\d+)$/s', 'DELETE FROM $1 WHERE id IN (SELECT id FROM $1 WHERE $2 LIMIT $3)', $sql);
        return $sql;
    }
    private function execute(string $sql): PDOStatement|false {
        $this->last_error = '';
        if ($this->failOn !== '' && str_contains($sql, $this->failOn)) {
            $this->last_error = 'Injected persistence failure';
            return false;
        }
        try { return self::$database->query($this->sql($sql)); }
        catch (Throwable $error) { $this->last_error = $error->getMessage(); throw new RuntimeException($this->last_error . ' SQL: ' . $sql); }
    }
    public function query(string $sql): int|false {
        if (!$this->central($sql) && $this->delegate) { return $this->delegate->query($sql); }
        $result = $this->execute($sql);
        return $result ? $result->rowCount() : false;
    }
    public function get_results(string $sql): array {
        if (!$this->central($sql) && $this->delegate) { return $this->delegate->get_results($sql); }
        $result = $this->execute($sql);
        return $result ? $result->fetchAll(PDO::FETCH_OBJ) : [];
    }
    public function get_row(string $sql): ?object {
        if (str_starts_with($sql, 'SHOW TABLE STATUS LIKE')) { return (object)['Engine' => 'InnoDB']; }
        if (!$this->central($sql) && $this->delegate) { return $this->delegate->get_row($sql); }
        return $this->get_results($sql)[0] ?? null;
    }
    public function get_col(string $sql): array {
        if (preg_match('/^SHOW COLUMNS FROM (wp_rrze_msm_analysis_\w+)$/', $sql, $match)) {
            $this->last_error = '';
            return self::$database->query('PRAGMA table_info(' . $match[1] . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
        }
        if (!$this->central($sql) && $this->delegate) { return $this->delegate->get_col($sql); }
        $result = $this->execute($sql);
        return $result ? $result->fetchAll(PDO::FETCH_COLUMN) : [];
    }
    public function get_var(string $sql): mixed { return $this->get_col($sql)[0] ?? null; }
    public function insert(string $table, array $data): int|false {
        $columns = implode(',', array_keys($data));
        return $this->query($this->prepare("INSERT INTO $table ($columns) VALUES (" . implode(',', array_fill(0, count($data), '%s')) . ')', ...array_values($data)));
    }
    public function update(string $table, array $data, array $where): int|false {
        return $this->query("UPDATE $table SET " . $this->pairs($data, ',') . ' WHERE ' . $this->pairs($where, ' AND '));
    }
    public function delete(string $table, array $where): int|false { return $this->query("DELETE FROM $table WHERE " . $this->pairs($where, ' AND ')); }
    private function pairs(array $data, string $separator): string {
        $pairs = [];
        foreach ($data as $key => $value) { $pairs[] = $key . ' = ' . self::$database->quote((string)$value); }
        return implode($separator, $pairs);
    }
}
