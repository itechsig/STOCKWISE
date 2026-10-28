<?php

use Illuminate\Support\Facades\Route;

// Serves the built React SPA (frontend/dist copied to public/, index.html
// renamed to app.html to avoid colliding with Laravel's own public/index.php
// front controller). Excludes api/* so Laravel's actual routes still work,
// and any request matching a real file under public/ (assets/*, favicon.svg,
// etc.) is served directly by the PHP built-in server before this ever runs.
Route::get('/{any?}', function () {
    return response(file_get_contents(public_path('app.html')))->header('Content-Type', 'text/html');
})->where('any', '^(?!api).*$');
