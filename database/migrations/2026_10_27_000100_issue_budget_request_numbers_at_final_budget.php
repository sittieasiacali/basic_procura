<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BR codes are issued when the Final budget is done, not when the Budget Maker submits.
 * Budget Requests still waiting for the Final budget lose their early code and get a new
 * one (BR-YYYY-NNNN) when they are finalized.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_requests', function (Blueprint $table) {
            $table->string('budget_request_number')->nullable()->change();
        });

        DB::table('budget_requests')
            ->whereNotIn('status', ['APPROVED', 'PARTIALLY_PAID', 'PAID', 'REJECTED'])
            ->where(fn ($query) => $query->whereNull('final_approval_status')
                ->orWhereNotIn('final_approval_status', ['APPROVED', 'REJECTED']))
            ->update(['budget_request_number' => null]);
    }

    public function down(): void
    {
        DB::table('budget_requests')->whereNull('budget_request_number')->orderBy('id')
            ->each(fn ($budget) => DB::table('budget_requests')->where('id', $budget->id)
                ->update(['budget_request_number' => 'BR-PENDING-'.$budget->id]));

        Schema::table('budget_requests', function (Blueprint $table) {
            $table->string('budget_request_number')->nullable(false)->change();
        });
    }
};
