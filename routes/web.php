<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FaceRecognitionController;

Route::get('/', [FaceRecognitionController::class, 'index'])->name('home');
Route::get('/enroll', [FaceRecognitionController::class, 'enroll'])->name('enroll');
Route::get('/users', [FaceRecognitionController::class, 'users'])->name('users');
Route::delete('/users/{name}', [FaceRecognitionController::class, 'deleteUser'])->name('users.delete');