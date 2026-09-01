<?php
declare(strict_types=1);

/** Shared read-only PDO handle to the contest DB (sc2k5_gamefaqs copy). */
function gf(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        global $GF;
        $pdo = new PDO($GF['dsn'], $GF['user'], $GF['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** Run a query, return all rows (assoc). Optional bound params. */
function gfq(string $sql, array $params = []): array {
    if ($params) {
        $st = gf()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }
    return gf()->query($sql)->fetchAll();
}
