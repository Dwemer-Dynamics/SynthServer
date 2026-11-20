<?php
require_once __DIR__ . "/autoload.php";
require_once __DIR__ . '/../lib/classes/Logger.php';
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

    /**
     * @param ?array $settings explicit SYNTH_DB_* configuration in place of runtime globals (environment still wins).
     * Sensitive: extract_caller() logs call arguments, and this one holds the database password.
     */
    public function __construct(#[\SensitiveParameter] private ?array $settings = null)
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
            $this->connectionFailed("SQL: Error in connection.");
        }

        $stat = pg_connection_status(self::$link);
        if ((!isset($stat)) || ($stat !== PGSQL_CONNECTION_OK)) {
            Logger::error("SQL: connection init FAILED [$stat] " . $this->extract_caller() );
            $this->connectionFailed("SQL: Error in connection.");
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
            $this->connectionFailed("SQL: Error configuring connection.");
        }
    }

    // Legacy (runtime-global) callers keep the historical exit, on the first connect and on a reconnect. An explicit
    // configuration (the v2 composition root, synthRuntimeConnectDatabase) gets an exception, so its caller answers
    // with the sanitized failure response instead of plaintext.
    private function connectionFailed(string $message): never
    {
        if ($this->settings !== null) throw new \RuntimeException($message);
        die($message);
    }

    private function readConnectionSetting(string $envName, string $globalName, string $default): string
    {
        $envValue = getenv($envName);
        if ($envValue !== false && trim(strval($envValue)) !== '') {
            return trim(strval($envValue));
        }

        $settings = $this->settings ?? $GLOBALS;
        if (isset($settings[$globalName]) && trim(strval($settings[$globalName])) !== '') {
            return trim(strval($settings[$globalName]));
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
        // No configured SYNTH_DB_PORT leaves the port to libpq (PGPORT, then 5432).
        $port = $this->readConnectionSetting('SYNTH_DB_PORT', 'SYNTH_DB_PORT', '');

        $parts = ['host=' . $this->escapeConnectionValue($host)];
        if ($port !== '') {
            $parts[] = 'port=' . $this->escapeConnectionValue($port);
        }
        return implode(' ', array_merge($parts, [
            'dbname=' . $this->escapeConnectionValue($dbname),
            'user=' . $this->escapeConnectionValue($user),
            'password=' . $this->escapeConnectionValue($password),
            'connect_timeout=' . $this->escapeConnectionValue($connectTimeout),
        ]));
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
            self::$link = @pg_connect($this->connString);

            if (!self::$link) {
                Logger::error("SQL: connection retry failed! " . $this->extract_caller() );
                $this->connectionFailed("SQL: Error in connection.");
            }

            $stat = pg_connection_status(self::$link);
            if ((!isset($stat)) || ($stat !== PGSQL_CONNECTION_OK)) {
                Logger::error("SQL: connection retry FAILED with code={$stat}. " . $this->extract_caller() );
                $this->connectionFailed("SQL: bad connection.");
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

    // Caller frames only: arguments are never captured, because they can carry bound credentials or row values.
    private function extract_caller() { // format back trace output
        $s_res = "";
        if ($this->debug_level > 0) {
            $arr_bkt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            if (count($arr_bkt) > 0) {
                $s_res .= " stack: ";
                for ($i = 1; $i <= 4; $i++) {
                    $s_file = $arr_bkt[$i]['file'] ?? '';
                    $s_line = strval($arr_bkt[$i]['line'] ?? '');
                    $s_func = $arr_bkt[$i]['function'] ?? '';
                    $s_class = $arr_bkt[$i]['class'] ?? '';
                    if (strlen($s_file.$s_line.$s_func) > 0) {
                        if ($s_line !== '')
                            $s_line = "[$s_line]";
                        if ($s_class !== '')
                            $s_class = "$s_class::";
                        $s_res .= "#{$i} {$s_file} {$s_line} {$s_class}{$s_func}(). ";
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
        $event = \Synth\Bootstrap::requestEvent()['json'] ?? null;
        if (!in_array($table, ['core_context_event', 'public.core_context_event'], true)
            || empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])
            || !is_array($event) || ($event['protocol_version'] ?? 1) !== 2
            || intval($event['context_sequence'] ?? 0) < 1) {
            return $this->insertRow($table, $data);
        }

        return $this->withNativeRequestLock(fn($connection, array $event) => $this->insertNativeHistory($connection, $event, $data));
    }

    // The same native history insertion for an explicit claimed owner; no runtime global supplies authority.
    public function insertClaimedHistory(SYNTHClaimedRequest $owner, array $data)
    {
        return $this->withClaimedRequestLock($owner, fn($connection, array $event) => $this->insertNativeHistory($connection, $event, $data));
    }

    // history_events is unique only by eventlog row, so a stage keyed by a deterministic utterance id is checked under
    // the same claimed-owner lock. Every row of this session and request carrying the utterance id is read, from either
    // table and in any generation or context. None: the row is inserted once. Exactly one: it is reused only when its
    // eventlog row and history_events row both carry this owner tuple and the snapshot's playthrough, its immutable
    // stage fields equal the requested ones and its event_payload agrees with the eventlog row. Processing time
    // (localts) and the SAPI tag are not compared, so a re-entered stage stays idempotent. Anything else, including a
    // second row, is refused. Returns false when the existing row was reused.
    // A stage is only a non-Whisper input root: insertNativeHistory gives it no scene and no hearer projection, so a
    // reused row must carry the same visibility (scene_context_sequence NULL, no synth_dialogue_hearer_ids).
    public function insertNativeHistoryOnce(array $data): bool
    {
        $utterance = $data['utterance_id'] ?? null;
        if (!is_string($utterance) || $utterance === '' || !is_string($data['type'] ?? null) || !is_string($data['data'] ?? null)) {
            throw new InvalidArgumentException('Native history stage requires an utterance id, type and text');
        }
        return $this->withNativeRequestLock(function ($connection, array $event) use ($data, $utterance): bool {
            if (!in_array($event['type'] ?? '', ['input_text', 'input_audio'], true)
                || ($event['payload']['control']['mode'] ?? '') === 'WHISPER') {
                throw new LogicException('native_history_stage_owner_invalid');
            }
            $columns = 'e.rowid,e.type,e.data,e.people,e.location,e.party,e.ts,e.gamets,e.utterance_id,e.delivery_state,
                e.synth_session_id,e.synth_request_id,e.synth_generation,e.synth_context_sequence,h.id AS history_id,
                h.session_id,h.request_id,h.generation,h.context_sequence,h.playthrough_id,h.scene_context_sequence,
                h.event_payload::text AS event_payload';
            $result = @pg_query_params($connection,
                "SELECT {$columns} FROM public.core_context_event e LEFT JOIN public.core_history_event h ON h.eventlog_rowid=e.rowid
                 WHERE e.synth_session_id=$1 AND e.synth_request_id=$2 AND e.utterance_id=$3
                 UNION ALL
                 SELECT {$columns} FROM public.core_history_event h LEFT JOIN public.core_context_event e ON e.rowid=h.eventlog_rowid
                 WHERE h.session_id=$1 AND h.request_id=$2 AND h.event_payload->>'utterance_id'=$3
                    AND (e.rowid IS NULL OR e.synth_session_id IS DISTINCT FROM $1 OR e.synth_request_id IS DISTINCT FROM $2
                        OR e.utterance_id IS DISTINCT FROM $3)",
                [$event['session_id'], $event['request_id'], $utterance]);
            if (!$result) throw new RuntimeException('Unable to read native history stage');
            $rows = pg_fetch_all($result) ?: [];
            if ($rows === []) return $this->insertNativeHistory($connection, $event, $data);
            $snapshot = @pg_query_params($connection, 'SELECT playthrough_id FROM public.core_runtime_context
                WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$3',
                [$event['session_id'], $event['generation'], $event['context_sequence']]);
            if (!$snapshot) throw new RuntimeException('Unable to read native history stage');
            $playthrough = ($snapshotRow = pg_fetch_row($snapshot)) ? $snapshotRow[0] : null;
            $row = $rows[0];
            $value = static fn($field): ?string => $field === null ? null : strval($field);
            $payload = is_string($row['event_payload']) ? json_decode($row['event_payload'], true, 64, JSON_BIGINT_AS_STRING) : null;
            $owner = ['session_id' => $event['session_id'], 'request_id' => $event['request_id'],
                'generation' => $event['generation'], 'context_sequence' => $event['context_sequence']];
            $same = count($rows) === 1 && $row['rowid'] !== null && $row['history_id'] !== null && is_array($payload)
                && is_string($playthrough) && $row['playthrough_id'] === $playthrough
                && $row['scene_context_sequence'] === null && !array_key_exists('synth_dialogue_hearer_ids', $payload)
                && $value($payload['rowid'] ?? null) === $row['rowid'];
            foreach ($owner as $field => $expected) {
                $same = $same && $row[$field] === strval($expected) && $row['synth_'.$field] === strval($expected)
                    && $value($payload['synth_'.$field] ?? null) === strval($expected);
            }
            foreach (['type', 'data', 'people', 'location', 'party', 'ts', 'gamets', 'utterance_id', 'delivery_state'] as $field) {
                $same = $same && $row[$field] === $value($data[$field] ?? null) && $value($payload[$field] ?? null) === $row[$field];
            }
            if (!$same) throw new LogicException('native_history_stage_conflict');
            return false;
        }, 2000);
    }

    // An ordinary delivery receipt's heard row, inside the receipt's own claimed transaction. The origin is the line's
    // proven admitted event, so the row gets exactly the scene, Close and Whisper hearers its emission-time row had.
    // $privateHearers are canonical identity IDs the caller proved from an inherited Whisper root (player, listener).
    public function insertReceiptHistory($connection, array $origin, array $data, ?array $privateHearers = null, bool $close = false, ?int $noSceneSpeaker = null): void
    {
        if (pg_transaction_status($connection) !== PGSQL_TRANSACTION_INTRANS) throw new LogicException('Receipt history requires the receipt transaction');
        if ($privateHearers !== null && ($privateHearers === [] || array_filter($privateHearers,
            static fn($id): bool => !is_int($id) || $id < 1) !== [])) throw new LogicException('Receipt hearers invalid');
        // The receipt row join already proved the origin unrevoked; its own failed execution keeps its frozen scene.
        $this->insertNativeHistory($connection, $origin, $data, $privateHearers, true, $close, $noSceneSpeaker);
    }

    private function insertNativeHistory($connection, array $event, array $data, ?array $privateHearers = null, bool $ownFailed = false, bool $close = false, ?int $noSceneSpeaker = null): bool
    {
        $session = $event['session_id'];
        $contextResult = @pg_query_params($connection,
            'SELECT playthrough_id,player_identity_id,target_identity_id,context_payload FROM public.core_runtime_context
             WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$3',
            [$session, $event['generation'], $event['context_sequence']]);
        $context = $contextResult ? pg_fetch_assoc($contextResult) : false;
        if (!$context) throw new RuntimeException('Native history context unavailable');
        require_once __DIR__.'/synth_rechat_routes.php';
        $scene=synth_rechat_response_scene_locked($connection,$event,$ownFailed);
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
        $hearers = isset($scene['close_hearers'])
            ? json_encode($this->closeHearerIdentityIds($connection, $event, intval($sceneSequence), $scene['close_hearers']),
                JSON_THROW_ON_ERROR) : null;
        if ($noSceneSpeaker !== null) {
            $hearers = json_encode([intval($context['player_identity_id']), $noSceneSpeaker], JSON_THROW_ON_ERROR);
        } elseif ($close && $hearers === null) {
            // A direct Close reply, or an action rooted in it, may use only the original measured Close group.
            $capture=json_decode($context['context_payload'],true,flags:JSON_THROW_ON_ERROR);
            $projected=synth_rechat_close_audience($capture,$capture);
            $keys=[$capture['target'] ?? []];
            if ($projected!==null) $keys=array_merge($keys,$projected['audience']);
            $keys=array_filter(array_map('synth_rechat_actor_key',$keys));
            $hearers=json_encode($this->closeHearerIdentityIds($connection,$event,$event['context_sequence'],$keys),JSON_THROW_ON_ERROR);
        }
        if (in_array($event['type'] ?? '', ['input_text','input_audio'], true)
            && ($event['payload']['control']['mode'] ?? '') === 'WHISPER')
            $hearers = json_encode(array_values(array_filter(array_map('intval',
                [$context['player_identity_id'], $context['target_identity_id']]))), JSON_THROW_ON_ERROR);
        if ($privateHearers !== null) $hearers = json_encode(array_values(array_unique($privateHearers)), JSON_THROW_ON_ERROR);
        $values = implode(',', array_map(static fn($i) => '$'.($i + 1), array_keys($columns)));
        // A projected Close row records exactly which snapshot identities could hear it; others keep raw scene reconstruction.
        $query = 'WITH inserted AS (INSERT INTO public.core_context_event ('.implode(',', $columns).') VALUES ('.$values.') RETURNING *)
            INSERT INTO public.core_history_event
                (session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload,scene_context_sequence)
            SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,
                $'.(count($columns) + 1).',rowid,
                CASE WHEN $'.(count($columns) + 3).'::jsonb IS NULL THEN to_jsonb(inserted)
                    ELSE to_jsonb(inserted)||jsonb_build_object(\'synth_dialogue_hearer_ids\',$'.(count($columns) + 3).'::jsonb) END,
                $'.(count($columns) + 2).' FROM inserted';
        if (@pg_query_params($connection, $query, [...array_values($data), $context['playthrough_id'],$sceneSequence,$hearers]) === false) {
            throw new RuntimeException('Unable to persist native history insertion');
        }
        return true;
    }

    // Align projected canonical keys with the immutable scene snapshot's resolved identity IDs; never resolve by name.
    private function closeHearerIdentityIds($connection, array $event, int $sceneSequence, array $keys): array
    {
        $result = @pg_query_params($connection,
            'SELECT player_identity_id,target_identity_id,audience_identity_ids,context_payload FROM public.core_runtime_context
             WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$3',
            [$event['session_id'], $event['generation'], $sceneSequence]);
        $row = $result ? pg_fetch_assoc($result) : false;
        if (!$row) throw new RuntimeException('Close hearer snapshot unavailable');
        $payload = json_decode($row['context_payload'], true, flags: JSON_THROW_ON_ERROR);
        $audienceIds = json_decode($row['audience_identity_ids'] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $ids = [intval($row['player_identity_id'])];
        $actors = [[$payload['target'] ?? null, $row['target_identity_id']]];
        foreach ($payload['audience'] ?? [] as $i => $actor) $actors[] = [$actor, $audienceIds[$i] ?? null];
        foreach ($actors as [$actor, $id]) {
            $key = is_array($actor) ? synth_rechat_actor_key($actor) : null;
            if ($key !== null && in_array($key, $keys, true) && is_numeric($id) && intval($id) > 0) $ids[] = intval($id);
        }
        return array_values(array_unique($ids));
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
    public function withNativeRequestLock(callable $operation, int $statementTimeoutMs = 0, bool $contextPublication = false)
    {
        $event = \Synth\Bootstrap::requestEvent()['json'] ?? null;
        $publication = $contextPublication && is_array($event) && ($event['type'] ?? '') === 'context'
            && ($event['context_sequence'] ?? null) === 0
            && is_int($event['payload']['snapshot_sequence'] ?? null)
            && $event['payload']['snapshot_sequence'] >= 1 && $event['payload']['snapshot_sequence'] <= 9007199254740991;
        if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']) || !is_array($event)
            || ($event['protocol_version'] ?? 1) !== 2 || ($contextPublication && !$publication)
            || (!$publication && intval($event['context_sequence'] ?? 0) < 1)) {
            throw new LogicException('A claimed native context is required');
        }
        return $this->lockedNativeRequest($event, $operation, $statementTimeoutMs, $publication, true);
    }

    // The explicit form of the same lock: the owner was produced by the canonical claim, and its durable
    // execution/session/turn row is rechecked under the session advisory lock before the operation runs.
    public function withClaimedRequestLock(SYNTHClaimedRequest $owner, callable $operation, int $statementTimeoutMs = 0)
    {
        return $this->lockedNativeRequest($owner->event, $operation, $statementTimeoutMs, false, false);
    }

    // One implementation of the claim lock and transaction. Legacy callers also recheck their worker leases.
    private function lockedNativeRequest(array $event, callable $operation, int $statementTimeoutMs, bool $publication, bool $legacy)
    {
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
                throw new SYNTHNativeRequestLockUnavailable('Unable to lock native history owner');
            }
            if ($legacy) synth_protocol_assert_request_active();
            else synth_protocol_assert_owner_active($this, $event);
            if ($publication) {
                // A context publishes its own positive capture, while its wire binding remains zero.
                // Recheck the exact admitted bytes and captured payload under the existing session lock.
                $canonical = synth_protocol_canonical_json($event);
                $proof = @pg_query_params(self::$link, "SELECT 1 FROM public.core_protocol_event p
                    JOIN public.core_request_execution e USING(session_id,request_id)
                    JOIN public.core_runtime_session r ON r.session_id=p.session_id
                    JOIN public.core_runtime_context c ON c.session_id=p.session_id AND c.generation=p.generation
                        AND c.snapshot_sequence=$6 AND c.context_payload=p.canonical_event->'payload'
                    WHERE p.session_id=$1 AND p.request_id=$2 AND p.generation=$3 AND p.turn_id=$7
                        AND p.event_type='context' AND e.event_type='context' AND e.generation=p.generation AND e.turn_id=p.turn_id
                        AND p.canonical_event=$4::jsonb AND p.event_sha256=$5 AND e.request_sha256=p.event_sha256
                        AND e.state IN ('claimed','streaming','complete') AND r.state='active' AND r.active_generation=p.generation
                        AND c.playthrough_id=r.playthrough_id AND c.playthrough_id=p.canonical_event#>>'{payload,playthrough_id}'
                        AND c.history_event_id IS NOT NULL
                        AND NOT EXISTS(SELECT 1 FROM public.core_runtime_context newer WHERE newer.session_id=c.session_id
                            AND newer.generation=c.generation AND newer.snapshot_sequence>c.snapshot_sequence)",
                    [$event['session_id'], $event['request_id'], $event['generation'], $canonical,
                     hash('sha256',$canonical), $event['payload']['snapshot_sequence'], $event['turn_id']]);
                if (!$proof || !pg_fetch_assoc($proof)) throw new DomainException('native_context_publication_owner_mismatch');
            }
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
            \Synth\Lib\Logger::log(\Synth\Lib\Logger::ERROR, 'DB_DELETE', "Failed to delete from {$table} WHERE {$where}. Error: {$error}");
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
                \Synth\Lib\Logger::log(\Synth\Lib\Logger::INFO, 'postgresql.class', $q);
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
