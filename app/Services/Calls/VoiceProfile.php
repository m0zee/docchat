<?php

namespace App\Services\Calls;

use App\Models\Workspace;
use Illuminate\Validation\Rule;

/**
 * How the calling agent sounds: its name and personality, language and
 * accent, the voice (gender, age, speed, pitch) for browser and phone calls,
 * and the turn-taking extras. Kept in workspaces.settings.calls.voice.
 *
 * Every choice here is free on its own. Twilio's Amazon Polly and Google
 * voices and voicemail detection use the Twilio trial credit, and say so.
 */
final class VoiceProfile
{
    public const DEFAULTS = [
        'assistant_name' => 'Ava',
        'personality' => 'friendly',
        'language' => 'en-US',
        // Answer in the language the caller speaks (browser calls).
        'auto_language' => true,
        'gender' => 'female',
        'age' => 'adult',
        // 0.8 to 1.3 times normal speed.
        'speed' => 1.0,
        // 0.8 to 1.2 times normal pitch.
        'pitch' => 1.0,
        // Browser calls: the visitor's own browser voice, or self-hosted Kokoro.
        'web_engine' => 'browser',
        // A browser voice name to prefer, when the visitor's device has it.
        'browser_voice' => null,
        'kokoro_voice' => 'af_heart',
        // Phone calls: a key of PHONE_VOICES, or "kokoro".
        'phone_voice' => 'basic',
        'interruptions' => true,
        'pace' => 'natural',
        'fillers' => true,
        'hold_music' => true,
        'background' => 'none',
        // Outbound phone calls: off, hang_up or leave_message.
        'voicemail' => 'off',
        'voicemail_message' => null,
    ];

    public const PERSONALITIES = [
        'friendly' => ['label' => 'Friendly', 'style' => 'Warm, relaxed and friendly, like a helpful person at the front desk.'],
        'professional' => ['label' => 'Professional', 'style' => 'Polite, clear and professional, without being stiff.'],
        'calm' => ['label' => 'Calm', 'style' => 'Calm, patient and reassuring. Speak a little slower and keep it simple.'],
        'cheerful' => ['label' => 'Cheerful', 'style' => 'Upbeat and cheerful, with a light touch of energy.'],
    ];

    /**
     * Languages and accents. "phrases" names the Phrases set, "whisper" the
     * speech-to-text language, "kokoro" the Kokoro voice prefix (null when
     * Kokoro has no voice for it), and "basic" whether Twilio's free basic
     * voice speaks it.
     */
    public const LANGUAGES = [
        'en-US' => ['label' => 'English (United States)', 'phrases' => 'en', 'whisper' => 'en', 'kokoro' => 'a', 'basic' => true],
        'en-GB' => ['label' => 'English (United Kingdom)', 'phrases' => 'en', 'whisper' => 'en', 'kokoro' => 'b', 'basic' => true],
        'en-AU' => ['label' => 'English (Australia)', 'phrases' => 'en', 'whisper' => 'en', 'kokoro' => 'b', 'basic' => false],
        'en-IN' => ['label' => 'English (India)', 'phrases' => 'en', 'whisper' => 'en', 'kokoro' => 'h', 'basic' => false],
        'es-ES' => ['label' => 'Spanish (Spain)', 'phrases' => 'es', 'whisper' => 'es', 'kokoro' => 'e', 'basic' => true],
        'es-MX' => ['label' => 'Spanish (Mexico)', 'phrases' => 'es', 'whisper' => 'es', 'kokoro' => 'e', 'basic' => false],
        'fr-FR' => ['label' => 'French (France)', 'phrases' => 'fr', 'whisper' => 'fr', 'kokoro' => 'f', 'basic' => true],
        'de-DE' => ['label' => 'German (Germany)', 'phrases' => 'de', 'whisper' => 'de', 'kokoro' => null, 'basic' => true],
        'hi-IN' => ['label' => 'Hindi (India)', 'phrases' => 'hi', 'whisper' => 'hi', 'kokoro' => 'h', 'basic' => false],
        'ur-PK' => ['label' => 'Urdu (Pakistan)', 'phrases' => 'ur', 'whisper' => 'ur', 'kokoro' => null, 'basic' => false],
        'ar-AE' => ['label' => 'Arabic (Gulf)', 'phrases' => 'ar', 'whisper' => 'ar', 'kokoro' => null, 'basic' => false],
    ];

