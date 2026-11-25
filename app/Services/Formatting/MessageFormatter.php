<?php

namespace App\Services\Formatting;

/**
 * Turns an answer and its citations into ready-to-send message parts for a
 * channel, each under the channel's length limit.
 */
class MessageFormatter
{
    /**
     * @param  list<array<string, mixed>>  $citations  as stored on messages (n, title, page, ...)
     * @return list<string>
     */
    public function format(string $answer, array $citations, MessageFormat $format): array
    {
        $answer = trim(str_replace("\r\n", "\n", $answer));

        $text = match ($format) {
            MessageFormat::Plain => $this->plain($answer, $citations),
            MessageFormat::Markdown, MessageFormat::Discord => $this->markdown($answer, $citations),
            MessageFormat::TelegramHtml => $this->telegramHtml($answer, $citations),
            MessageFormat::WhatsApp => $this->whatsApp($answer, $citations),
        };

        return $this->split($text, $format->limit(), $format === MessageFormat::TelegramHtml);
    }

    /**
     * @param  list<array<string, mixed>>  $citations
     */
    private function plain(string $answer, array $citations): string
    {
        $answer = (string) preg_replace('/\*\*(.+?)\*\*/u', '$1', $answer);

        return $this->withSources($answer, 'Sources:', $citations, fn (string $s): string => $s);
    }

    /**
     * @param  list<array<string, mixed>>  $citations
     */
    private function markdown(string $answer, array $citations): string
    {
        return $this->withSources($answer, '**Sources**', $citations, fn (string $s): string => $s);
    }

    /**
     * @param  list<array<string, mixed>>  $citations
     */
    private function telegramHtml(string $answer, array $citations): string
    {
        $escape = fn (string $s): string => htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Escape first, then turn **bold** (within one line) into <b>.
        $html = (string) preg_replace('/\*\*([^\n*]+?)\*\*/u', '<b>$1</b>', $escape($answer));

        return $this->withSources($html, '<b>Sources</b>', $citations, $escape);
    }

    /**
     * @param  list<array<string, mixed>>  $citations
     */
    private function whatsApp(string $answer, array $citations): string
    {
        $text = (string) preg_replace('/\*\*([^\n*]+?)\*\*/u', '*$1*', $answer);

        return $this->withSources($text, '*Sources*', $citations, fn (string $s): string => $s);
    }

    /**
     * @param  list<array<string, mixed>>  $citations
     * @param  callable(string): string  $escape
     */
    private function withSources(string $answer, string $heading, array $citations, callable $escape): string
    {
        if ($citations === []) {
            return $answer;
        }

        $lines = array_map(
            fn (array $c): string => '['.(int) ($c['n'] ?? 0).'] '.$escape((string) ($c['title'] ?? '')).', p. '.(int) ($c['page'] ?? 0),
            $citations,
        );

        return $answer."\n\n".$heading."\n".implode("\n", $lines);
    }

    /**
     * Split at paragraph, then line, then word boundaries.
     *
     * @return list<string>
     */
    private function split(string $text, int $limit, bool $html): array
    {
        $parts = [];
        $current = '';

        foreach ($this->pieces($text, $limit, $html) as $piece) {
            $candidate = $current === '' ? $piece : $current."\n".$piece;

            if (mb_strlen($candidate) <= $limit) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $parts[] = $current;
            }

            $current = $piece;
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return array_map('trim', $parts);
    }

    /**
     * Lines no longer than $limit. A line that is too long is cut at spaces;
     * in HTML its tags are dropped first so a cut never breaks a tag.
     *
     * @return list<string>
     */
    private function pieces(string $text, int $limit, bool $html): array
    {
        $pieces = [];

        foreach (explode("\n", $text) as $line) {
            if (mb_strlen($line) <= $limit) {
                $pieces[] = $line;

                continue;
            }

            if ($html) {
                $line = strip_tags($line);
            }

            while (mb_strlen($line) > $limit) {
                $cut = mb_strrpos(mb_substr($line, 0, $limit), ' ');
                $cut = $cut === false || $cut < $limit / 2 ? $limit : $cut;

                // Never cut through an HTML entity such as &amp;.
                if ($html) {
                    $amp = mb_strrpos(mb_substr($line, 0, $cut), '&');

                    if ($amp !== false && mb_strpos(mb_substr($line, $amp, $cut - $amp), ';') === false) {
                        $cut = $amp;
                    }
                }

                $pieces[] = rtrim(mb_substr($line, 0, $cut));
                $line = ltrim(mb_substr($line, $cut));
            }

            $pieces[] = $line;
        }

        return $pieces;
    }
}
