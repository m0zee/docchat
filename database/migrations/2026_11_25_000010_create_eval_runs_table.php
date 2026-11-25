<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eval_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('queued');
            $table->jsonb('cases');
            $table->jsonb('document_ids')->nullable();
            $table->float('delay_seconds')->default(0);
            $table->jsonb('results');
            $table->jsonb('summary')->nullable();
            $table->string('callback_url', 2048)->nullable();
            // Encrypted with APP_KEY: the plain secret is needed to sign the callback.
            $table->text('callback_secret')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_runs');
    }
};
