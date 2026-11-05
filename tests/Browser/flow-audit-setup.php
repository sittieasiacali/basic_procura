<?php

use App\Models\Campaign;
use App\Models\PoRoleAssignment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CampaignSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

// Creates an isolated SQLite HTTP test environment. Never uses the application DB.
$directory = $argv[1] ?? '';
if (! is_dir($directory) || file_exists($directory.'/flow.sqlite')) {
    throw new RuntimeException('Provide a private directory without an existing flow.sqlite file.');
}
$root = dirname(__DIR__, 2);
$settings = [
    'APP_ENV' => 'local', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'APP_URL' => 'http://127.0.0.1:18474', 'DB_CONNECTION' => 'sqlite',
    'APP_CONFIG_CACHE' => $directory.'/source-config-cache.php',
    'DB_DATABASE' => $directory.'/flow.sqlite', 'DB_URL' => '',
    'SESSION_DRIVER' => 'database', 'CACHE_STORE' => 'array',
    'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'LARAVEL_STORAGE_PATH' => $directory.'/source-storage',
];
foreach ($settings as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/private', 'app/public'] as $path) {
    mkdir($settings['LARAVEL_STORAGE_PATH'].'/'.$path, 0700, true);
}
fclose(fopen($settings['DB_DATABASE'], 'x'));
file_put_contents($directory.'/source-environment.json', json_encode($settings, JSON_THROW_ON_ERROR));
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $settings['DB_DATABASE']) {
    throw new RuntimeException('Refusing to migrate a database outside the isolated audit environment.');
}
Artisan::call('migrate', ['--force' => true]);
foreach ([RoleSeeder::class, CampaignSeeder::class] as $seeder) {
    Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
}
$campaign = Campaign::whereIn('name', array_keys(Campaign::BUDGET_ACCOUNT_OPTIONS))->firstOrFail();
$result = ['campaign_id' => $campaign->id, 'campaign_name' => $campaign->name, 'accounts' => []];
foreach (['requestor', 'it-staff', 'purchasing-team', 'purchasing', 'president', 'po-checker', 'po-noted', 'po-approver', 'finance', 'inventory', 'premises-staff'] as $slug) {
    $user = User::create([
        'name' => 'Audit '.$slug, 'email' => 'audit-'.$slug.'@example.test',
        'password' => bin2hex(random_bytes(16)), 'role_id' => Role::where('slug', $slug)->value('id'),
        'campaign_id' => $campaign->id, 'department' => 'Audit', 'is_active' => true, 'must_change_password' => false,
    ]);
    $stage = match ($slug) {
        'purchasing-team' => 'PO_PREPARED_BY', 'po-checker' => 'PO_CHECKED_BY',
        'po-noted' => 'PO_NOTED_BY', 'po-approver' => 'PO_APPROVED_BY', default => null,
    };
    if ($stage) {
        PoRoleAssignment::create(['stage' => $stage, 'user_id' => $user->id]);
    }
    $session = app('session')->driver();
    $session->setId(Str::random(40));
    $session->start();
    $session->flush();
    $session->put(app('auth')->guard('web')->getName(), $user->id);
    $session->getHandler()->setExists(false);
    $session->save();
    $name = config('session.cookie');
    $value = CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId();
    $result['accounts'][$slug] = ['id' => $user->id, 'name' => $user->name, 'cookie_name' => $name, 'cookie_value' => app('encrypter')->encrypt($value, false)];
}
file_put_contents($directory.'/source-sessions.json', json_encode($result, JSON_THROW_ON_ERROR));
echo "Isolated source environment ready.\n";
