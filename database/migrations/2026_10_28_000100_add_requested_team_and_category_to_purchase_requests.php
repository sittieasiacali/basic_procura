<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            // Chosen by the requestor; request_type stays the screening team's own classification.
            $table->string('requested_team', 20)->nullable()->after('request_type');
            $table->string('request_category', 60)->nullable()->after('requested_team');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn(['requested_team', 'request_category']);
        });
    }
};
