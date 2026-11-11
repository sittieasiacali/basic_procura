<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_request_lines', fn (Blueprint $table) => $table->json('funding_allocations')->nullable());
    }

    public function down(): void
    {
        Schema::table('budget_request_lines', fn (Blueprint $table) => $table->dropColumn('funding_allocations'));
    }
};
