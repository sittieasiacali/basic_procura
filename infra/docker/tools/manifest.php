<?php

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$manifest = ['version' => 1, 'created_at' => date(DATE_ATOM), 'app_key_sha256' => hash('sha256', config('app.key')), 'table_counts' => [], 'uploads' => []];
foreach (['users', 'purchase_requests', 'purchase_orders', 'attachments'] as $table) {
    $manifest['table_counts'][$table] = Illuminate\Support\Facades\DB::table($table)->count();
}
foreach (['private', 'public'] as $disk) {
    $root = storage_path('app/'.$disk);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink()) {
            throw new RuntimeException('Upload symlinks cannot be backed up portably.');
        }
        if ($file->isFile()) {
            $manifest['uploads'][] = ['path' => $disk.'/'.substr($file->getPathname(), strlen($root) + 1), 'sha256' => hash_file('sha256', $file->getPathname()), 'bytes' => $file->getSize()];
        }
    }
}
$output = $argv[1] ?? '/tmp/procura-manifest.json';
if (! str_starts_with($output, '/tmp/')) {
    throw new RuntimeException('Manifest output must be under /tmp.');
}
file_put_contents($output, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
