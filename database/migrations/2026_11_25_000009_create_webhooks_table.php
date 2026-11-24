<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->jsonb('events');
            // Encrypted with APP_KEY: the plain secret is needed to sign.
            $table->text('secret');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
