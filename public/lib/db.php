<?php

declare(strict_types=1);

/** Shared read-only PDO handle to the contest DB snapshot (data/tables/gamefaqs.sqlite). */
function gf(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'sqlite:' . dirname(__DIR__, 2) . '/data/tables/gamefaqs.sqlite';
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    return $pdo;
}

/** Run a query, return all rows (assoc). Optional bound params. */
function gfq(string $sql, array $params = []): array
{
    if ($params) {
        $st = gf()->prepare($sql);
        $st->execute($params);

        return $st->fetchAll();
    }

    return gf()->query($sql)->fetchAll();
}
