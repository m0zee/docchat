<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bring your own key: a workspace's own Groq or Gemini key, encrypted
        // with APP_KEY. One key per provider and workspace.
        Schema::create('workspace_ai_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->text('api_key');
            $table->string('key_hint', 12);
            $table->string('model', 120)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'provider']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('ai_source', 20)->nullable()->after('cache_hit');
        });

        Schema::table('usage_events', function (Blueprint $table) {
            $table->string('ai_source', 20)->nullable()->after('cache_hit');
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->dropColumn('ai_source');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('ai_source');
        });

        Schema::dropIfExists('workspace_ai_keys');
    }
};
