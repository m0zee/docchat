<?php

use App\Enums\DocumentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('mime');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('page_count')->nullable();
            $table->string('status')->default(DocumentStatus::Pending->value)->index();
            $table->text('error')->nullable();
            $table->string('embedding_model')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
