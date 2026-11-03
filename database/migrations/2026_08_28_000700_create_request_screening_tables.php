<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('screened_by')->constrained('users')->restrictOnDelete();
            $table->string('team')->index();
            $table->string('decision')->index();
            $table->text('evidence')->nullable();
            $table->timestamp('decided_at')->index();
            $table->timestamps();
        });

        Schema::create('screening_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_screening_id')->constrained()->cascadeOnDelete();
            $table->string('asset_tag')->index();
            $table->string('item_code')->index();
            $table->text('description');
            $table->string('serial_number')->nullable()->index();
            $table->string('location');
            $table->string('source')->index();
            $table->date('release_date')->index();
            $table->foreignId('released_by')->constrained('users')->restrictOnDelete();
            $table->string('recipient');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_assets');
        Schema::dropIfExists('request_screenings');
    }
};
