<?php

namespace App\Http\Controllers\Twilio;

use App\Http\Controllers\Controller;
use App\Services\Twilio\TwiML;
use App\Services\Voice\KokoroTextToSpeech;
use App\Services\Voice\Wav;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Audio files the phone line plays with <Play>.
 */
class TwilioAudioController extends Controller
{
    public function hold(): Response
    {
        $wav = Cache::rememberForever('twilio:hold-tone:v1', fn (): string => base64_encode(Wav::holdTone()));

        return new Response((string) base64_decode($wav), 200, [
            'Content-Type' => 'audio/wav',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function clip(Request $request, string $clip): Response
    {
        $path = KokoroTextToSpeech::path($clip);

        abort_unless(hash_equals(TwiML::clipSignature($clip), (string) $request->query('sig')), 403);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return new Response((string) Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
