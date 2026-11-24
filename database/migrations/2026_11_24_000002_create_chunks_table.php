<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->unsignedInteger('token_estimate');
            $table->vector('embedding', dimensions: (int) config('docchat.embedding.dimensions'));
            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
            $table->vectorIndex('embedding');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};
