<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FileImportController;

// Главная страница импорта
Route::get('/', [FileImportController::class, 'index'])->name('import.index');
Route::post('/upload', [FileImportController::class, 'upload'])->name('import.upload');
Route::delete('/{file}', [FileImportController::class, 'destroy'])->name('import.destroy');

// Этот роут оставляем для динамической подгрузки строк во Vue через axios (Блок 3)
Route::get('/{file}/data', [FileImportController::class, 'getData'])->name('import.data');
