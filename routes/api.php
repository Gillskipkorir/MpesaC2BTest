<?php

use App\Http\Controllers\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


/** Transaction Endpoints **/
Route::post('register-urls', [TransactionController::class, 'registerUrl']);
Route::post('validation', [TransactionController::class, 'validateTransaction']);
Route::post('confirmation', [TransactionController::class, 'confirmTransaction']);
Route::post('/statusquery', [TransactionController::class, 'statusQuery']);


Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
