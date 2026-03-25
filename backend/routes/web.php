<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes — GO Systems & Technology
|--------------------------------------------------------------------------
| SPA — all web requests are handled by the Vue frontend.
*/

Route::get('/', fn () => response()->json(['app' => 'GO Systems & Technology']));
