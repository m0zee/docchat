<?php

test('the API docs page renders', function () {
    $this->get('/docs/api')->assertOk()->assertSee('DocChat', false);
});

test('the OpenAPI document lists the endpoints with bearer auth', function () {
    $spec = $this->getJson('/docs/api.json')->assertOk()->json();

    expect(array_keys($spec['paths']))->toContain('/v1/ask', '/v1/ask/stream', '/v1/documents', '/v1/documents/{document}', '/v1/workspace')
        ->and($spec['components']['securitySchemes'])->not->toBeEmpty()
        ->and($spec['components']['schemas']['AskRequest']['properties'])->toHaveKeys(['question', 'format', 'external_conversation_id']);
});
