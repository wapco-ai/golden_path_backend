<?php

use App\Http\Controllers\Api\RouteHistoryController;
use Illuminate\Support\Facades\Route;

Route::get('users/me/route-history', [RouteHistoryController::class, 'index']);
