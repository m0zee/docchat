<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('stores citations as jsonb', function () {
    $type = DB::scalar(
        "select data_type from information_schema.columns where table_name = 'messages' and column_name = 'citations'",
    );

    expect($type)->toBe('jsonb');
});

it('deletes conversations and messages with their user', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();
    Message::factory()->for($conversation)->create();
    Message::factory()->assistant()->for($conversation)->create();

    $user->delete();

    expect(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0);
});

it('casts message role, citations and usage', function () {
    $message = Message::factory()->assistant()->create([
        'citations' => [['n' => 1, 'chunk_id' => 5]],
        'usage_estimated' => true,
    ])->fresh();

    expect($message->role)->toBe(MessageRole::Assistant)
        ->and($message->citations)->toBe([['n' => 1, 'chunk_id' => 5]])
        ->and($message->usage_estimated)->toBeTrue();
});

it('treats a conversation with no documents as all documents', function () {
    $user = User::factory()->create();
    $conversation = Conversation::factory()->for($user)->create();

    expect($conversation->scopedDocumentIds())->toBeNull();

    $document = Document::factory()->for($user)->create();
    $conversation->documents()->attach($document);

    expect($conversation->scopedDocumentIds())->toBe([$document->id]);
});

it('only lets the owner view, update or delete a conversation', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $conversation = Conversation::factory()->for($owner)->create();

    foreach (['view', 'update', 'delete'] as $ability) {
        expect($owner->can($ability, $conversation))->toBeTrue()
            ->and($other->can($ability, $conversation))->toBeFalse();
    }
});
