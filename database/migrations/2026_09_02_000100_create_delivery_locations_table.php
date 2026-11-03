<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::table('purchase_requests')
            ->whereNotNull('delivery_location')
            ->where('delivery_location', '<>', '')
            ->distinct()
            ->orderBy('delivery_location')
            ->pluck('delivery_location')
            ->each(function ($name): void {
                $name = preg_replace('/\s+/u', ' ', trim((string) $name));

                if ($name !== '') {
                    DB::table('delivery_locations')->insertOrIgnore([
                        'name' => $name,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_locations');
    }
};
