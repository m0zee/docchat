<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Moves data from "owned by a user" to "owned by a workspace".
 * Every existing user gets a personal workspace and their rows move into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['documents', 'chunks', 'conversations', 'messages'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('workspace_id')->nullable()->after('id')
                    ->constrained()->cascadeOnDelete();
            });
        }

        $this->backfill();

        foreach (['documents', 'chunks', 'conversations', 'messages'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('workspace_id')->nullable(false)->change();
            });
        }

        // Documents and conversations from the API or a bot have no user.
        // A document stays in the workspace when its uploader is deleted;
        // web conversations are private, so they go with their user.
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreignId('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->index(['workspace_id', 'user_id', 'updated_at']);
        });

        Schema::table('chunks', function (Blueprint $table) {
            $table->index('workspace_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['workspace_id', 'created_at']);
        });
    }

    private function backfill(): void
    {
        $now = now();

        DB::table('users')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('workspace_user')
                ->whereColumn('workspace_user.user_id', 'users.id'))
            ->orderBy('id')
            ->each(function (object $user) use ($now): void {
                $name = Str::limit(Str::before(trim((string) $user->name), ' ')."'s workspace", 80, '');

                $workspaceId = DB::table('workspaces')->insertGetId([
                    'name' => $name,
                    'slug' => Str::limit(Str::slug($name) ?: 'workspace', 80, '').'-'.Str::lower(Str::random(6)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('workspace_user')->insert([
                    'workspace_id' => $workspaceId,
                    'user_id' => $user->id,
                    'role' => 'owner',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('users')->where('id', $user->id)->whereNull('current_workspace_id')
                    ->update(['current_workspace_id' => $workspaceId]);
            });

        $personal = '(select current_workspace_id from users where users.id = %s.user_id)';

        DB::statement('update documents set workspace_id = '.sprintf($personal, 'documents').' where workspace_id is null');
        DB::statement('update conversations set workspace_id = '.sprintf($personal, 'conversations').' where workspace_id is null');
        DB::statement('update chunks set workspace_id = (select workspace_id from documents where documents.id = chunks.document_id) where workspace_id is null');
        DB::statement('update messages set workspace_id = (select workspace_id from conversations where conversations.id = messages.conversation_id) where workspace_id is null');
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'created_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'user_id', 'updated_at']);
        });

        foreach (['documents', 'chunks', 'conversations', 'messages'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('workspace_id');
            });
        }
    }
};