    /**
     * Kokoro voices (Apache-2.0, self-hosted). The first letter is the
     * language: a American English, b British English, e Spanish, f French,
     * h Hindi.
     */
    public const KOKORO_VOICES = [
        'af_heart' => ['label' => 'Heart (warm)', 'gender' => 'female', 'age' => 'adult'],
        'af_bella' => ['label' => 'Bella (bright)', 'gender' => 'female', 'age' => 'adult'],
        'af_nicole' => ['label' => 'Nicole (soft)', 'gender' => 'female', 'age' => 'adult'],
        'af_sky' => ['label' => 'Sky (young)', 'gender' => 'female', 'age' => 'young'],
        'af_sarah' => ['label' => 'Sarah (mature)', 'gender' => 'female', 'age' => 'mature'],
        'am_michael' => ['label' => 'Michael (warm)', 'gender' => 'male', 'age' => 'adult'],
        'am_adam' => ['label' => 'Adam', 'gender' => 'male', 'age' => 'adult'],
        'am_puck' => ['label' => 'Puck (young)', 'gender' => 'male', 'age' => 'young'],
        'am_fenrir' => ['label' => 'Fenrir (deep)', 'gender' => 'male', 'age' => 'mature'],
        'bf_emma' => ['label' => 'Emma', 'gender' => 'female', 'age' => 'adult'],
        'bf_isabella' => ['label' => 'Isabella', 'gender' => 'female', 'age' => 'young'],
        'bm_george' => ['label' => 'George (mature)', 'gender' => 'male', 'age' => 'mature'],
        'bm_lewis' => ['label' => 'Lewis', 'gender' => 'male', 'age' => 'adult'],
        'ef_dora' => ['label' => 'Dora', 'gender' => 'female', 'age' => 'adult'],
        'em_alex' => ['label' => 'Alex', 'gender' => 'male', 'age' => 'adult'],
        'ff_siwis' => ['label' => 'Siwis', 'gender' => 'female', 'age' => 'adult'],
        'hf_alpha' => ['label' => 'Alpha', 'gender' => 'female', 'age' => 'adult'],
        'hf_beta' => ['label' => 'Beta', 'gender' => 'female', 'age' => 'young'],
        'hm_omega' => ['label' => 'Omega', 'gender' => 'male', 'age' => 'adult'],
        'hm_psi' => ['label' => 'Psi', 'gender' => 'male', 'age' => 'mature'],
    ];

    /**
     * Twilio <Say> voices. "basic" is free; Polly and Google voices use the
     * trial credit and are paid per 100 characters after it.
     */
    public const PHONE_VOICES = [
        'basic' => ['label' => 'Basic voice (free)', 'voice' => 'woman', 'male' => 'man', 'languages' => ['en-US', 'en-GB', 'es-ES', 'fr-FR', 'de-DE'], 'gender' => null, 'free' => true],
        'Polly.Joanna-Neural' => ['label' => 'Joanna (Amazon)', 'languages' => ['en-US'], 'gender' => 'female', 'free' => false],
        'Polly.Matthew-Neural' => ['label' => 'Matthew (Amazon)', 'languages' => ['en-US'], 'gender' => 'male', 'free' => false],
        'Google.en-US-Chirp3-HD-Aoede' => ['label' => 'Aoede (Google, most natural)', 'languages' => ['en-US'], 'gender' => 'female', 'free' => false],
        'Google.en-US-Chirp3-HD-Charon' => ['label' => 'Charon (Google, most natural)', 'languages' => ['en-US'], 'gender' => 'male', 'free' => false],
        'Polly.Amy-Neural' => ['label' => 'Amy (Amazon)', 'languages' => ['en-GB'], 'gender' => 'female', 'free' => false],
        'Polly.Brian-Neural' => ['label' => 'Brian (Amazon)', 'languages' => ['en-GB'], 'gender' => 'male', 'free' => false],
        'Polly.Olivia-Neural' => ['label' => 'Olivia (Amazon)', 'languages' => ['en-AU'], 'gender' => 'female', 'free' => false],
        'Google.en-AU-Neural2-B' => ['label' => 'Australian male (Google)', 'languages' => ['en-AU'], 'gender' => 'male', 'free' => false],
        'Polly.Kajal-Neural' => ['label' => 'Kajal (Amazon)', 'languages' => ['en-IN', 'hi-IN'], 'gender' => 'female', 'free' => false],
        'Google.en-IN-Neural2-B' => ['label' => 'Indian English male (Google)', 'languages' => ['en-IN'], 'gender' => 'male', 'free' => false],
        'Google.hi-IN-Neural2-B' => ['label' => 'Hindi male (Google)', 'languages' => ['hi-IN'], 'gender' => 'male', 'free' => false],
        'Polly.Lucia-Neural' => ['label' => 'Lucia (Amazon)', 'languages' => ['es-ES'], 'gender' => 'female', 'free' => false],
        'Polly.Sergio-Neural' => ['label' => 'Sergio (Amazon)', 'languages' => ['es-ES'], 'gender' => 'male', 'free' => false],
        'Polly.Mia-Neural' => ['label' => 'Mia (Amazon)', 'languages' => ['es-MX'], 'gender' => 'female', 'free' => false],
        'Polly.Andres-Neural' => ['label' => 'Andres (Amazon)', 'languages' => ['es-MX'], 'gender' => 'male', 'free' => false],
        'Polly.Lea-Neural' => ['label' => 'Lea (Amazon)', 'languages' => ['fr-FR'], 'gender' => 'female', 'free' => false],
        'Polly.Remi-Neural' => ['label' => 'Remi (Amazon)', 'languages' => ['fr-FR'], 'gender' => 'male', 'free' => false],
        'Polly.Vicki-Neural' => ['label' => 'Vicki (Amazon)', 'languages' => ['de-DE'], 'gender' => 'female', 'free' => false],
        'Polly.Daniel-Neural' => ['label' => 'Daniel (Amazon)', 'languages' => ['de-DE'], 'gender' => 'male', 'free' => false],
        'Polly.Hala-Neural' => ['label' => 'Hala (Amazon)', 'languages' => ['ar-AE'], 'gender' => 'female', 'free' => false],
        'Polly.Zayd-Neural' => ['label' => 'Zayd (Amazon)', 'languages' => ['ar-AE'], 'gender' => 'male', 'free' => false],
    ];

