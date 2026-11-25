<?php

use App\Services\Voice\SpokenText;

test('citations, markdown and lists are removed for reading aloud', function () {
    $text = "## Hours\n**Open** 9 to 5 [1].\n- Saturday: 10 to 2 [2, 3]\n- Sunday: closed\nSee https://harbor.test/hours";

    expect(SpokenText::from($text))->toBe('Hours. Open 9 to 5. Saturday: 10 to 2. Sunday: closed. See the link in the documents.');
});

test('long answers are cut at a sentence end', function () {
    $text = str_repeat('This is one sentence. ', 10);

    expect(SpokenText::from($text, 50))->toBe('This is one sentence. This is one sentence.')
        ->and(SpokenText::limit(str_repeat('word ', 40), 30))->toEndWith('.');
});
