<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_screenings', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable();
            $table->index(['purchase_request_id', 'superseded_at']);
            $table->dropUnique(['purchase_request_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('request_screenings')->select('purchase_request_id')->groupBy('purchase_request_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Screening history exists; rollback would lose historical decisions.');
        }
        Schema::table('request_screenings', function (Blueprint $table) {
            $table->unique('purchase_request_id');
            $table->dropIndex(['purchase_request_id', 'superseded_at']);
            $table->dropColumn('superseded_at');
        });
    }
};
