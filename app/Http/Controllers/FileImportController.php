<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\File;
use App\Jobs\ImportExcelJob;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class FileImportController extends Controller
{
    // Отображает главную страницу со списком файлов (Блок 2)
    public function index()
    {
        return Inertia::render('ImportPage', [
            'files' => File::orderBy('created_at', 'desc')->get()
        ]);
    }

    // Блок 1: Принять файл и отправить в очередь
    public function upload(Request $request)
    {
        // 1. Базовая валидация на тип файла и размер
        $request->validate([
            // Увеличили лимит до 51200 КБ (50 МБ)
            'file' => 'required|file|mimes:xlsx,xls|max:51200',
        ]);

        $uploadedFile = $request->file('file');
        $originalName = $uploadedFile->getClientOriginalName();

        // 2. ПРОВЕРКА НА ПОВТОРНУЮ ЗАГРУЗКУ: Ищем файл с таким же именем в БД
        $fileExists = File::where('original_name', $originalName)->exists();

        if ($fileExists) {
            // Возвращаем ошибку валидации, которая автоматически отобразится под инпутом во Vue
            return redirect()->back()->withErrors([
                'file' => 'Файл с именем "' . $originalName . '" уже был загружен ранее. Удалите старую таблицу перед повторным импортом.'
            ]);
        }

        // 3. Если файла нет, продолжаем стандартный процесс
        $tableName = 'import_' . Str::random(8) . '_' . time();
        $path = $uploadedFile->store('imports');

        $fileRecord = File::create([
            'original_name' => $originalName,
            'table_name' => $tableName,
            'status' => 'processing',
        ]);

        ImportExcelJob::dispatch($fileRecord->id, $path);

        // Перенаправляем обратно на ту же страницу (Inertia обновит данные props)
        return redirect()->back()->with('message', 'Файл отправлен на обработку');
    }

    // Блок 3: Получить данные динамической таблицы (Оставляем JSON-запрос для динамической пагинации без перезагрузки)
    public function getData(File $file, Request $request)
    {
        if ($file->status !== 'completed') {
            return response()->json(['error' => 'Данные еще обрабатываются.'], 400);
        }

        $perPage = $request->input('per_page', 10);

        // Получаем строки из динамической таблицы
        $paginatedData = DB::table($file->table_name)->paginate($perPage);

        // Возвращаем данные, подмешивая массив сохраненных заголовков
        return response()->json([
            'rows' => $paginatedData->items(),
            'headers' => $file->headers ?? [], // Передаем оригинальные названия колонок
            'current_page' => $paginatedData->currentPage(),
            'last_page' => $paginatedData->lastPage(),
            'links' => $paginatedData->linkCollection()->toArray(),
        ]);
    }


    // Блок 2: Удаление файла и динамической таблицы
    public function destroy(File $file)
    {
        Schema::dropIfExists($file->table_name);
        $file->delete();

        return redirect()->back()->with('message', 'Таблица удалена');
    }
}
