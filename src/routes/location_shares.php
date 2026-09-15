<?php

use App\Http\Controllers\Api\LocationShareController;
use Illuminate\Support\Facades\Route;

Route::post('location-shares', [LocationShareController::class, 'store']);
Route::get('location-shares/incoming', [LocationShareController::class, 'incoming']);
Route::get('location-shares/outgoing', [LocationShareController::class, 'outgoing']);
Route::delete('location-shares/{id}', [LocationShareController::class, 'destroy'])->whereNumber('id');
