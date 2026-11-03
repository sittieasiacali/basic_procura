<?php

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['pdo_mysql', 'gd', 'mbstring', 'zip', 'pcntl', 'intl'] as $extension) {
    if (! extension_loaded($extension)) {
        throw new RuntimeException('Missing PHP extension: '.$extension);
    }
}
foreach (['public/build/manifest.json', 'public/assets/images/Allianz_logo.png', 'vendor/autoload.php', 'artisan'] as $file) {
    if (! is_file('/var/www/html/'.$file)) {
        throw new RuntimeException('Missing image content: '.$file);
    }
}
$counts = [];
foreach (['users', 'purchase_requests', 'purchase_orders', 'attachments'] as $table) {
    $counts[$table] = Illuminate\Support\Facades\DB::table($table)->count();
}
if (isset($argv[1])) {
    $manifest = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    if (! hash_equals($manifest['app_key_sha256'], hash('sha256', config('app.key')))) {
        throw new RuntimeException('The original application key was not preserved.');
    }
    foreach ($manifest['table_counts'] as $table => $expected) {
        if (($counts[$table] ?? null) !== $expected) {
            throw new RuntimeException('Restored row count differs: '.$table);
        }
    }
    foreach ($manifest['uploads'] as $file) {
        $relative = $file['path'];
        if (! preg_match('~^(private|public)/~', $relative) || str_contains($relative, '..')) {
            throw new RuntimeException('Invalid snapshot path.');
        }
        $path = storage_path('app/'.$relative);
        if (! is_file($path) || ! hash_equals($file['sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('Missing or modified uploaded file: '.$relative);
        }
    }
}
echo json_encode(['database_counts' => $counts, 'runtime' => 'OK'], JSON_THROW_ON_ERROR).PHP_EOL;
