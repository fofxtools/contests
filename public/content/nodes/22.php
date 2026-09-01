<?php

$matchnum = $_GET['matchnum'] ?? null;
$sort     = $_GET['sort'] ?? null;
$type     = $_GET['type'] ?? null;
$num      = $_GET['num'] ?? null;
if (!isset($sort)) {
    $sort = 'totalvotes';
}
if (!isset($type)) {
    $type = 'DESC';
}
if (!isset($num)) {
    $num = 2;
}
if ($matchnum == '5267') {
    $num = 2;
} // For the Link vs. Snake match
if (!isset($matchnum)) {
    echo 'Must specify a match.';
} else {
    displayupdates($matchnum, $sort, $type, $num);
}
