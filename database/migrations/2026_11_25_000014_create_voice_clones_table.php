<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A cloned voice for the calling agent. It is made only from a
        // recording on the consent page that starts with the speaker reading
        // a consent statement; that recording is the voice sample.
        Schema::create('voice_clones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 60);
            $table->string('speaker_name', 80);
            $table->string('speaker_email')->nullable();
            $table->string('language', 8);
            $table->string('status', 16);
            // The consent page link (dc_vc_...), hashed. Also used to withdraw.
            $table->string('token_hash', 64)->unique();
            $table->string('code_words', 60);
            $table->text('statement');
            $table->string('sample_path')->nullable();
            $table->decimal('sample_seconds', 6, 2)->nullable();
            $table->text('transcript')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('failure', 200)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->string('consent_user_agent', 255)->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_clones');
    }
};
