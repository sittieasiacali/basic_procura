<?php

$directory = getenv('PROCURA_AUDIT_DIR');
if (! $directory) {
    throw new RuntimeException('Set PROCURA_AUDIT_DIR to the private isolated audit directory.');
}
$settings = json_decode(file_get_contents($directory.'/source-environment.json'), true, flags: JSON_THROW_ON_ERROR);
if ($settings['DB_CONNECTION'] !== 'sqlite' || $settings['DB_DATABASE'] !== $directory.'/flow.sqlite') {
    throw new RuntimeException('Only the isolated audit database is allowed.');
}
foreach ($settings as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
$public = dirname(__DIR__, 2).'/public';
$path = realpath($public.'/'.ltrim(rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/'));
if ($path && str_starts_with($path, realpath($public).DIRECTORY_SEPARATOR) && is_file($path) && ! str_ends_with($path, '.php')) {
    return false;
}
require $public.'/index.php';
