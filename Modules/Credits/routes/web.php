<?php

use Illuminate\Support\Facades\Route;
use Modules\Credits\app\Http\Controllers\CreditsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('credits', CreditsController::class)->names('credits');
});
