<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiKeyType;
use App\Enums\CallDirection;
use App\Enums\CallStatus;
use App\Enums\CallTransport;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Services\Calls\CallAgent;
use App\Services\Calls\CallSettings;
use App\Support\Api\ApiError;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallController extends Controller
{
    /**
     * Start a voice call in the browser.
     *
     * The website widget's phone button calls this with the public key. The
     * reply holds a call token (dc_call_...) for the call's other routes and
     * the greeting to read aloud. Calls end after the time limit.
     *
     * @response 201 array{data: array{call_id: int, token: string, greeting: string, max_seconds: int}}
     */
    public function store(Request $request, CurrentWorkspace $current, CallAgent $agent): JsonResponse
    {
        $workspace = $current->require();
        $key = $current->apiKey();
        $public = $key?->type === ApiKeyType::Public;

        $request->validate([
            /** The widget's random visitor id. Needed with a public key. */
            'external_conversation_id' => [$public ? 'required' : 'nullable', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
        ]);

        if ($public && ! CallSettings::for($workspace)['widget_calls']) {
            return ApiError::response(403, 'calls_off', 'Voice calls are turned off for this widget.');
        }

        $call = Call::query()->create([
            'workspace_id' => $workspace->id,
            'direction' => CallDirection::Inbound,
            'transport' => CallTransport::Web,
            'status' => CallStatus::Queued,
            'expires_at' => now()->addSeconds(Call::maxSeconds() + 300),
        ]);

        $token = $call->issueToken();
        $greeting = $agent->begin($call);

        return new JsonResponse(['data' => [
            'call_id' => $call->id,
            'token' => $token,
            'greeting' => $greeting,
            'max_seconds' => Call::maxSeconds(),
        ]], 201);
    }
}
