<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\FakeCochraneController;

Route::get('/mock/cochrane/render_portlet', function (Request $r) {
    $cur  = max(1, (int) $r->query('cur', 1));
    $path = public_path("mock/cochrane/page{$cur}.html");
    abort_unless(file_exists($path), 404);
    return response()->file($path, ['Content-Type' => 'text/html; charset=utf-8']);
});
Route::get('/mock/ping', fn () => 'pong');




Route::get('/fake/render_portlet', [FakeCochraneController::class, 'render'])
     ->name('fake.cochrane.render');