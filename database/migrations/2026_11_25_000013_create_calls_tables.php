<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // People the calling agent may call. Outbound calls need consent_at
        // and no opted_out_at.
        Schema::create('call_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_note', 200)->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'phone']);
        });

        // One voice call, in the browser or on the phone. The transcript is the
        // conversation's messages.
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('call_contacts')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('direction', 10);
            $table->string('transport', 10);
            $table->string('status', 16);
            $table->string('purpose', 200)->nullable();
            $table->text('opening')->nullable();
            // The browser's call token (dc_call_...), hashed. Phone calls have none.
            $table->string('token_hash', 64)->nullable()->unique();
            $table->string('provider_sid', 64)->nullable()->unique();
            $table->string('from_number', 20)->nullable();
            $table->string('to_number', 20)->nullable();
            $table->unsignedSmallInteger('turns')->default(0);
            $table->unsignedSmallInteger('silences')->default(0);
            $table->string('end_reason', 40)->nullable();
            // The language the caller speaks, when speech recognition detected it ("en", "ur").
            $table->string('language', 8)->nullable();
            // Outbound phone calls: human, machine_start, machine_end_beep... from Twilio.
            $table->string('answered_by', 30)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
        Schema::dropIfExists('call_contacts');
    }
};
