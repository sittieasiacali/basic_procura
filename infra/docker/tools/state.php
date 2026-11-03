<?php

$mode = $argv[1] ?? '';
$hash = $argv[2] ?? '';
$path = '/var/www/html/storage/app/.procura-import.json';
$state = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
if ($mode === 'read') {
    echo json_encode($state, JSON_THROW_ON_ERROR);
    exit;
}
if (! preg_match('/^[a-f0-9]{64}$/', $hash) || ! in_array($mode, ['database', 'complete'], true)) {
    exit(1);
}
$state['database_sha256'] = $hash;
$state['stage'] = $mode;
file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
