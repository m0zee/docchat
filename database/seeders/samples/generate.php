<?php

// Builds harbor-point-library-handbook.pdf from the text below, with no packages.
// Usage: php database/seeders/samples/generate.php

$pages = [
    <<<'TXT'
    Harbor Point Community Library - Member Handbook

    Page 1: Welcome and membership

    Welcome to the Harbor Point Community Library. This handbook explains how membership, borrowing, fees, opening hours, digital services and rooms work.

    Library cards are free for anyone who lives, works or studies in Harbor Point County. To get a card, bring a photo ID and a proof of address dated within the last 60 days to any service desk.

    Visitors from outside the county can buy a Regional Card for 30 dollars per year.

    Children under 13 need a parent or guardian to sign the application form. Teen cards are issued from age 13 without a guardian signature.

    A lost card can be replaced at the service desk for 3 dollars. Cards stay valid for three years and are renewed free of charge.
    TXT,
    <<<'TXT'
    Page 2: Borrowing and renewals

    Members can borrow up to 15 items at the same time.

    Books and audiobooks are lent for 21 days. Magazines are lent for 14 days. DVDs and video games are lent for 7 days.

    Most items can be renewed twice, online or at the desk, as long as no other member has placed a hold on them. Items with a waiting list cannot be renewed.

    Holds are free. When a held item arrives, members get an email and have 5 days to collect it from the hold shelf.

    Items from other libraries in the regional network can be requested through interlibrary loan. Each interlibrary loan costs 2 dollars and takes about two weeks.
    TXT,
    <<<'TXT'
    Page 3: Late fees and lost items

    Late fees are 25 cents per day for books and magazines and 1 dollar per day for DVDs and video games.

    The late fee for a single item never goes above 10 dollars. Children's books never have late fees.

    If an item is more than 45 days late it is treated as lost. The member is charged the replacement cost plus a 5 dollar processing fee. If the item is returned within six months, the replacement cost is refunded but the processing fee is not.

    A member who owes more than 20 dollars cannot borrow until the balance is paid.

    Every February the library runs Fine Free February: late items returned during that month have their late fees removed.
    TXT,
    <<<'TXT'
    Page 4: Opening hours and branches

    The Main Branch at 12 Lighthouse Road is open Monday to Thursday from 9 am to 8 pm, Friday from 9 am to 6 pm, and Saturday from 10 am to 4 pm. It is closed on Sundays.

    The Eastside Branch at 480 Orchard Avenue is open Tuesday to Saturday from 10 am to 5 pm and is closed on Sundays and Mondays.

    Both branches close on public holidays and close at 2 pm on December 24 and December 31.

    Items can be returned at any time through the outdoor book drop at either branch. Items in the book drop are checked in on the next opening day.
    TXT,
    <<<'TXT'
    Page 5: Digital services, Wi-Fi and printing

    Members can borrow e-books and e-audiobooks through the ShelfLine app. Each member can check out up to 10 digital titles per month, and each digital loan lasts 14 days.

    Free Wi-Fi is available in both branches on the network HPCL-Guest. The password changes on the first day of every month and is shown at the service desk.

    Printing costs 10 cents per page in black and white and 50 cents per page in colour. Scanning to email is free.

    The Main Branch has a 3D printer in the Maker Lab. Members aged 16 and over can book it for up to 2 hours per day, and printing material costs 1 dollar per hour of print time.
    TXT,
    <<<'TXT'
    Page 6: Rooms, events and conduct

    Study rooms seat up to 4 people. They can be booked up to 7 days ahead, for at most 3 hours per day per member.

    The Community Room at the Main Branch holds up to 40 people. Non-profit groups can book it for free; other groups pay 25 dollars per hour.

    Story Time for children aged 2 to 5 takes place every Wednesday at 10:30 am at the Main Branch. Registration is not needed.

    The third floor of the Main Branch is a quiet floor: phone calls and group work are not allowed there.

    Food is not allowed near computers. Covered drinks are welcome everywhere in the library.
    TXT,
];

/**
 * @return list<string>
 */
function wrapPage(string $text, int $width = 88): array
{
    $lines = [];

    foreach (explode("\n", $text) as $paragraph) {
        $paragraph = trim($paragraph);
        $lines = array_merge($lines, $paragraph === '' ? [''] : explode("\n", wordwrap($paragraph, $width, "\n", true)));
    }

    return $lines;
}

function pdfText(string $text): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

$objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
$count = count($pages);
$fontId = 3 + $count * 2;
$kids = [];

foreach ($pages as $index => $text) {
    $pageId = 3 + $index * 2;
    $kids[] = $pageId.' 0 R';

    $stream = "BT\n/F1 11 Tf\n14 TL\n60 740 Td\n";

    foreach (wrapPage($text) as $line) {
        $stream .= '('.pdfText($line).") Tj T*\n";
    }

    $stream .= "ET\n";

    $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 '.$fontId.' 0 R >> >> /Contents '.($pageId + 1).' 0 R >>';
    $objects[$pageId + 1] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream';
}

$objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$count.' >>';
$objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
$objects[$fontId + 1] = '<< /Title (Harbor Point Community Library - Member Handbook) /Author (DocChat sample, CC0) >>';
ksort($objects);

$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
$offsets = [];

foreach ($objects as $id => $body) {
    $offsets[$id] = strlen($pdf);
    $pdf .= $id." 0 obj\n".$body."\nendobj\n";
}

$max = max(array_keys($objects));
$xref = strlen($pdf);
$pdf .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";

for ($id = 1; $id <= $max; $id++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
}

$pdf .= "trailer\n<< /Size ".($max + 1).' /Root 1 0 R /Info '.($fontId + 1)." 0 R >>\nstartxref\n".$xref."\n%%EOF\n";

file_put_contents(__DIR__.DIRECTORY_SEPARATOR.'harbor-point-library-handbook.pdf', $pdf);

echo "sample written\n";
