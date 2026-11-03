<?php

use App\Support\AccountCampaignCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('campaigns')->insertOrIgnore(array_map(fn (string $name) => [
            'name' => $name,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], AccountCampaignCatalog::NAMES));
    }

    public function down(): void
    {
        // Keep campaign records: users and historical requests may now reference them.
    }
};
