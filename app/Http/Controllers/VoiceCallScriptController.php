<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the browser call client at /voice-call.js, like /widget.js: no
 * session or cookies, cacheable, loadable from any site that embeds the widget.
 */
class VoiceCallScriptController extends Controller
{
    public const PATH = 'resources/widget/voice-call.js';

    public function __invoke(Request $request): Response
    {
        $script = (string) file_get_contents(base_path(self::PATH));
        $etag = '"'.substr(hash('sha256', $script), 0, 16).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return new Response('', 304, ['ETag' => $etag, 'Cache-Control' => 'public, max-age=3600']);
        }

        return new Response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'ETag' => $etag,
            'Access-Control-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
