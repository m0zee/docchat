<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Calls\CallSettings;
use App\Services\Widget\WidgetSettings;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;

class WidgetController extends Controller
{
    /**
     * Widget settings.
     *
     * What the website widget shows: title, welcome message, brand color and
     * the handoff line for questions the documents cannot answer, and whether
     * visitors can call the assistant by voice. Works with
     * the widget's public key.
     *
     * device_ai is true when the widget may let the visitor's browser write
     * answers with its built-in AI (see POST /ask/device).
     *
     * @response array{data: array{title: string, welcome: string, color: string, position: 'left'|'right', handoff: array{email: string|null, text: string}, calls: bool, device_ai: bool}}
     */
    public function __invoke(CurrentWorkspace $current): JsonResponse
    {
        $workspace = $current->require();
        $settings = WidgetSettings::for($workspace);

        return new JsonResponse(['data' => [
            'title' => $settings['title'],
            'welcome' => $settings['welcome'],
            'color' => $settings['color'],
            'position' => $settings['position'],
            'handoff' => ['email' => $settings['handoff_email'], 'text' => $settings['handoff_text']],
            'calls' => CallSettings::for($workspace)['widget_calls'],
            'device_ai' => DeviceAskController::allowed($workspace),
        ]], 200, ['Cache-Control' => 'public, max-age=60']);
    }
}
