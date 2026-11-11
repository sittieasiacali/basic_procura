<?php

namespace App\Support;

use App\Models\Campaign;
use Illuminate\Validation\ValidationException;

class BudgetSourceContributions
{
    /** Called inside the locked budget-preparation transaction. Amounts include shipping and VAT. */
    public static function resolve(array $validated, float $total): array
    {
        $sources = $validated['source_accounts'] ?? [['campaign_id' => $validated['campaign_id'], 'amount' => $total]];
        $cents = array_map(fn ($source) => (int) round((float) $source['amount'] * 100), $sources);
        if (array_sum($cents) !== (int) round($total * 100)) {
            throw ValidationException::withMessages(['source_accounts' => 'The account contributions must equal the approved PO total, including VAT and shipping.']);
        }
        $campaigns = Campaign::query()->where('is_active', true)->whereIn('name', array_keys(Campaign::BUDGET_ACCOUNT_OPTIONS))
            ->whereKey(array_column($sources, 'campaign_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($campaigns->count() !== count($sources)) {
            throw ValidationException::withMessages(['source_accounts' => 'Select a different active campaign source account for each contribution.']);
        }

        return array_values(array_map(function ($source) use ($campaigns): array {
            $campaign = $campaigns[$source['campaign_id']];

            return ['campaign_id' => $campaign->id, 'campaign_name' => $campaign->name, 'account_number' => $campaign->account_number,
                'amount' => number_format((float) $source['amount'], 2, '.', '')];
        }, $sources));
    }
}
