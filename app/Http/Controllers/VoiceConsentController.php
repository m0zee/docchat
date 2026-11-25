<?php

namespace App\Http\Controllers;

use App\Enums\VoiceCloneStatus;
use App\Exceptions\SpeechException;
use App\Models\VoiceClone;
use App\Services\Voice\Transcriber;
use App\Services\Voice\VoiceConsent;
use App\Services\Voice\Wav;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The page where a speaker agrees to have their voice cloned. They read the
 * consent statement and a short passage aloud in one take, recorded right
 * here; that recording is both the proof of consent and the voice sample.
 * There is no file upload, so nobody can clone a voice from someone else's
 * recording. The same link withdraws consent at any time.
 */
class VoiceConsentController extends Controller
{
    public function show(string $token): View
    {
        $clone = VoiceClone::findByToken($token);

        return view('voice-consent.show', [
            'clone' => $clone,
            'token' => $clone === null ? null : $token,
            'workspace' => $clone?->workspace,
            'passage' => $clone === null ? null : VoiceConsent::passage($clone->language),
            'minSeconds' => (int) config('docchat.voice.clone.min_seconds', 8),
            'maxSeconds' => (int) config('docchat.voice.clone.max_seconds', 45),
        ]);
    }

    public function record(Request $request, string $token, Transcriber $transcriber): JsonResponse
    {
        $clone = VoiceClone::findByToken($token);

        if ($clone === null || ! $clone->canRecord()) {
            return self::fail(410, __('This consent link has ended.'));
        }

        if ($clone->attempts >= VoiceClone::MAX_ATTEMPTS) {
            return self::fail(429, __('Too many tries. Ask for a new link.'));
        }

        $minSeconds = (int) config('docchat.voice.clone.min_seconds', 8);
        $maxSeconds = (int) config('docchat.voice.clone.max_seconds', 45);

        $request->validate([
            'audio' => ['required', 'file', 'max:'.(int) ceil($maxSeconds * 48000 * 2 / 1024 + 64)],
            'confirm' => ['accepted'],
        ]);

        $audio = (string) $request->file('audio')?->get();
        $seconds = self::seconds($audio);

        if ($seconds === null) {
            return self::fail(422, __('The recording could not be read. Please try again in another browser.'));
        }

        if ($seconds < $minSeconds || $seconds > $maxSeconds + 1) {
            return self::fail(422, __('Please record between :min and :max seconds.', ['min' => $minSeconds, 'max' => $maxSeconds]));
        }

        try {
            $transcript = $transcriber->transcribe($clone->workspace, $audio, 'consent.wav', $clone->language);
        } catch (SpeechException $exception) {
            report($exception);

            return self::fail(503, __('Speech recognition is not available right now. Please try again later.'));
        }

        $clone->increment('attempts');
        $problem = VoiceConsent::problem($clone, $transcript->text);

        if ($problem !== null) {
            $failed = $clone->attempts >= VoiceClone::MAX_ATTEMPTS;

            $clone->forceFill([
                'failure' => mb_substr($problem, 0, 200),
                'status' => $failed ? VoiceCloneStatus::Failed : VoiceCloneStatus::Waiting,
            ])->save();

            return self::fail(422, $problem, ['heard' => $transcript->text, 'tries_left' => max(0, VoiceClone::MAX_ATTEMPTS - $clone->attempts)]);
        }

        $path = $clone->samplePath();
        Storage::disk('local')->put($path, $audio);

        $clone->forceFill([
            'status' => VoiceCloneStatus::Ready,
            'sample_path' => $path,
            'sample_seconds' => round($seconds, 2),
            'transcript' => mb_substr($transcript->text, 0, 5000),
            'failure' => null,
            'consented_at' => now(),
            'consent_ip' => $request->ip(),
            'consent_user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ])->save();

        return response()->json(['data' => ['status' => 'ready', 'message' => __('Thank you. Your voice is ready, and you can withdraw your consent from this page at any time.')]]);
    }

    public function withdraw(string $token): RedirectResponse
    {
        $clone = VoiceClone::findByToken($token);

        if ($clone !== null && $clone->status !== VoiceCloneStatus::Withdrawn) {
            $clone->withdraw();
        }

        return redirect()->route('voice-consent.show', $token)->with('withdrawn', true);
    }

    /**
     * The length of a 16-bit PCM WAV, or null when it is not one.
     */
    public static function seconds(string $audio): ?float
    {
        try {
            [$format, $data] = Wav::parse($audio);
        } catch (SpeechException) {
            return null;
        }

        if (strlen($format) < 16) {
            return null;
        }

        /** @var array{code: int, channels: int, rate: int, bytes: int, align: int, bits: int} $fmt */
        $fmt = unpack('vcode/vchannels/Vrate/Vbytes/valign/vbits', $format);

        if ($fmt['code'] !== 1 || $fmt['bits'] !== 16 || $fmt['channels'] < 1 || $fmt['channels'] > 2
            || $fmt['rate'] < 8000 || $fmt['rate'] > 48000) {
            return null;
        }

        return strlen($data) / ($fmt['rate'] * $fmt['channels'] * 2);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function fail(int $status, string $message, array $extra = []): JsonResponse
    {
        return response()->json(['error' => ['message' => $message, ...$extra]], $status);
    }
}
