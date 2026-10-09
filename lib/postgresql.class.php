<?php
require_once("logger.php");

if (class_exists('sql', false)) {
    return;
}

class sql
{
    private static $link = null;
    private $queryTimeThreshold = 0.5; // Time threshold in seconds
    private $connString = "";
    private $debug_level = 3; // 0 = quiet .. 3=use timer .. 5 = verbose

    public function __construct()
    {
        if (!function_exists('pg_connect')) {
            throw new \RuntimeException("PHP PostgreSQL extension is not loaded; pg_connect() is unavailable.");
        }

        $this->connString = $this->buildConnectionString();
        if (!self::$link || @pg_connection_status(self::$link) !== PGSQL_CONNECTION_OK) {
            self::$link = @pg_connect($this->connString);
        }

        if (!self::$link || self::$link === false) {
            Logger::error("SQL: connection init failed. " . $this->extract_caller() );
            die("SQL: Error in connection.");
        }

        $stat = pg_connection_status(self::$link);
        if ((!isset($stat)) || ($stat !== PGSQL_CONNECTION_OK)) {
            Logger::error("SQL: connection init FAILED [$stat] " . $this->extract_caller() );
            die("SQL: Error in connection.");
        }

        $this->applySearchPath();
        if ($this->debug_level > 4)
            Logger::debug("SQL: connected $stat to ". pg_host(self::$link) ."/". pg_dbname(self::$link) . " " . $this->extract_caller() . $this->GetLastError() );
    }

    /** Keep every initial and recovered connection on Synthserver's canonical schema order. */
    private function applySearchPath(): void
    {
        if (!self::$link || @pg_query(self::$link, "SET search_path TO synth, synth_meta, public") === false) {
            Logger::error("SQL: could not configure Synthserver search path. " . $this->extract_caller());
            die("SQL: Error configuring connection.");
        }
    }

    private function readConnectionSetting(string $envName, string $globalName, string $default): string
    {
        $envValue = getenv($envName);
        if ($envValue !== false && trim(strval($envValue)) !== '') {
            return trim(strval($envValue));
        }

        if (isset($GLOBALS[$globalName]) && trim(strval($GLOBALS[$globalName])) !== '') {
            return trim(strval($GLOBALS[$globalName]));
        }

        return $default;
    }

    private function buildConnectionString(): string
    {
        $host = $this->readConnectionSetting('SYNTH_DB_HOST', 'SYNTH_DB_HOST', '127.0.0.1');
        $dbname = $this->readConnectionSetting('SYNTH_DB_NAME', 'SYNTH_DB_NAME', 'synth');
        $user = $this->readConnectionSetting('SYNTH_DB_USER', 'SYNTH_DB_USER', 'dwemer');
        $password = $this->readConnectionSetting('SYNTH_DB_PASSWORD', 'SYNTH_DB_PASSWORD', 'dwemer');
        $connectTimeout = $this->readConnectionSetting('SYNTH_DB_CONNECT_TIMEOUT', 'SYNTH_DB_CONNECT_TIMEOUT', '90');

        return implode(' ', [
            'host=' . $this->escapeConnectionValue($host),
            'dbname=' . $this->escapeConnectionValue($dbname),
            'user=' . $this->escapeConnectionValue($user),
            'password=' . $this->escapeConnectionValue($password),
            'connect_timeout=' . $this->escapeConnectionValue($connectTimeout),
        ]);
    }

