<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::table('purchase_requests', function (Blueprint $table) {
            // Snapshot on submission: changing an account's campaign must not move its history.
            $table->foreignId('chat_campaign_id')->nullable()->constrained('campaigns')->restrictOnDelete();
        });
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 24)->default('message');
            $table->text('body');
            $table->json('metadata')->nullable();
            $table->uuid('client_id')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->timestamps();
            $table->index(['purchase_request_id', 'id']);
            $table->unique(['purchase_request_id', 'user_id', 'client_id'], 'chat_message_retry_unique');
        });
        Schema::create('chat_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_message_id')->default(0);
            $table->timestamps();
            $table->unique(['purchase_request_id', 'user_id']);
        });
        Schema::create('chat_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['chat_message_id', 'user_id']);
        });
        Schema::create('mobile_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->constrained()->cascadeOnDelete();
            $table->string('push_token')->unique();
            $table->string('platform', 10);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_devices');
        Schema::dropIfExists('chat_acknowledgements');
        Schema::dropIfExists('chat_reads');
        Schema::dropIfExists('chat_messages');
        Schema::table('purchase_requests', fn (Blueprint $table) => $table->dropConstrainedForeignId('chat_campaign_id'));
        Schema::dropIfExists('personal_access_tokens');
    }
};
