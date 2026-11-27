<?php

use App\Services\Voice\SpokenText;

test('citations, markdown and lists are removed for reading aloud', function () {
    $text = "## Hours\n**Open** 9 to 5 [1].\n- Saturday: 10 to 2 [2, 3]\n- Sunday: closed\nSee https://harbor.test/hours";

    expect(SpokenText::from($text))->toBe('Hours. Open 9 to 5. Saturday: 10 to 2. Sunday: closed. See the link in the documents.');
});

test('amounts are written so an English voice reads them as words', function () {
    $text = 'Rs. 1,200,000 to Rs. 2,200,000 pays Rs 6 k plus 11% [1]. At ₹24 lakh it is ₹1 16 000 plus 20 % of ₹22 00 000, about $1.5k.';

    expect(SpokenText::from($text, null, 'en'))->toBe('twelve lakh rupees to twenty-two lakh rupees pays six thousand rupees plus eleven percent.'
        .' At twenty-four lakh rupees it is one lakh sixteen thousand rupees plus twenty percent of twenty-two lakh rupees, about one point five thousand dollars.');
});

test('English numbers are spoken as words, like a person says them', function () {
    expect(SpokenText::from('Tax year 2026-27: 20 cases, 2.5 lakh, 15.75 percent, 1,050 forms in 2005 and 1999.', null, 'en'))
        ->toBe('Tax year twenty twenty-six to twenty-seven: twenty cases, two point five lakh, fifteen point seven five percent, one thousand fifty forms in two thousand five and nineteen ninety-nine.')
        ->and(SpokenText::from('Call +923243348668 or 0300 1234567.', null, 'en'))
        ->toBe('Call nine two three two four three three four eight six six eight or zero three zero zero one two three four five six seven.')
        ->and(SpokenText::from('Rs 25,000,000 and 3,456,789 people.', null, 'en'))
        ->toBe('two crore fifty lakh rupees and three million four hundred fifty-six thousand seven hundred eighty-nine people.');
});

test('other languages only get their digit groups joined', function () {
    expect(SpokenText::from('Cuesta 1,200 € o el 15 %, páginas 3, 45 y 2026-27.', null, 'es'))->toBe('Cuesta 1200 € o el 15 %, páginas 3, 45 y 2026-27.')
        ->and(SpokenText::from('Open 9 to 5, 1.5 km away.'))->toBe('Open 9 to 5, 1.5 km away.');
});

test('long answers are cut at a sentence end', function () {
    $text = str_repeat('This is one sentence. ', 10);

    expect(SpokenText::from($text, 50))->toBe('This is one sentence. This is one sentence.')
        ->and(SpokenText::limit(str_repeat('word ', 40), 30))->toEndWith('.');
});