    private function escapeConnectionValue(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.:-]+$/', $value) === 1) {
            return $value;
        }

        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }

    private function re_connect() { //check connection availability and reconnect if connection expired
        $b_ok = true;

        if (!isset(self::$link)) { // connection not inited or closed
            if ($this->debug_level > 3)
                Logger::debug("SQL: disconnected, retry. " . $this->GetLastError() . $this->extract_caller() );
            $b_ok = false;
        } else { // connection inited, check if is OK to use
            $stat = pg_connection_status(self::$link);
            if ((!isset($stat)) || ($stat !== PGSQL_CONNECTION_OK)) {
                $b_ok = false;
                if ($this->debug_level > 3)
                    Logger::error("SQL: connection not OK, retry. code={$stat}. " . $this->extract_caller() );
            }
        }

        if (!$b_ok) { //reconnect
            self::$link = pg_connect($this->connString);

            if (!isset(self::$link)) {
                Logger::error("SQL: connection retry failed! " . $this->extract_caller() );
                die("SQL: Error in connection.");
            }

            $stat = pg_connection_status(self::$link);
            if ((!isset($stat)) || ($stat !== PGSQL_CONNECTION_OK)) {
                Logger::error("SQL: connection retry FAILED with code={$stat}. " . $this->extract_caller() );
                die("SQL: bad connection.");
            }

            $this->applySearchPath();
        }
    }

    public function __destruct()
    {
        // The connection is shared by every sql wrapper in this PHP process. Closing it from one
        // wrapper's destructor can invalidate a newer wrapper assigned to $GLOBALS['db'].
        // Explicit close() remains available to tests and controlled shutdown paths.
    }

    public function close()
    {
        if (self::$link) {
            if ($this->debug_level > 4)
                Logger::debug("SQL: close connection to ". pg_host(self::$link) ."/". pg_dbname(self::$link) . " " . $this->extract_caller() );
            pg_close(self::$link);
            self::$link = null;
        } else
            if ($this->debug_level > 4)
                Logger::debug("SQL: close connection [null]. " . $this->extract_caller() . $this->GetLastError() );
    }

    public function set_debug_level($debug_lvl = 0) { // set debug level, 0=quiet 5=max verbosity, 0 doesn't suppress errors
        $this->debug_level = $debug_lvl;
    }

    // Close the shared socket in the parent, before fork; child-side close can terminate the parent's session.
    public static function disconnectBeforeFork(): void
    {
        if (self::$link === null) return;
        if (pg_transaction_status(self::$link) !== PGSQL_TRANSACTION_IDLE) {
            throw new LogicException('Cannot fork with an active database transaction');
        }
        if (!pg_close(self::$link)) throw new RuntimeException('Unable to disconnect before fork');
        self::$link = null;
    }

    private function extract_caller() { // format back trace output
        $s_res = "";
        if ($this->debug_level > 0) {
            $arr_bkt = debug_backtrace();
            if (isset($arr_bkt) && (count($arr_bkt)> 0)) {
                $s_res .= " stack: ";
                for ($i = 1; $i <= 4; $i++) {
                    $s_file = $arr_bkt[$i]['file'] ?? '';
                    $s_line = $arr_bkt[$i]['line'] ?? '';
                    $s_func = $arr_bkt[$i]['function'] ?? '';
                    $s_class = $arr_bkt[$i]['class'] ?? '';
                    if (isset($arr_bkt[$i]['args']) && (count($arr_bkt[$i]['args']) > 0)) {
                        $s_arg = json_encode($arr_bkt[$i]['args'],JSON_UNESCAPED_SLASHES); //"array[".count($arr_bkt[$i]['args'][0])."]";
                    } else $s_arg = '';
                    $sx = $s_file.$s_line.$s_func;
                    if (strlen($sx) > 0) {
                        if ($s_line > '')
                            $s_line = "[$s_line]";
                        if ($s_func > '')
                            $s_func = "s_func";
                        if ($s_class > '')
                            $s_class = "$s_class::";
                        if ($s_arg > '')
                            $s_arg = "($s_arg)";
                        else
                            $s_arg = "()";
                        $s_res .= "#{$i} {$s_file} {$s_line} {$s_class}{$s_func}{$s_arg}. ";
                    }
                }
            }
        }
        return $s_res;
    }

    public function GetLastError()
    {
        if (self::$link)
            return pg_last_error(self::$link)." ";
        else
            return "SQL: connection not initialized. ";
    }

    public function insert($table, $data)
    {
        $event = $GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? null;
        if (!in_array($table, ['eventlog', 'synth.eventlog'], true)
            || empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])
            || !is_array($event) || ($event['protocol_version'] ?? 1) !== 2
            || intval($event['context_sequence'] ?? 0) < 1) {
            return $this->insertRow($table, $data);
        }

        return $this->withNativeRequestLock(function ($connection, array $event) use ($data) {
            $session = $event['session_id'];
            $contextResult = @pg_query_params($connection,
                'SELECT playthrough_id FROM synth_meta.context_snapshots
                 WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$3',
                [$session, $event['generation'], $event['context_sequence']]);
            $context = $contextResult ? pg_fetch_assoc($contextResult) : false;
            if (!$context) throw new RuntimeException('Native history context unavailable');
            require_once __DIR__.'/synth_rechat_routes.php';
            $scene=synth_rechat_response_scene_locked($connection,$event);
            $sceneSequence=$scene['scene_context_sequence'] ?? null;
            // Caller-provided extra columns must never override native ownership or allocate an old row ID.
            unset($data['rowid']);
            $data['synth_session_id'] = $session;
            $data['synth_generation'] = $event['generation'];
            $data['synth_context_sequence'] = $event['context_sequence'];
            $data['synth_request_id'] = $event['request_id'];
            $columns = array_keys($data);
            foreach ($columns as $column) {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) {
                    throw new InvalidArgumentException('Invalid native history column');
                }
            }
            $values = implode(',', array_map(static fn($i) => '$'.($i + 1), array_keys($columns)));
            $query = 'WITH inserted AS (INSERT INTO synth.eventlog ('.implode(',', $columns).') VALUES ('.$values.') RETURNING *)
                INSERT INTO synth_meta.history_events
                    (session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload,scene_context_sequence)
                SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,
                    $'.(count($columns) + 1).',rowid,to_jsonb(inserted),$'.(count($columns) + 2).' FROM inserted';
            if (@pg_query_params($connection, $query, [...array_values($data), $context['playthrough_id'],$sceneSequence]) === false) {
                throw new RuntimeException('Unable to persist native history insertion');
            }
            return true;
        });
    }

    /** Capture once before a catalogue transaction; its direct adapter never reconnects. */
    public function getCatalogueSqlConnection(): \PgSql\Connection
    {
        $this->re_connect();
        return self::$link;
    }

    public function isTransactionIdle(): bool
    {
        $this->re_connect();
        return pg_transaction_status(self::$link) === PGSQL_TRANSACTION_IDLE;
    }

    // Run bounded database-only native work under the claim lock; never commit a caller-owned transaction.
    public function withNativeRequestLock(callable $operation, int $statementTimeoutMs = 0)
    {
        $event = $GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? null;
        if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']) || !is_array($event)
            || ($event['protocol_version'] ?? 1) !== 2 || intval($event['context_sequence'] ?? 0) < 1) {
            throw new LogicException('A claimed native context is required');
        }
        require_once __DIR__.'/synth_protocol_state.php';
        $this->re_connect();
        $startedTransaction = pg_transaction_status(self::$link) === PGSQL_TRANSACTION_IDLE;
        if ($startedTransaction && @pg_query(self::$link, 'BEGIN ISOLATION LEVEL READ COMMITTED') === false) {
            throw new RuntimeException('Unable to begin native history transaction');
        }
        $previousStatementTimeout = null;
        try {
            if (pg_transaction_status(self::$link) !== PGSQL_TRANSACTION_INTRANS) {
                throw new RuntimeException('Native history transaction is not usable');
            }
            $isolation = @pg_query(self::$link, 'SHOW transaction_isolation');
            if (!$isolation || pg_fetch_row($isolation)[0] !== 'read committed') {
                throw new RuntimeException('Native history requires read committed isolation');
            }
            if ($statementTimeoutMs > 0) {
                $timeout = @pg_query(self::$link, "SELECT current_setting('statement_timeout') AS previous,
                    setting::bigint AS milliseconds FROM pg_settings WHERE name='statement_timeout'");
                $timeout = $timeout ? pg_fetch_assoc($timeout) : false;
                if (!$timeout) throw new RuntimeException('Unable to read native query deadline');
                $previousStatementTimeout = $timeout['previous'];
                $boundedTimeout = intval($timeout['milliseconds']) > 0
                    ? min($statementTimeoutMs, intval($timeout['milliseconds'])) : $statementTimeoutMs;
                if (@pg_query_params(self::$link,"SELECT set_config('statement_timeout',$1,true)",[(string)$boundedTimeout]) === false) {
                    throw new RuntimeException('Unable to set native query deadline');
                }
            }
            if ($startedTransaction && @pg_query(self::$link, "SET LOCAL lock_timeout='2s'") === false) {
                throw new RuntimeException('Unable to bound native history lock wait');
            }
            if (@pg_query_params(self::$link, 'SELECT pg_advisory_xact_lock(hashtext($1))', [$event['session_id']]) === false) {
                throw new RuntimeException('Unable to lock native history owner');
            }
            synth_protocol_assert_request_active();
            $result = $operation(self::$link, $event);
            if (pg_transaction_status(self::$link) !== PGSQL_TRANSACTION_INTRANS) {
                throw new RuntimeException('Native request operation aborted transaction');
            }
            if ($previousStatementTimeout !== null) {
                if (@pg_query_params(self::$link,"SELECT set_config('statement_timeout',$1,true)",[$previousStatementTimeout]) === false) {
                    throw new RuntimeException('Unable to restore native query deadline');
                }
                $previousStatementTimeout = null;
            }
            if ($startedTransaction) {
                if (@pg_query(self::$link, 'COMMIT') === false) throw new RuntimeException('Unable to commit native history');
                $startedTransaction = false;
            }
            return $result;
        } finally {
            if ($startedTransaction) @pg_query(self::$link, 'ROLLBACK');
            elseif ($previousStatementTimeout !== null && pg_transaction_status(self::$link) === PGSQL_TRANSACTION_INTRANS) {
                if (@pg_query_params(self::$link,"SELECT set_config('statement_timeout',$1,true)",[$previousStatementTimeout]) === false) {
                    throw new RuntimeException('Unable to restore caller query deadline');
                }
            }
        }
    }

    // Legacy/non-event inserts retain their original behavior and do not infer a native owner.
    private function insertRow($table, $data)
    {
        $startTime = microtime(true);
        $this->re_connect();
        $i=0;
        $values = [];

        if (empty($data)) {
            Logger::error("SQL: Insert query refused because no values were provided for {$table}. " . $this->extract_caller());
            return false;
        }

        $columns = implode(', ', array_keys($data));
        foreach (array_keys($data) as $d) {
            $values[]='$'.(++$i);
        }
        $values = implode(', ', $values);

        $query = "INSERT INTO $table ($columns) VALUES ($values)";
        $params = array_values($data);
        $result = @pg_query_params(self::$link, $query, $params);

        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
            $GLOBALS["DB_EXECUTION_TIME"] = 0;
        }
        $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

        if ($this->debug_level > 2) {
            if ($elapsedTime > $this->queryTimeThreshold) {
                Logger::warn("SQL: Insert query execution time exceeded threshold {$elapsedTime} seconds. {$query} ". $this->extract_caller() );
            }
        }
        if (!$result) {
            Logger::error("SQL: Insert query failed {$query} " . $this->GetLastError() . $this->extract_caller() );
            return false;
        }

        return true;
    }

    public function insertReturningId($table, $data, $idColumn = 'id')
    {
        $startTime = microtime(true);
        $this->re_connect();

        if (empty($data)) {
            Logger::error("SQL: Insert returning ID refused because no values were provided for {$table}. " . $this->extract_caller());
            return 0;
        }

        foreach (array_merge([$table, $idColumn], array_keys($data)) as $identifier) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$identifier)) {
                Logger::error("SQL: Invalid identifier for insertReturningId");
                return 0;
            }
        }

        $values = [];
        foreach (array_keys($data) as $index => $column) {
            $values[] = '$' . ($index + 1);
        }

        $columns = implode(', ', array_keys($data));
        $query = "INSERT INTO {$table} ({$columns}) VALUES (" . implode(', ', $values) . ") RETURNING {$idColumn}";
        $result = @pg_query_params(self::$link, $query, array_values($data));

        $elapsedTime = microtime(true) - $startTime;
        if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
            $GLOBALS["DB_EXECUTION_TIME"] = 0;
        }
        $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

        if ($this->debug_level > 2 && $elapsedTime > $this->queryTimeThreshold) {
            Logger::warn("SQL: Insert query execution time exceeded threshold {$elapsedTime} seconds. {$query} " . $this->extract_caller());
        }
        if (!$result) {
            Logger::error("SQL: Insert query failed {$query} " . $this->GetLastError() . $this->extract_caller());
            return 0;
        }

        $row = pg_fetch_assoc($result);
        return isset($row[$idColumn]) ? (int)$row[$idColumn] : 0;
    }

    public function query($query)
    {
        $startTime = microtime(true);
        $this->re_connect();

        if (!function_exists('pg_query')) {
            Logger::error("SQL: pg_query function not available. Ensure PHP pgsql extension is installed. " . $this->extract_caller());
            return false;
        }

        $result = pg_query(self::$link, $query);

        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
            $GLOBALS["DB_EXECUTION_TIME"] = 0;
        }
        $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

        if ($this->debug_level > 2) {
            if ($elapsedTime > $this->queryTimeThreshold) {
                Logger::warn("SQL: query execution time exceeded threshold {$elapsedTime} seconds. {$query} " . $this->extract_caller());
            }
        }
        if ($result === false) {
            Logger::error("SQL: query failed {$query} " . $this->GetLastError() . $this->extract_caller());
        }
        return $result;
    }

    public function delete($table, $where = "FALSE")
    {
        $startTime = microtime(true);
        $this->re_connect();
        $query = "DELETE FROM $table WHERE $where";
        $result = pg_query(self::$link, $query);

        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
            $GLOBALS["DB_EXECUTION_TIME"] = 0;
        }
        $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

        if ($this->debug_level > 2) {
            if ($elapsedTime > $this->queryTimeThreshold) {
                Logger::warn("SQL: delete execution time exceeded threshold {$elapsedTime} seconds. {$query} " . $this->extract_caller());
            }
        }
        if ($result === false) {
            $error = pg_last_error(self::$link);
            error_log("[DB_DELETE] Failed to delete from {$table} WHERE {$where}. Error: {$error}");
        }
        return $result !== false;
    }

    public function truncate($table, $restart = false, $cascade = false)
    {
        if ($table > "") {
            $startTime = microtime(true);
            $this->re_connect();
            $query = "TRUNCATE {$table}";
            if ($restart)
                $query .= " RESTART IDENTITY";
            if ($cascade)
                $query .= " CASCADE";
            pg_query(self::$link, $query);

            $endTime = microtime(true);
            $elapsedTime = $endTime - $startTime;
            if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
                $GLOBALS["DB_EXECUTION_TIME"] = 0;
            }
            $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

            if ($this->debug_level > 2) {
                if ($elapsedTime > $this->queryTimeThreshold) {
                    Logger::warn("SQL: truncate execution time exceeded threshold {$elapsedTime} seconds. {$query} " . $this->extract_caller());
                }
            }
        } else
            Logger::warn("SQL: truncate empty parameter [table] " . $this->extract_caller() );
    }

    public function update($table, $set, $where = "FALSE")
    {
        $startTime = microtime(true);
        $this->re_connect();
        $query = "UPDATE $table SET $set WHERE $where";
        $result = pg_query(self::$link, $query);

        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
            $GLOBALS["DB_EXECUTION_TIME"] = 0;
        }
        $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

        if ($this->debug_level > 2) {
            if ($elapsedTime > $this->queryTimeThreshold) {
                Logger::warn("SQL: update query execution time exceeded threshold: {$elapsedTime} seconds. {$query} ");
            }
        }
        if (!$result) {
            Logger::error("SQL: update query failed {$query} " . $this->GetLastError() . $this->extract_caller() );
        }
    }

    public function execQuery($sqlquery, array $params = [])
    {
        $startTime = microtime(true);
        $this->re_connect();
        $result = $params === [] ? pg_query(self::$link, $sqlquery) : pg_query_params(self::$link, $sqlquery, $params);

        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
            $GLOBALS["DB_EXECUTION_TIME"] = 0;
        }
        $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

        if ($this->debug_level > 2) {
            if ($elapsedTime > $this->queryTimeThreshold) {
                Logger::warn("SQL: query execution time exceeded threshold: {$elapsedTime} seconds. {$sqlquery} ");
            }
        }
        if (!$result) {
            Logger::error("SQL: execute query failed {$sqlquery} " . $this->GetLastError() . $this->extract_caller() );
        }

        return $result;
    }

    // Keep management mutations on one bounded transaction; never reconnect or replay after it starts.
    public function withManagementTransaction(callable $operation)
    {
        $this->re_connect();
        $connection=self::$link;
        if (pg_transaction_status($connection)!==PGSQL_TRANSACTION_IDLE) {
            throw new LogicException('Management requires an idle database connection.');
        }
        if (@pg_query($connection,'BEGIN ISOLATION LEVEL READ COMMITTED')===false) {
            throw new RuntimeException('Unable to begin management transaction.');
        }
        try {
            if (@pg_query($connection,"SET LOCAL statement_timeout='2s'")===false
                || @pg_query($connection,"SET LOCAL lock_timeout='2s'")===false) {
                throw new RuntimeException('Unable to bound management transaction.');
            }
            $result=$operation($connection);
            if (pg_transaction_status($connection)!==PGSQL_TRANSACTION_INTRANS
                || @pg_query($connection,'COMMIT')===false) {
                throw new RuntimeException('Unable to confirm management commit.');
            }
            return $result;
        } finally {
            if (pg_connection_status($connection)===PGSQL_CONNECTION_OK
                && pg_transaction_status($connection)!==PGSQL_TRANSACTION_IDLE) {
                @pg_query($connection,'ROLLBACK');
            }
        }
    }

    public function execQueryVerbose($sqlquery)
    {
        $startTime = microtime(true);
        $this->re_connect();
        $result = pg_query(self::$link, $sqlquery);

        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
            $GLOBALS["DB_EXECUTION_TIME"] = 0;
        }
        $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

        if ($this->debug_level > 2) {
            if ($elapsedTime > $this->queryTimeThreshold) {
                Logger::error("Query execution time exceeded threshold: {$elapsedTime} seconds. {$sqlquery} ");
            }
        }
        if (!$result) {
            $res = $this->GetLastError();
            Logger::error("SQL: execute query failed {$sqlquery} " . $res . $this->extract_caller() );
            return $res;
        }
        return "";
    }

    public function fetchAll($q,$log=false)
    {
        $startTime = microtime(true);
        $this->re_connect();
        $result = pg_query(self::$link, $q);

        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
            $GLOBALS["DB_EXECUTION_TIME"] = 0;
        }
        $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

        if ($this->debug_level > 2) {
            if ($elapsedTime > $this->queryTimeThreshold) {
                Logger::warn("SQL: FetchAll execution time exceeded threshold: {$elapsedTime} seconds. '{$q}' ");
            }
        }

        if ($log) {
            if ($this->debug_level > 0)
                error_log($q);
        }

        if (!isset($result)) {
            Logger::error("SQL: FetchAll failed '{$q}' " . $this->GetLastError() . $this->extract_caller() );
            return [];
        }

        $finalData = array();

        if (!$result) {
            throw new Exception("SQL: FetchAll query failed '{$q}' " . $this->GetLastError() . $this->extract_caller());
        }

        while ($row = pg_fetch_assoc($result)) {
            $finalData[] = $row;
        }

        return $finalData;
    }

    public function fetchOne($q)
    {
        $startTime = microtime(true);
        $this->re_connect();
        $result = pg_query(self::$link, $q);
        // error_log($q);

        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
            $GLOBALS["DB_EXECUTION_TIME"] = 0;
        }
        $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

        if ($this->debug_level > 2) {
            if ($elapsedTime > $this->queryTimeThreshold) {
                Logger::error("SQL: FetchOne query execution time exceeded threshold: {$elapsedTime} seconds. {$q} ");
            }
        }
        if (!$result) {
            Logger::error("SQL: FetchOne failed {$q} " . $this->GetLastError() . $this->extract_caller() );
            return [];
        }

        $finalData = array();
        while ($row = pg_fetch_assoc($result)) {
            $finalData = $row;
            break;
        }

        return $finalData;
    }

    public function fetchArray($res)
    {
        $this->re_connect();
        return pg_fetch_array($res);
    }

    public function escape($string)
    {
        if ($string !== null) {
            $this->re_connect();
            return pg_escape_string(self::$link, strval($string));
        } else
            return "";
    }

    public function escapeLiteral($string)
    {
        if ($string !== null) {
            $this->re_connect();
            return pg_escape_literal(self::$link, strval($string));
        } else
            return "";
    }

    public function updateRow($table, $data, $where)
    {
        $startTime = microtime(true);
        $setClauses = [];
        $params = [];
        $i = 0;

        if (empty($data)) {
            Logger::error("SQL: updateRow refused because no values were provided for {$table}. " . $this->extract_caller());
            return false;
        }

        foreach ($data as $column => $value) {
            $setClauses[] = "$column = $" . (++$i);
            $params[] = $value;
        }

        $set = implode(', ', $setClauses);

        $query = "UPDATE $table SET $set WHERE $where";
        $this->re_connect();
        $result = @pg_query_params(self::$link, $query, $params);

        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
            $GLOBALS["DB_EXECUTION_TIME"] = 0;
        }
        $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

        if ($this->debug_level > 2) {
            if ($elapsedTime > $this->queryTimeThreshold) {
                Logger::warn("SQL: updateRow execution time exceeded threshold: {$elapsedTime} seconds. {$query} " . $this->extract_caller());
            }
        }
        if (!$result) {
            Logger::error("SQL: updateRow failed {$query} " .$this->GetLastError() . $this->extract_caller() );
            return false;
        }

        return true;
    }

    public function upsertRow($table, $data, $where) {
        $startTime = microtime(true);
        // Check if the row exists
        $this->re_connect();
        $checkQuery = "SELECT 1 FROM $table WHERE $where LIMIT 1";
        $checkResult = pg_query(self::$link, $checkQuery);

        if (!$checkResult) {
            Logger::error("SQL: upsertRow failed {$checkQuery} " . $this->GetLastError() . $this->extract_caller() );
            return false;
        }

        if (pg_num_rows($checkResult) > 0) {
            // Row exists, perform an update
            $setClauses = [];
            $params = [];
            $i = 0;

            foreach ($data as $column => $value) {
                $setClauses[] = "$column = $" . (++$i);
                $params[] = $value;
            }

            $set = implode(', ', $setClauses);
            $query = "UPDATE $table SET $set WHERE $where";
        } else {
            // Row does not exist, perform an insert
            $columns = array_keys($data);
            $placeholders = [];
            $params = [];
            $i = 0;

            foreach ($data as $value) {
                $placeholders[] = '$' . (++$i);
                $params[] = $value;
            }

            $columnList = implode(', ', $columns);
            $placeholderList = implode(', ', $placeholders);

            $query = "INSERT INTO $table ($columnList) VALUES ($placeholderList)";
        }

        // Execute the query
        $result = pg_query_params(self::$link, $query, $params);

        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
            $GLOBALS["DB_EXECUTION_TIME"] = 0;
        }
        $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

        if ($this->debug_level > 2) {
            if ($elapsedTime > $this->queryTimeThreshold) {
                Logger::warn("SQL: upsertRow execution time exceeded threshold: {$elapsedTime} seconds. {$query} " . $this->extract_caller());
            }
        }
        if (!$result) {
            Logger::error("SQL: upsertRow failed {$query} " . $this->GetLastError() . $this->extract_caller() );
            return false;
        }

        return true;
    }

    public function upsertRowTrx($table, $data, $whereCondition) {
        $startTime = microtime(true);
        // Start a transaction
        $this->re_connect();
        pg_query(self::$link, "BEGIN");

        try {
            // Extract WHERE condition keys and values
            $whereClauses = [];
            $whereParams = [];
            $i = 0;

            foreach ($whereCondition as $column => $value) {
                $whereClauses[] = "$column = $" . (++$i); // Explicitly cast as TEXT
                $whereParams[] = (string) $value; // Convert value to string
            }


            $whereSql = implode(" AND ", $whereClauses);

            // Check if the row exists and lock it
            $checkQuery = "SELECT 1 FROM $table WHERE $whereSql FOR UPDATE";
            $checkResult = pg_query_params(self::$link, $checkQuery, $whereParams);

            if (!$checkResult) {
                throw new Exception("SQL: upsertRowTrx failed {$checkQuery} " . $this->GetLastError() . $this->extract_caller() );
            }

            if (pg_num_rows($checkResult) > 0) {
                // Row exists, perform an UPDATE (excluding WHERE fields)
                $setClauses = [];
                $params = [];
                foreach ($data as $column => $value) {
                    if (!array_key_exists($column, $whereCondition)) { // Exclude WHERE fields
                        $setClauses[] = "$column = $" . (++$i); // Explicitly cast as TEXT
                        $params[] = (string) $value; // Convert value to string
                    }
                }

                if (empty($setClauses)) {
                    throw new Exception("SQL: upsertRowTrx failed, no update-able fields provided. " . $this->extract_caller() );
                }

                $setSql = implode(', ', $setClauses);
                $query = "UPDATE $table SET $setSql WHERE $whereSql";

                // Merge params: first the update values, then the WHERE values
                $params = array_merge($params, $whereParams);
            } else {
                // Row does not exist, perform an INSERT
                $i = 0;
                $columns = array_keys($data);
                $placeholders = [];
                $params = [];

                foreach ($data as $index => $value) {
                    $placeholders[] = '$' . (++$i) ; // Explicitly cast as TEXT
                    $params[] = (string) $value; // Convert value to string
                }

                $columnList = implode(', ', $columns);
                $placeholderList = implode(', ', $placeholders);
                $query = "INSERT INTO $table ($columnList) VALUES ($placeholderList)";
            }

            // error_log($query . " " . print_r($params, true));

            // Execute the query
            $result = pg_query_params(self::$link, $query, $params);
            if (!$result) {
                throw new Exception("SQL: upsertRowTrx failed {$query} " . $this->GetLastError() . $this->extract_caller() );
            }

            // Commit transaction
            pg_query(self::$link, "COMMIT");

            $endTime = microtime(true);
            $elapsedTime = $endTime - $startTime;
            if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
                $GLOBALS["DB_EXECUTION_TIME"] = 0;
            }
            $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

            if ($this->debug_level > 2) {
                if ($elapsedTime > $this->queryTimeThreshold) {
                    Logger::warn("SQL: upsertRowTrx execution time exceeded threshold: {$elapsedTime} seconds. " . $this->extract_caller());
                }
            }

            return true;
        } catch (Exception $e) {
            // Rollback on error
            pg_query(self::$link, "ROLLBACK");

            $endTime = microtime(true);
            $elapsedTime = $endTime - $startTime;
            if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
                $GLOBALS["DB_EXECUTION_TIME"] = 0;
            }
            $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

            Logger::error("SQL: upsertRowTrx failed {$query} " . $e->getMessage() . $this->extract_caller() );
            return false;
        }
    }

    // an upsert that completes in one query. Good for simple cases when a constraint can be used
    public function upsertRowOnConflict($tableName, $data, $conflictTarget) {
        $startTime = microtime(true);
        // Prepare the column names for the INSERT statement.

        $columns = implode(', ', array_keys($data));

        $this->re_connect();

        // Take care of escaping here instead of requiring it before every upsert call
        $values = array_map(function($value) {
            return pg_escape_literal(self::$link, $value);
        }, array_values($data));
        $valuesString = implode(', ', $values);

        // EXCLUDED refers to the row that was attempted to be inserted.
        // This loop constructs "column = EXCLUDED.column" for each column in the data.
        $updateStatements = [];
        foreach ($data as $column => $value) {
            $updateStatements[] = "$column = EXCLUDED.$column";
        }
        $updateString = implode(', ', $updateStatements);

        // ON CONFLICT ... DO UPDATE is effectively an upsert
        // If the constraint in $conflictTarget is violated during the insert, an update will be done instead
        $sqlquery = "INSERT INTO $tableName ($columns) VALUES ($valuesString) " .
                    "ON CONFLICT ($conflictTarget) DO UPDATE SET $updateString;";

        $result = pg_query(self::$link, $sqlquery);

        $endTime = microtime(true);
        $elapsedTime = $endTime - $startTime;
        if (!isset($GLOBALS["DB_EXECUTION_TIME"])) {
            $GLOBALS["DB_EXECUTION_TIME"] = 0;
        }
        $GLOBALS["DB_EXECUTION_TIME"] += $elapsedTime;

        if ($this->debug_level > 2) {
            if ($elapsedTime > $this->queryTimeThreshold) {
                Logger::warn("SQL: upsertRowOnConflict execution time exceeded threshold: {$elapsedTime} seconds. {$sqlquery} " . $this->extract_caller());
            }
        }
        if (!$result) {
            Logger::error("SQL: upsertRowOnConflict failed {$sqlquery} " . $this->GetLastError() . $this->extract_caller() );
            return false; // Indicate failure
        }

        return true; // Indicate success
    }

    /*
     * Class Method Summary:
     * Note. Singleton object is available on $GLOBALS["db].
     * __construct(): Establishes a database connection using pg_connect.
     * __destruct(): Closes the database connection when the object is destroyed.
     * close(): Closes the database connection.
     * re_connect(): check connection availability and reconnect if connection expired
     * GetLastError(): Returns the last error message from the database.
     * set_debug_level($debug_lvl = 0): set debug level, 0=quiet 5=max verbosity, doesn't suppress errors
     * extract_caller() // format back trace output
     * insert($table, $data): Inserts data into a specified table.
     * query($query): Executes a raw SQL query.
     * delete($table, $where = "FALSE"): Deletes rows from a table based on a WHERE clause.
     * truncate($table, $restart = false, $cascade = false): Truncates a table, optionally restarting identity and cascading.
     * update($table, $set, $where = "FALSE"): Updates data in a table based on a WHERE clause.
     * execQuery($sqlquery): Executes a SQL query and returns the result.
     * execQueryVerbose($sqlquery): Executes a SQL query and returns either an empty string on success or the error message on failure.
     * fetchAll($q): Executes a query and returns all rows as an associative array.
     * fetchOne($q): Executes a query and returns the first row as an associative array.
     * fetchArray($res): Fetches a row as an array.
     * escape($string): Escapes a string for use in a SQL query.
     * updateRow($table, $data, $where): Updates a row in a table using parameterized queries.
     * upsertRow($table, $data, $where): Inserts or updates a row in a table based on whether it exists.
     * upsertRowTrx($table, $data, $whereCondition): Performs an upsert operation within a transaction for data integrity.
     * upsertRowOnConflict($tableName, $data, $conflictTarget): Performs an upsert based on an existing index constraint.
     */

}
?>
