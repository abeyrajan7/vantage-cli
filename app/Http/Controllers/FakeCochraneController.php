<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class FakeCochraneController extends Controller
{
    public function render(Request $request)
    {
        $topicId = (string) $request->query('facetQueryTerm', '');
        $cur     = (int) $request->query('cur', 1);

        // You saved files under app/fake_cochrane/{topicId}/page_{cur}.html
        $path = base_path("app/fake_cochrane/{$topicId}/page_{$cur}.html");

        if (! File::exists($path)) {
            abort(404);
        }

        return response(File::get($path), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
