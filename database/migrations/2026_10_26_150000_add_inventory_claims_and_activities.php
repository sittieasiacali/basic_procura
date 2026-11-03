<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_releases', function (Blueprint $table) {
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signature_name')->nullable();
            $table->longText('signature_data')->nullable();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
        });

        Schema::create('inventory_claim_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_release_id')->constrained()->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->timestamps();
        });

        Schema::create('inventory_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_release_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->decimal('quantity', 12, 2)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        DB::table('inventory_items')->orderBy('id')->chunkById(200, function ($items): void {
            foreach ($items as $item) {
                DB::table('inventory_activities')->insert([
                    'inventory_item_id' => $item->id,
                    'actor_id' => $item->logged_by,
                    'action' => 'ADDED',
                    'quantity' => (float) $item->quantity + (float) DB::table('inventory_releases')->where('inventory_item_id', $item->id)->sum('quantity'),
                    'occurred_at' => $item->logged_at ?? $item->created_at,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        DB::table('inventory_releases')->orderBy('id')->chunkById(200, function ($releases): void {
            foreach ($releases as $release) {
                $recipientIds = DB::table('users')->where('name', $release->released_to)->limit(2)->pluck('id');
                if ($recipientIds->count() === 1) {
                    DB::table('inventory_releases')->where('id', $release->id)->update(['recipient_user_id' => $recipientIds->first()]);
                }
                DB::table('inventory_activities')->insert([
                    'inventory_item_id' => $release->inventory_item_id,
                    'inventory_release_id' => $release->id,
                    'actor_id' => $release->released_by,
                    'action' => 'RELEASED',
                    'quantity' => $release->quantity,
                    'occurred_at' => $release->released_at,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_activities');
        Schema::dropIfExists('inventory_claim_proofs');
        Schema::table('inventory_releases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recipient_user_id');
            $table->dropConstrainedForeignId('claimed_by');
            $table->dropColumn(['signature_name', 'signature_data', 'claimed_at']);
        });
    }
};
