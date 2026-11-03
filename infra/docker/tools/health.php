<?php

$service = $argv[1] ?? '';
if ($service === 'app') {
    $command = 'SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000';
    exec($command, $output, $result);
    exit($result === 0 && str_contains(implode("\n", $output), 'pong') ? 0 : 1);
}
$command = @file_get_contents('/proc/1/cmdline') ?: '';
$needle = match ($service) { 'worker' => 'queue:work', 'scheduler' => 'schedule:work', default => '' };
exit($needle !== '' && str_contains($command, $needle) ? 0 : 1);
