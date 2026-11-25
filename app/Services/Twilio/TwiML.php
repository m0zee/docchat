<?php

namespace App\Services\Twilio;

use Illuminate\Http\Response;

/**
 * The few TwiML verbs the phone line needs: Say, Gather (speech) and Hangup.
 * Twilio trial accounts block Record, Stream and Dial, so none are used.
 */
final class TwiML
{
    /**
     * Say the text, then listen for speech and post it to $action.
     */
    public static function ask(string $text, string $action): Response
    {
        $language = (string) config('docchat.calls.twilio.language', 'en-US');

        return self::response(
            '<Gather input="speech" action="'.self::attr($action).'" method="POST" speechTimeout="auto" timeout="6"'
            .' actionOnEmptyResult="true" language="'.self::attr($language).'">'
            .self::say($text)
            .'</Gather>'
            // Only reached if Twilio cannot post the result.
            .'<Redirect method="POST">'.self::text($action).'</Redirect>'
        );
    }

    public static function hangUp(string $text = ''): Response
    {
        return self::response(($text === '' ? '' : self::say($text)).'<Hangup/>');
    }

    public static function say(string $text): string
    {
        $voice = (string) config('docchat.calls.twilio.voice');
        $language = (string) config('docchat.calls.twilio.language', 'en-US');

        return '<Say'.($voice !== '' ? ' voice="'.self::attr($voice).'"' : '')
            .' language="'.self::attr($language).'">'.self::text($text).'</Say>';
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

    private static function attr(string $value): string
    {
        return self::text($value);
    }
}
