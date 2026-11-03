<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Finance records the transaction type at final approval, after the Budget Request is created.
        Schema::table('budget_requests', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('budget_requests')->whereNull('payment_method')->exists()) {
            return;
        }

        Schema::table('budget_requests', function (Blueprint $table) {
            $table->string('payment_method')->nullable(false)->change();
        });
    }
};
