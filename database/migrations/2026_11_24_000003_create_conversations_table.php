<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        // No rows for a conversation means "all my documents".
        Schema::create('conversation_document', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            $table->primary(['conversation_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_document');
        Schema::dropIfExists('conversations');
    }
};
