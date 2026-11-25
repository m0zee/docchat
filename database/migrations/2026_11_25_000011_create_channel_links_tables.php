<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A chat (Telegram chat id, WhatsApp number, Discord channel) linked to
        // one workspace. A chat belongs to at most one workspace at a time.
        Schema::create('channel_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('external_id');
            $table->string('label')->nullable();
            $table->foreignId('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['channel', 'external_id']);
            $table->index(['workspace_id', 'channel']);
        });

        // One-time codes an owner creates in DocChat and sends to the bot.
        Schema::create('channel_link_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16);
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_link_codes');
        Schema::dropIfExists('channel_links');
    }
};
