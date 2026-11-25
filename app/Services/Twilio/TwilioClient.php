<?php

namespace App\Services\Twilio;

use App\Models\Call;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Places outbound calls through Twilio's REST API with Laravel's HTTP
 * client (no SDK). On a trial account Twilio only calls verified numbers.
 */
class TwilioClient
{
    /**
     * Start the call and return Twilio's call sid.
     */
    public function placeCall(Call $call, string $to): string
    {
        $config = (array) config('docchat.calls.twilio');
        $sid = (string) ($config['account_sid'] ?? '');
        $from = (string) ($config['from_number'] ?? '');

        if ($sid === '' || (string) ($config['auth_token'] ?? '') === '' || $from === '') {
            throw new RuntimeException('Set TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN and TWILIO_PHONE_NUMBER in .env.');
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, (string) $config['auth_token'])
                ->acceptJson()
                ->timeout(20)
                ->post(rtrim((string) $config['base_url'], '/').'/Accounts/'.rawurlencode($sid).'/Calls.json', [
                    'To' => $to,
                    'From' => $from,
                    'Url' => TwilioSignature::url('twilio/voice/calls/'.$call->id.'/answer'),
                    'Method' => 'POST',
                    'StatusCallback' => TwilioSignature::url('twilio/voice/calls/'.$call->id.'/status'),
                    'StatusCallbackMethod' => 'POST',
                    'Timeout' => 30,
                    'TimeLimit' => Call::maxSeconds(),
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Twilio could not be reached.', 0, $exception);
        }

        if (! $response->successful() || ! is_string($response->json('sid'))) {
            // Trial accounts answer 400 with code 21219 for unverified numbers.
            $message = (string) ($response->json('message') ?? 'HTTP '.$response->status());

            throw new RuntimeException('Twilio refused the call: '.$message);
        }

        return (string) $response->json('sid');
    }
}
