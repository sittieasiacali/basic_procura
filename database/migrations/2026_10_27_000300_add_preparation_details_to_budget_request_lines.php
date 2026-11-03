<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_request_lines', function (Blueprint $table) {
            $table->string('priority_level', 20)->nullable();
            $table->string('request_campaign')->nullable();
            $table->string('requested_payment_method', 20)->nullable();
            $table->string('requesting_team')->nullable();
            $table->date('payable_on')->nullable();
            $table->text('remarks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('budget_request_lines', function (Blueprint $table) {
            $table->dropColumn(['priority_level', 'request_campaign', 'requested_payment_method', 'requesting_team', 'payable_on', 'remarks']);
        });
    }
};
