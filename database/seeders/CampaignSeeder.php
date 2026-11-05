<?php

namespace Database\Seeders;

use App\Models\Campaign;
use Illuminate\Database\Seeder;

class CampaignSeeder extends Seeder
{
    public function run(): void
    {
        $campaigns = [
            'Operations' => 'OPERATIONS-001',
            ...Campaign::BUDGET_ACCOUNT_OPTIONS,
        ];

        foreach ($campaigns as $name => $accountNumber) {
            Campaign::query()->firstOrCreate(
                ['name' => $name],
                ['account_number' => $accountNumber, 'is_active' => true],
            );
        }
    }
}
