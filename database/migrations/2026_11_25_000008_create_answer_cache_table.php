<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Goes up whenever the searchable documents change; cached
            // answers from an older version no longer match.
            $table->unsignedInteger('corpus_version')->default(0)->after('settings');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('cache_hit', 10)->nullable()->after('usage_estimated');
        });

        Schema::create('answer_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('question_hash', 64);
            // Document scope plus retrieval and prompt settings.
            $table->string('scope_hash', 64);
            $table->unsignedInteger('corpus_version');
            $table->vector('embedding', dimensions: (int) config('docchat.embedding.dimensions'));
            $table->text('answer');
            $table->jsonb('citations');
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            // Rows per workspace stay small, so the filtered scan needs no
            // vector index.
            $table->index(['workspace_id', 'scope_hash', 'corpus_version']);
            $table->index(['workspace_id', 'question_hash']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_cache');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('cache_hit');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('corpus_version');
        });
    }
};
