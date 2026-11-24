<?php

namespace App\Http\Controllers;

use App\Services\Widget\WidgetSettings;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class WidgetPreviewController extends Controller
{
    public function __invoke(CurrentWorkspace $current): View
    {
        $workspace = $current->require();
        Gate::authorize('manage', $workspace);

        $key = WidgetSettings::plainKey($workspace);
        abort_if($key === null, 404);

        return view('widget.preview', ['key' => $key]);
    }
}
