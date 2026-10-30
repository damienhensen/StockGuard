<?php

use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;

Route::get('/products/{product}', [ProductController::class, 'show']);
Route::post('/products/{product}/reservations', [ReservationController::class, 'store']);
