<?php

declare(strict_types=1);

namespace Db;

// PDO statement class for the app's SQLite connection.
//
// Problem: with WAL, a statement that has returned a row but not been
// stepped to the end (the usual `$st->execute(); $row = $st->fetch();`
// pattern) keeps a read snapshot open for as long as the statement object
// lives - in the endpoint scripts, until the request ends. If any other
// process writes in the meantime, this connection's next write can never
// succeed: SQLite reports SQLITE_BUSY_SNAPSHOT ("database is locked")
// immediately, without honouring the busy timeout. The chat endpoint hit
// this on every message while the product sync was writing.
//
// Fix: when a write fails that way, close the cursors of this
// connection's other open statements (their callers have already taken
// the rows they fetched) so the stale snapshot is released, then retry
// with a short backoff. Reads and failures inside an explicit transaction
// are rethrown unchanged.
class Statement extends \PDOStatement
{
    /** @var \WeakMap<Statement, true>|null */
    private static ?\WeakMap $live = null;

    protected function __construct()
    {
        self::$live ??= new \WeakMap();
        self::$live[$this] = true;
    }

    public function execute(?array $params = null): bool
    {
        $attempt = 0;
        while (true) {
            try {
                return parent::execute($params);
            } catch (\PDOException $e) {
                if (!self::isBusy($e) || self::isRead($this->queryString) || $attempt >= 8 || self::inTransaction()) {
                    throw $e;
                }
                // Reset this statement too: after a failed step SQLite keeps
                // its implicit transaction (and the stale snapshot) until
                // the statement itself is reset.
                try { $this->closeCursor(); } catch (\Throwable) {}
                self::releaseOthers($this);
                usleep(min(1_000_000, 50_000 * (2 ** $attempt)));
                $attempt++;
            }
        }
    }

    public static function isBusy(\PDOException $e): bool
    {
        $code = (int)($e->errorInfo[1] ?? 0);
        return $code === 5 || $code === 6 || str_contains($e->getMessage(), 'database is locked');
    }

    private static function isRead(string $sql): bool
    {
        return (bool)preg_match('/^\s*(SELECT|WITH\s+[\s\S]*?\)\s*SELECT|PRAGMA|EXPLAIN)\b/i', $sql);
    }

    private static function inTransaction(): bool
    {
        return function_exists('db') && db()->inTransaction();
    }

    public static function releaseOthers(?self $except = null): void
    {
        if (!self::$live) return;
        foreach (self::$live as $st => $_) {
            if ($st !== $except) {
                try { $st->closeCursor(); } catch (\Throwable) {}
            }
        }
    }
}
