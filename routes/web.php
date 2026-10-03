<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FileImportController;

// Главная страница импорта
Route::get('/import', [FileImportController::class, 'index'])->name('import.index');
Route::post('/import/upload', [FileImportController::class, 'upload'])->name('import.upload');
Route::delete('/import/{file}', [FileImportController::class, 'destroy'])->name('import.destroy');

// Этот роут оставляем для динамической подгрузки строк во Vue через axios (Блок 3)
Route::get('/import/{file}/data', [FileImportController::class, 'getData'])->name('import.data');
