<?php

namespace App\Services\Voice;

use App\Models\VoiceClone;
use Illuminate\Support\Str;

/**
 * The consent statement a speaker reads aloud before their voice is cloned,
 * and the check that the recording really says it.
 *
 * The statement names the speaker and the workspace and ends with two random
 * code words, so an old recording of the same person can't be passed off as
 * consent. Speech recognition must hear the code words, the "agree" word and
 * the speaker's name.
 */
final class VoiceConsent
{
    /**
     * Languages a clone can be made in. Pocket TTS speaks more (Italian,
     * Portuguese, Dutch), but the consent statement is written for these.
     */
    public const LANGUAGES = [
        'en' => 'English',
        'es' => 'Spanish',
        'fr' => 'French',
        'de' => 'German',
    ];

    private const STATEMENTS = [
        'en' => 'I, :name, agree that :workspace may create an AI copy of my voice for its virtual assistant. My code words are :words.',
        'es' => 'Yo, :name, acepto que :workspace cree una copia de mi voz con inteligencia artificial para su asistente virtual. Mis palabras clave son :words.',
        'fr' => 'Moi, :name, j\'accepte que :workspace crée une copie de ma voix par intelligence artificielle pour son assistant virtuel. Mes mots de code sont :words.',
        'de' => 'Ich, :name, bin einverstanden, dass :workspace mit künstlicher Intelligenz eine Kopie meiner Stimme für seinen virtuellen Assistenten erstellt. Meine Codewörter sind :words.',
    ];

    /** The word that says yes, as speech recognition writes it (accents removed). */
    private const AGREE = [
        'en' => ['agree', 'agreed'],
        'es' => ['acepto'],
        'fr' => ['accepte'],
        'de' => ['einverstanden'],
    ];

    /** Read after the statement, so the clone has enough of the voice to learn from. */
    private const PASSAGES = [
        'en' => 'Thanks for calling. I can help you find opening hours, prices and answers from our documents. If I don\'t know something, I\'ll tell you honestly and take a message for the team. Have a lovely day.',
        'es' => 'Gracias por llamar. Puedo ayudarle con los horarios, los precios y las respuestas de nuestros documentos. Si no sé algo, se lo diré con sinceridad y dejaré un mensaje al equipo. Que tenga un buen día.',
        'fr' => 'Merci de votre appel. Je peux vous aider avec les horaires, les prix et les réponses de nos documents. Si je ne sais pas quelque chose, je vous le dirai franchement et je laisserai un message à l\'équipe. Bonne journée.',
        'de' => 'Danke für Ihren Anruf. Ich helfe Ihnen gern mit Öffnungszeiten, Preisen und Antworten aus unseren Unterlagen. Wenn ich etwas nicht weiß, sage ich es ehrlich und gebe dem Team eine Nachricht weiter. Einen schönen Tag noch.',
    ];

    /** Short, common words that speech recognition spells the same way every time. */
    private const WORDS = [
        'en' => ['apple', 'river', 'garden', 'silver', 'pepper', 'window', 'yellow', 'rocket', 'orange', 'candle', 'forest', 'tiger', 'ocean', 'pencil', 'blanket', 'mountain', 'violin', 'harbor', 'coffee', 'thunder'],
        'es' => ['manzana', 'jardín', 'ventana', 'naranja', 'montaña', 'guitarra', 'tortuga', 'camino', 'pelota', 'estrella', 'bosque', 'zapato', 'ciudad', 'galleta', 'paloma', 'cereza', 'puente', 'caballo', 'limón', 'nube'],
        'fr' => ['pomme', 'jardin', 'fenêtre', 'orange', 'montagne', 'guitare', 'tortue', 'chemin', 'étoile', 'forêt', 'soleil', 'bateau', 'nuage', 'cerise', 'pont', 'cheval', 'citron', 'lapin', 'bougie', 'musique'],
        'de' => ['apfel', 'garten', 'fenster', 'orange', 'berg', 'gitarre', 'schildkröte', 'straße', 'stern', 'wald', 'sonne', 'schiff', 'wolke', 'kirsche', 'brücke', 'pferd', 'zitrone', 'hase', 'kerze', 'musik'],
    ];

    public static function language(?string $code): string
    {
        return array_key_exists((string) $code, self::LANGUAGES) ? (string) $code : 'en';
    }

    /**
     * Two different random code words.
     */
    public static function codeWords(string $language): string
    {
        $words = self::WORDS[self::language($language)];
        $picked = array_rand(array_flip($words), 2);

        return implode(' ', (array) $picked);
    }

    public static function statement(string $language, string $speaker, string $workspace, string $codeWords): string
    {
        return strtr(self::STATEMENTS[self::language($language)], [
            ':name' => trim($speaker),
            ':workspace' => trim($workspace),
            ':words' => $codeWords,
        ]);
    }

    public static function passage(string $language): string
    {
        return self::PASSAGES[self::language($language)];
    }

    /**
     * Why the transcript isn't valid consent, or null when it is.
     */
    public static function problem(VoiceClone $clone, string $transcript): ?string
    {
        $heard = self::words($transcript);
        $language = self::language($clone->language);

        if ($heard === []) {
            return __('We could not hear anything. Check your microphone and try again.');
        }

        foreach (self::words($clone->code_words) as $word) {
            if (! self::hears($heard, $word)) {
                return __('We did not hear the code word ":word". Please read the whole statement again.', ['word' => $word]);
            }
        }

        $agreed = false;

        foreach (self::AGREE[$language] as $word) {
            $agreed = $agreed || self::hears($heard, $word);
        }

        if (! $agreed) {
            return __('We did not hear you agree. Please read the statement from the start.');
        }

        $name = self::words($clone->speaker_name);
        $named = $name === [];

        foreach ($name as $part) {
            $named = $named || self::hears($heard, $part);
        }

        if (! $named) {
            return __('We did not hear your name. Please say it at the start of the statement.');
        }

        return null;
    }

    /**
     * Lower case, accents removed, split into words.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $plain = Str::lower(Str::ascii($text));
        $plain = (string) preg_replace('/[^a-z0-9]+/', ' ', $plain);

        return array_values(array_filter(explode(' ', $plain), fn (string $word): bool => $word !== ''));
    }

    /**
     * Whether the word was heard. Words of four letters or more allow one wrong letter, since
     * speech recognition sometimes spells a name or a word a little off.
     *
     * @param  list<string>  $heard
     */
    private static function hears(array $heard, string $word): bool
    {
        $word = self::words($word)[0] ?? '';

        if ($word === '') {
            return true;
        }

        foreach ($heard as $candidate) {
            if ($candidate === $word || (strlen($word) >= 4 && levenshtein($candidate, $word) <= 1)) {
                return true;
            }
        }

        return false;
    }
}
