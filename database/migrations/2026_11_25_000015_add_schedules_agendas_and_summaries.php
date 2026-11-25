<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            // What the agent covers on an outbound call, one point per entry,
            // and how many points it has asked so far.
            $table->json('agenda')->nullable()->after('opening');
            $table->unsignedSmallInteger('agenda_step')->default(0)->after('agenda');
            // When a scheduled call starts (UTC), and the time zone it was set in.
            $table->timestamp('scheduled_at')->nullable()->after('expires_at');
            $table->string('timezone', 64)->nullable()->after('scheduled_at');
            // A scheduled call link, encrypted, kept only until the "it's time"
            // email is sent. Other call tokens are only stored as a hash.
            $table->text('link_token')->nullable()->after('token_hash');

            $table->index(['status', 'scheduled_at']);
        });

        // A cached AI summary of the conversation, and the last message it covers.
        Schema::table('conversations', function (Blueprint $table) {
            $table->text('summary')->nullable();
            $table->unsignedBigInteger('summary_message_id')->nullable();
            $table->timestamp('summarized_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['summary', 'summary_message_id', 'summarized_at']);
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_at']);
            $table->dropColumn(['agenda', 'agenda_step', 'scheduled_at', 'timezone', 'link_token']);
        });
    }
};