    /**
     * Pace: how long a pause ends the caller's turn, and the shortest gap
     * before the assistant answers, in milliseconds.
     */
    public const PACES = [
        'quick' => ['label' => 'Quick', 'end_of_turn_ms' => 700, 'reply_gap_ms' => 0, 'twilio_timeout' => 'auto'],
        'natural' => ['label' => 'Natural', 'end_of_turn_ms' => 950, 'reply_gap_ms' => 450, 'twilio_timeout' => 'auto'],
        'relaxed' => ['label' => 'Relaxed', 'end_of_turn_ms' => 1400, 'reply_gap_ms' => 800, 'twilio_timeout' => '2'],
    ];

    public const BACKGROUNDS = ['none' => 'None', 'office' => 'Quiet office', 'cafe' => 'Soft cafe'];

    public const VOICEMAIL = ['off' => 'Off', 'hang_up' => 'Hang up', 'leave_message' => 'Leave a message'];

    public const GENDERS = ['female' => 'Female', 'male' => 'Male'];

    public const AGES = ['young' => 'Young', 'adult' => 'Adult', 'mature' => 'Mature'];

    /**
     * @return array<string, mixed>
     */
    public static function for(Workspace $workspace): array
    {
        $calls = $workspace->setting('calls', []);
        $saved = is_array($calls) && is_array($calls['voice'] ?? null) ? $calls['voice'] : [];

        return self::normalize(array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS)));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(Workspace $workspace, array $values): void
    {
        $settings = $workspace->settings ?? [];
        $calls = is_array($settings['calls'] ?? null) ? $settings['calls'] : [];
        $calls['voice'] = self::normalize(array_merge(self::for($workspace), array_intersect_key($values, self::DEFAULTS)));
        $settings['calls'] = $calls;

        $workspace->update(['settings' => $settings]);
    }

    /**
     * Validation rules for the settings form, keyed without a prefix.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'assistant_name' => ['required', 'string', 'max:40', 'regex:/^[\pL\pM][\pL\pM\s\'.-]*$/u'],
            'personality' => ['required', Rule::in(array_keys(self::PERSONALITIES))],
            'language' => ['required', Rule::in(array_keys(self::LANGUAGES))],
            'auto_language' => ['boolean'],
            'gender' => ['required', Rule::in(array_keys(self::GENDERS))],
            'age' => ['required', Rule::in(array_keys(self::AGES))],
            'speed' => ['required', 'numeric', 'between:0.8,1.3'],
            'pitch' => ['required', 'numeric', 'between:0.8,1.2'],
            'web_engine' => ['required', Rule::in(['browser', 'kokoro'])],
            'browser_voice' => ['nullable', 'string', 'max:120'],
            'kokoro_voice' => ['required', Rule::in(array_keys(self::KOKORO_VOICES))],
            'phone_voice' => ['required', Rule::in([...array_keys(self::PHONE_VOICES), 'kokoro'])],
            'interruptions' => ['boolean'],
            'pace' => ['required', Rule::in(array_keys(self::PACES))],
            'fillers' => ['boolean'],
            'hold_music' => ['boolean'],
            'background' => ['required', Rule::in(array_keys(self::BACKGROUNDS))],
            'voicemail' => ['required', Rule::in(array_keys(self::VOICEMAIL))],
            'voicemail_message' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private static function normalize(array $profile): array
    {
        $pick = fn (string $key, array $allowed) => array_key_exists((string) $profile[$key], $allowed) ? (string) $profile[$key] : self::DEFAULTS[$key];

        $profile['assistant_name'] = trim((string) $profile['assistant_name']) ?: self::DEFAULTS['assistant_name'];
        $profile['personality'] = $pick('personality', self::PERSONALITIES);
        $profile['language'] = $pick('language', self::LANGUAGES);
        $profile['gender'] = $pick('gender', self::GENDERS);
        $profile['age'] = $pick('age', self::AGES);
        $profile['pace'] = $pick('pace', self::PACES);
        $profile['background'] = $pick('background', self::BACKGROUNDS);
        $profile['voicemail'] = $pick('voicemail', self::VOICEMAIL);
        $profile['kokoro_voice'] = $pick('kokoro_voice', self::KOKORO_VOICES);
        $profile['web_engine'] = in_array($profile['web_engine'], ['browser', 'kokoro'], true) ? $profile['web_engine'] : 'browser';
        $phone = (string) $profile['phone_voice'];
        $profile['phone_voice'] = $phone === 'kokoro' || array_key_exists($phone, self::PHONE_VOICES) ? $phone : 'basic';
        $profile['speed'] = round(min(1.3, max(0.8, (float) $profile['speed'])), 2);
        $profile['pitch'] = round(min(1.2, max(0.8, (float) $profile['pitch'])), 2);

        foreach (['auto_language', 'interruptions', 'fillers', 'hold_music'] as $flag) {
            $profile[$flag] = (bool) $profile[$flag];
        }

        $profile['browser_voice'] = filled($profile['browser_voice']) ? (string) $profile['browser_voice'] : null;
        $profile['voicemail_message'] = filled($profile['voicemail_message']) ? trim((string) $profile['voicemail_message']) : null;

        return $profile;
    }

    public static function kokoroAvailable(): bool
    {
        return filled(config('docchat.voice.kokoro.url'));
    }

    /**
     * The language set used for fixed phrases: the call's detected language
     * when auto detection found a supported one, else the profile's.
     *
     * @param  array<string, mixed>  $profile
     */
    public static function phraseLanguage(array $profile, ?string $detected = null): string
    {
        if ($detected !== null && in_array($detected, Phrases::LANGUAGES, true)) {
            return $detected;
        }

        return self::LANGUAGES[$profile['language']]['phrases'];
    }

    /**
     * A BCP-47 tag for the browser voice: the profile's own when the call is
     * in its language, else a common one for the detected language.
     *
     * @param  array<string, mixed>  $profile
     */
    public static function speechLang(array $profile, ?string $detected = null): string
    {
        $phrases = self::phraseLanguage($profile, $detected);

        if (self::LANGUAGES[$profile['language']]['phrases'] === $phrases) {
            return $profile['language'];
        }

        foreach (self::LANGUAGES as $tag => $language) {
            if ($language['phrases'] === $phrases) {
                return $tag;
            }
        }

        return $profile['language'];
    }

    /**
     * Turn Whisper's language ("english", "Urdu", "ur") into a two-letter code.
     */
    public static function languageCode(?string $whisper): ?string
    {
        if ($whisper === null || trim($whisper) === '') {
            return null;
        }

        $value = strtolower(trim($whisper));
        $names = ['english' => 'en', 'spanish' => 'es', 'french' => 'fr', 'german' => 'de', 'hindi' => 'hi', 'urdu' => 'ur', 'arabic' => 'ar'];

        if (isset($names[$value])) {
            return $names[$value];
        }

        return preg_match('/^[a-z]{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * The Kokoro voice to use for the call's language. The chosen voice when
     * it speaks that language, else one with the same gender that does.
     *
     * @param  array<string, mixed>  $profile
     */
    public static function kokoroVoice(array $profile, ?string $detected = null): ?string
    {
        $tag = self::speechLang($profile, $detected);
        $prefix = self::LANGUAGES[$tag]['kokoro'] ?? null;

        if ($prefix === null) {
            return null;
        }

        $chosen = (string) $profile['kokoro_voice'];

        if (str_starts_with($chosen, $prefix)) {
            return $chosen;
        }

        $fallback = null;

        foreach (self::KOKORO_VOICES as $id => $voice) {
            if (! str_starts_with($id, $prefix)) {
                continue;
            }

            $fallback ??= $id;

            if ($voice['gender'] === $profile['gender']) {
                return $id;
            }
        }

        return $fallback;
    }

    /**
     * Twilio voice and language for the phone line.
     *
     * @param  array<string, mixed>  $profile
     * @return array{voice: string, language: string, ssml: bool, kokoro: bool}
     */
    public static function phone(array $profile): array
    {
        $language = (string) $profile['language'];
        $choice = (string) $profile['phone_voice'];

        if ($choice === 'kokoro' && self::kokoroAvailable() && self::kokoroVoice($profile) !== null) {
            return ['voice' => '', 'language' => $language, 'ssml' => false, 'kokoro' => true];
        }

        $voice = self::PHONE_VOICES[$choice] ?? self::PHONE_VOICES['basic'];

        if ($choice !== 'basic' && $choice !== 'kokoro' && in_array($language, $voice['languages'], true)) {
            return ['voice' => $choice, 'language' => $language, 'ssml' => true, 'kokoro' => false];
        }

        // The basic voice only speaks a few languages; others fall back to US English.
        $basic = self::PHONE_VOICES['basic'];
        $language = in_array($language, $basic['languages'], true) ? $language : 'en-US';

        return [
            'voice' => $profile['gender'] === 'male' ? $basic['male'] : $basic['voice'],
            'language' => $language,
            'ssml' => false,
            'kokoro' => false,
        ];
    }

    /**
     * Rate and pitch for the browser voice. Age nudges both a little.
     *
     * @param  array<string, mixed>  $profile
     * @return array{rate: float, pitch: float}
     */
    public static function prosody(array $profile): array
    {
        $nudge = ['young' => [0.04, 0.12], 'adult' => [0.0, 0.0], 'mature' => [-0.06, -0.12]][$profile['age']] ?? [0.0, 0.0];

        if ($profile['personality'] === 'calm') {
            $nudge[0] -= 0.05;
        } elseif ($profile['personality'] === 'cheerful') {
            $nudge[0] += 0.03;
            $nudge[1] += 0.05;
        }

        return [
            'rate' => round(min(1.5, max(0.6, (float) $profile['speed'] + $nudge[0])), 2),
            'pitch' => round(min(1.5, max(0.5, (float) $profile['pitch'] + $nudge[1])), 2),
        ];
    }

    /**
     * The style note for spoken answers.
     *
     * @param  array<string, mixed>  $profile
     */
    public static function answerStyle(array $profile, ?string $detected = null): string
    {
        $personality = self::PERSONALITIES[$profile['personality']]['style'];
        $language = $profile['auto_language']
            ? 'Reply in the language the caller used.'
            : 'Reply in '.Phrases::languageName(self::phraseLanguage($profile)).'.';

        return 'This answer is spoken aloud on a voice call. You are '.$profile['assistant_name']
            .', a virtual assistant. '.$personality.' Use one to three short, natural spoken sentences'
            .' with everyday words and contractions. No lists, headings, links or symbols. Still cite'
            .' facts with [1] style markers; they are removed before speaking. '.$language;
    }

    /**
     * What the browser call client needs.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public static function client(array $profile, ?string $detected = null): array
    {
        $kokoro = $profile['web_engine'] === 'kokoro' && self::kokoroAvailable() && self::kokoroVoice($profile, $detected) !== null;
        $pace = self::PACES[$profile['pace']];
        $lang = self::phraseLanguage($profile, $detected);

        return [
            'engine' => $kokoro ? 'kokoro' : 'browser',
            'lang' => self::speechLang($profile, $detected),
            'gender' => $profile['gender'],
            'age' => $profile['age'],
            'browser_voice' => $profile['browser_voice'],
            ...self::prosody($profile),
            'interruptions' => $profile['interruptions'],
            'end_of_turn_ms' => $pace['end_of_turn_ms'],
            'reply_gap_ms' => $pace['reply_gap_ms'],
            'fillers' => $profile['fillers'] ? Phrases::all($lang, 'fillers') : [],
            'hold_music' => $profile['hold_music'],
            'background' => $profile['background'],
            'name' => $profile['assistant_name'],
        ];
    }
}
