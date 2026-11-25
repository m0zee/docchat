<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('source', 20)->default('upload')->after('user_id');
            $table->string('external_id')->nullable()->after('source');
            $table->jsonb('meta')->nullable()->after('external_id');
            $table->string('content_hash', 64)->nullable()->after('embedding_model');

            // NULL external_ids never collide, so plain uploads are unaffected.
            $table->unique(['workspace_id', 'source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'source', 'external_id']);
            $table->dropColumn(['source', 'external_id', 'meta', 'content_hash']);
        });
    }
};
