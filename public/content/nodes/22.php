<?php

$matchnum = $_GET['matchnum'] ?? null;
$sort     = $_GET['sort'] ?? null;
$type     = $_GET['type'] ?? null;
if (!isset($sort)) {
    $sort = 'totalvotes';
}
if (!isset($type)) {
    $type = 'DESC';
}
if (!isset($matchnum)) {
    echo 'Must specify a match.';
} else {
    displayupdates($matchnum, $sort, $type);
}
