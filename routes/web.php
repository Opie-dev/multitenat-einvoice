<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'billplz-einvoice-engine', 'docs' => '/v1/health']));
