<?php

// Local, read-only export. No passwords, application keys, or SQL are printed.
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    [$script, $destination, $dumpExecutable, $port, $tag] = $argv;
    if (! is_file($dumpExecutable) || ! preg_match('/^[a-zA-Z0-9_.-]+$/', $tag) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('Invalid export arguments.');
    }
    $connection = config('database.default');
    if (! in_array($connection, ['mysql', 'mariadb'], true)) {
        throw new RuntimeException('This exporter requires the current XAMPP MySQL/MariaDB connection.');
    }
    $db = config('database.connections.'.$connection);
    if (! preg_match('/^[a-zA-Z0-9_]+$/', $db['database'])) {
        throw new RuntimeException('Unsupported database name.');
    }
    $key = (string) config('app.key');
    if ($key === '') {
        throw new RuntimeException('The existing APP_KEY is missing.');
    }
    @mkdir($destination, 0700, true);
    @mkdir($destination.'/secrets', 0700, true);
    $writeSecret = function (string $name, string $value) use ($destination): void {
        $path = $destination.'/secrets/'.$name.'.txt';
        if (is_file($path)) {
            if ($name === 'app_key' && trim(file_get_contents($path)) !== $value) {
                throw new RuntimeException('Existing bundle APP_KEY differs from the source; choose a new destination.');
            }
            return;
        }
        if (file_put_contents($path, $value, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write secret file.');
        }
        chmod($path, 0644);
    };
    $writeSecret('app_key', $key);
    $writeSecret('db_password', bin2hex(random_bytes(24)));
    $writeSecret('db_root_password', bin2hex(random_bytes(32)));
    $writeSecret('smtp_password', (string) config('mail.mailers.smtp.password', ''));

    $settings = [
        'COMPOSE_PROJECT_NAME' => 'procura', 'PROCURA_APP_IMAGE' => 'procura/app:'.$tag,
        'PROCURA_WEB_IMAGE' => 'procura/web:'.$tag, 'PROCURA_DB_IMAGE' => 'mariadb:10.11',
        'HTTP_BIND' => '127.0.0.1', 'HTTP_PORT' => $port,
        'APP_NAME' => config('app.name'), 'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
        'APP_URL' => 'http://localhost:'.$port, 'APP_TIMEZONE' => config('app.timezone'),
        'APP_LOCALE' => config('app.locale'), 'APP_FALLBACK_LOCALE' => config('app.fallback_locale'),
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'database', 'DB_PORT' => '3306',
        'DB_DATABASE' => $db['database'], 'DB_USERNAME' => 'procura',
        'SESSION_DRIVER' => 'database', 'SESSION_LIFETIME' => config('session.lifetime', 120),
        'SESSION_SECURE_COOKIE' => 'false', 'SESSION_PATH' => '/',
        'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database', 'FILESYSTEM_DISK' => 'local',
        'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'warning',
        'MAIL_MAILER' => config('mail.default'), 'MAIL_SCHEME' => config('mail.mailers.smtp.scheme') ?: 'smtp',
        'MAIL_HOST' => config('mail.mailers.smtp.host'), 'MAIL_PORT' => config('mail.mailers.smtp.port'),
        'MAIL_REQUIRE_TLS' => config('mail.mailers.smtp.require_tls') ? 'true' : 'false',
        'MAIL_TIMEOUT' => '30', 'MAIL_USERNAME' => config('mail.mailers.smtp.username'),
        'MAIL_FROM_ADDRESS' => config('mail.from.address'), 'MAIL_FROM_NAME' => config('mail.from.name'),
        'PROCUREMENT_COMPANY_ADDRESS' => config('procurement.company.address'),
        'PROCUREMENT_BR_REQUESTED_BY' => config('procurement.budget_request_signatories.requested_by'),
        'PROCUREMENT_BR_APPROVED_BY' => config('procurement.budget_request_signatories.approved_by'),
        'PROCUREMENT_PO_PREFIX' => config('procurement.purchase_order_number.prefix'),
        'PROCUREMENT_PO_PADDING' => config('procurement.purchase_order_number.padding'),
    ];
    if (! is_file($destination.'/runtime.env')) {
        $lines = ['# Runtime settings only. Secrets are in the secrets/ directory.'];
        foreach ($settings as $name => $value) {
            $value = (string) $value;
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new RuntimeException('Multiline runtime setting is not supported: '.$name);
            }
            $lines[] = $name."='".str_replace("'", "\\'", $value)."'";
        }
        file_put_contents($destination.'/runtime.env', implode("\n", $lines)."\n");
    }

    $id = date('Ymd-His').'-'.bin2hex(random_bytes(3));
    $relative = 'data/'.$id;
    $snapshot = $destination.'/'.$relative;
    mkdir($snapshot.'/uploads/private', 0700, true);
    mkdir($snapshot.'/uploads/public', 0700, true);
    $manifest = ['version' => 1, 'created_at' => date(DATE_ATOM), 'data_directory' => $relative,
        'app_key_sha256' => hash('sha256', $key), 'table_counts' => [], 'uploads' => []];
    foreach (['users', 'purchase_requests', 'purchase_orders', 'attachments'] as $table) {
        $manifest['table_counts'][$table] = Illuminate\Support\Facades\DB::table($table)->count();
    }

    $run = function (array $command): void {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start export utility.');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Export utility failed. Check database access and utility installation.');
        }
    };
    $defaults = tempnam(sys_get_temp_dir(), 'procura-db-');
    chmod($defaults, 0600);
    $quote = fn ($value) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $value).'"';
    file_put_contents($defaults, "[client]\nhost=".$quote($db['host'])."\nport=".(int) $db['port']."\nuser=".$quote($db['username'])."\npassword=".$quote($db['password'])."\n");
    try {
        $run([$dumpExecutable, '--defaults-extra-file='.$defaults, '--single-transaction', '--quick', '--routines', '--events', '--triggers', '--hex-blob', '--default-character-set=utf8mb4', '--result-file='.$snapshot.'/database.sql', $db['database']]);
    } finally {
        unlink($defaults);
    }
    foreach (['private', 'public'] as $disk) {
        $source = $root.'/storage/app/'.$disk;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('Upload symlinks are not supported in portable snapshots.');
            }
            $path = $disk.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
            $target = $snapshot.'/uploads/'.$path;
            if ($file->isDir()) {
                @mkdir($target, 0700, true);
            } else {
                @mkdir(dirname($target), 0700, true);
                if (! copy($file->getPathname(), $target)) {
                    throw new RuntimeException('Cannot copy an uploaded file.');
                }
                $manifest['uploads'][] = ['path' => $path, 'sha256' => hash_file('sha256', $target), 'bytes' => filesize($target)];
            }
        }
    }
    $run(['tar.exe', '-cf', $snapshot.'/uploads.tar', '-C', $snapshot.'/uploads', 'private', 'public']);
    $manifest['database_sha256'] = hash_file('sha256', $snapshot.'/database.sql');
    $manifest['uploads_sha256'] = hash_file('sha256', $snapshot.'/uploads.tar');
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($snapshot.'/manifest.json', $json);
    file_put_contents($destination.'/snapshot.json', $json);
    echo 'Snapshot exported: '.$relative.' ('.count($manifest['uploads'])." uploaded files).\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Export stopped: '.$error->getMessage().PHP_EOL);
    exit(1);
}
