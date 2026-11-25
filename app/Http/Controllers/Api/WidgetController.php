<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Widget\WidgetSettings;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;

class WidgetController extends Controller
{
    /**
     * Widget settings.
     *
     * What the website widget shows: title, welcome message, brand color and
     * the handoff line for questions the documents cannot answer. Works with
     * the widget's public key.
     *
     * @response array{data: array{title: string, welcome: string, color: string, position: 'left'|'right', handoff: array{email: string|null, text: string}}}
     */
    public function __invoke(CurrentWorkspace $current): JsonResponse
    {
        $settings = WidgetSettings::for($current->require());

        return new JsonResponse(['data' => [
            'title' => $settings['title'],
            'welcome' => $settings['welcome'],
            'color' => $settings['color'],
            'position' => $settings['position'],
            'handoff' => ['email' => $settings['handoff_email'], 'text' => $settings['handoff_text']],
        ]], 200, ['Cache-Control' => 'public, max-age=60']);
    }
}
