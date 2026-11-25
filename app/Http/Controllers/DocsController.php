<?php

namespace App\Http\Controllers;

use App\Support\Docs;
use Illuminate\View\View;

class DocsController extends Controller
{
    public function __invoke(string $page = 'index'): View
    {
        abort_unless(Docs::exists($page), 404);

        [$previous, $next] = Docs::neighbours($page);

        return view('site.docs', [
            'page' => $page,
            'title' => Docs::title($page),
            'html' => Docs::html($page),
            'previous' => $previous,
            'next' => $next,
        ]);
    }
}
