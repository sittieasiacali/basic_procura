<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_requests', function (Blueprint $table) {
            $table->date('payable_on')->nullable()->after('requested_at');
            $table->string('account_type')->nullable()->after('campaign_account_number');
        });

        DB::table('budget_requests')->whereNotNull('payment_date')->update([
            'payable_on' => DB::raw('payment_date'),
        ]);

        Schema::table('budget_requests', function (Blueprint $table) {
            $table->dropColumn('payment_date');
        });
    }

    public function down(): void
    {
        Schema::table('budget_requests', function (Blueprint $table) {
            $table->date('payment_date')->nullable()->after('requested_at');
        });

        DB::table('budget_requests')->whereNotNull('payable_on')->update([
            'payment_date' => DB::raw('payable_on'),
        ]);

        Schema::table('budget_requests', function (Blueprint $table) {
            $table->dropColumn(['payable_on', 'account_type']);
        });
    }
};
