<?php

namespace Synth\Core\Doctrine;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\PgSQL\ConvertParameters;
use Doctrine\DBAL\Driver\PgSQL\Result;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\SQL\Parser;

// Doctrine DBAL over the shared legacy PostgreSQL session. DBAL's own pgsql driver opens a second backend, closes
// its handle on destruction and leaves named prepared statements; this adapter does none of that. Every statement
// runs through the legacy sql wrapper's execQuery() (same reconnect, search_path, transaction, DB_EXECUTION_TIME,
// slow-query and failure logging), so a read sees what a legacy execQuery on the same request would see,
// including its uncommitted writes.
final class SharedSessionDriver extends AbstractPostgreSQLDriver
{
    /** @param object $db the legacy sql wrapper: execQuery() and getCatalogueSqlConnection() */
    public function __construct(private readonly object $db) {}

    public function connect(array $params): DriverConnection
    {
        return new SharedSessionConnection($this->db);
    }
}

// Read-only by contract: it never begins, commits or rolls back, so it cannot end a legacy-owned transaction.
final class SharedSessionConnection implements DriverConnection
{
    // Doctrine\DBAL\SQL\Parser is marked internal; composer.json pins doctrine/dbal to one minor release for it.
    private readonly Parser $parser;

    public function __construct(private readonly object $db)
    {
        $this->parser = new Parser(false);
    }

    public function prepare(string $sql): DriverStatement
    {
        $visitor = new ConvertParameters();
        $this->parser->parse($sql, $visitor);
        return new SharedSessionStatement($this->db, $visitor->getSQL(), $visitor->getParameterMap());
    }

    public function query(string $sql): Result
    {
        return SharedSessionException::result($this->db, $sql, []);
    }

    public function quote(string $value): string
    {
        $quoted = pg_escape_literal($this->db->getCatalogueSqlConnection(), $value);
        if ($quoted === false) throw new SharedSessionException('Unable to quote a value on the shared session');
        return $quoted;
    }

    public function exec(string $sql): int
    {
        return $this->query($sql)->rowCount();
    }

    public function lastInsertId(): int|string
    {
        throw new SharedSessionException('The shared settings session is read-only');
    }

    public function beginTransaction(): void
    {
        throw new SharedSessionException('Transactions on the shared session belong to the legacy sql owner');
    }

    public function commit(): void
    {
        throw new SharedSessionException('Transactions on the shared session belong to the legacy sql owner');
    }

    public function rollBack(): void
    {
        throw new SharedSessionException('Transactions on the shared session belong to the legacy sql owner');
    }

    public function getServerVersion(): string
    {
        return strval(pg_version($this->db->getCatalogueSqlConnection())['server'] ?? '');
    }

    public function getNativeConnection(): \PgSql\Connection
    {
        return $this->db->getCatalogueSqlConnection();
    }
}

// Binds Doctrine's positional parameters to execQuery's $n placeholders; execQuery runs it as an unnamed
// pg_query_params, so nothing is left allocated on the shared session.
final class SharedSessionStatement implements DriverStatement
{
    private array $parameters = [];

    /** @param array<array-key, int> $parameterMap */
    public function __construct(private readonly object $db, private readonly string $sql,
        private readonly array $parameterMap) {}

    public function bindValue(int|string $param, mixed $value, ParameterType $type = ParameterType::STRING): void
    {
        if (!isset($this->parameterMap[$param])) throw new SharedSessionException('Unknown parameter '.strval($param));
        if ($type === ParameterType::BINARY || $type === ParameterType::LARGE_OBJECT) {
            throw new SharedSessionException('Binary parameters are not supported on the shared settings session');
        }
        $this->parameters[$this->parameterMap[$param]] = $value === null ? null
            : ($type === ParameterType::BOOLEAN ? ((bool) $value ? 't' : 'f') : $value);
    }

    public function execute(): Result
    {
        if (count($this->parameters) !== count(array_unique($this->parameterMap))) {
            throw new SharedSessionException('Every statement parameter must be bound');
        }
        ksort($this->parameters);
        return SharedSessionException::result($this->db, $this->sql, array_values($this->parameters));
    }
}

final class SharedSessionException extends AbstractException
{
    // execQuery() has already timed and logged the statement, failures included; this only reports it to DBAL.
    public static function result(object $db, string $sql, array $parameters): Result
    {
        $result = $db->execQuery($sql, $parameters);
        if ($result instanceof \PgSql\Result) return new Result($result);
        // GetLastError() reads the failed link without reconnecting, so the message is the statement's own.
        throw new self(trim(strval($db->GetLastError())) ?: 'The shared session statement failed');
    }
}
