<?php

namespace App\Services\Twilio;

use App\Exceptions\SpeechException;
use App\Models\Workspace;
use App\Services\Calls\VoiceProfile;
use App\Services\Voice\KokoroTextToSpeech;
use Illuminate\Http\Response;

/**
 * The TwiML verbs the phone line needs: Say (or Play for the self-hosted
 * Kokoro voice), Gather with speech, Pause, Redirect and Hangup. Twilio
 * trial accounts block Record, Stream and Dial, so none are used.
 *
 * The voice, language, speed, interruptions and pace come from the
 * workspace's voice profile.
 */
final class TwiML
{
    /**
     * @param  array<string, mixed>  $profile
     * @param  array{voice: string, language: string, ssml: bool, kokoro: bool}  $phone
     */
    private function __construct(private array $profile, private array $phone) {}

    public static function for(Workspace $workspace): self
    {
        $profile = VoiceProfile::for($workspace);

        return new self($profile, VoiceProfile::phone($profile));
    }

    /**
     * Say the text, then listen for speech and post it to $action. The caller
     * can talk over the assistant unless interruptions are off.
     */
    public function ask(string $text, string $action): Response
    {
        $pace = VoiceProfile::PACES[$this->profile['pace']] ?? VoiceProfile::PACES['natural'];

        return self::response(
            '<Gather input="speech" action="'.self::text($action).'" method="POST"'
            .' speechTimeout="'.self::text((string) $pace['twilio_timeout']).'" timeout="6"'
            .' actionOnEmptyResult="true" language="'.self::text($this->phone['language']).'"'
            .' bargeIn="'.($this->profile['interruptions'] ? 'true' : 'false').'">'
            .$this->speech($text)
            .'</Gather>'
            // Only reached if Twilio cannot post the result.
            .'<Redirect method="POST">'.self::text($action).'</Redirect>'
        );
    }

    public function hangUp(string $text = ''): Response
    {
        return self::response(($text === '' ? '' : $this->speech($text)).'<Hangup/>');
    }

    /**
     * While the answer is being written: an optional filler line, the hold
     * sound or a short pause, then come back to $next.
     */
    public function wait(string $filler, string $next): Response
    {
        $body = $filler === '' ? '' : $this->speech($filler);
        $body .= $this->profile['hold_music']
            ? '<Play>'.self::text(TwilioSignature::url('twilio/voice/hold.wav')).'</Play>'
            : '<Pause length="2"/>';

        return self::response($body.'<Redirect method="POST">'.self::text($next).'</Redirect>');
    }

    /**
     * The text as speech: Kokoro audio when that voice is picked (falling back
     * to Say if it can't be made), else Say with the chosen voice.
     */
    public function speech(string $text): string
    {
        if ($this->phone['kokoro']) {
            $clip = self::kokoroClip($this->profile, $text);

            if ($clip !== null) {
                return '<Play>'.self::text(TwilioSignature::url('twilio/voice/audio/'.$clip.'?sig='.self::clipSignature($clip))).'</Play>';
            }
        }

        $body = self::text($text);
        $speed = (float) $this->profile['speed'];

        if ($this->phone['ssml'] && abs($speed - 1.0) >= 0.05) {
            $body = '<prosody rate="'.((int) round($speed * 100)).'%">'.$body.'</prosody>';
        }

        $voice = $this->phone['voice'] === '' ? '' : ' voice="'.self::text($this->phone['voice']).'"';

        return '<Say'.$voice.' language="'.self::text($this->phone['language']).'">'.$body.'</Say>';
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private static function kokoroClip(array $profile, string $text): ?string
    {
        $voice = VoiceProfile::kokoroVoice($profile);

        if ($voice === null) {
            return null;
        }

        try {
            return app(KokoroTextToSpeech::class)->clip($text, $voice, VoiceProfile::prosody($profile)['rate']);
        } catch (SpeechException $exception) {
            report($exception);

            return null;
        }
    }

    public static function clipSignature(string $clip): string
    {
        return substr(hash_hmac('sha256', 'twilio-clip:'.$clip, (string) config('app.key')), 0, 32);
    }

    public static function response(string $body): Response
    {
        return new Response(
            '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<Response>'.$body.'</Response>',
            200,
            ['Content-Type' => 'text/xml; charset=utf-8'],
        );
    }

    private static function text(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
