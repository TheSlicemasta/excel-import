<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use App\Models\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ImportExcelJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 300;

    public function __construct(
        protected int $fileId,
        protected string $filePath
    ) {}

    public function handle(): void
    {
        $fileRecord = File::find($this->fileId);
        if (!$fileRecord) return;

        $tableName = $fileRecord->table_name;
        $absolutePath = Storage::path($this->filePath);

        ini_set('memory_limit', '512M');

        $zip = new \ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            $fileRecord->update(['status' => 'failed']);
            return;
        }

        try {
            // 1. Извлекаем словарь общих строк
            $sharedStrings = [];
            $stringsEntry = $zip->getFromName('xl/sharedStrings.xml');
            if ($stringsEntry) {
                $xml = simplexml_load_string($stringsEntry);
                foreach ($xml->si as $val) {
                    $sharedStrings[] = (string)($val->t ?? $val->r->t ?? '');
                }
                unset($xml, $stringsEntry);
            }

            // 2. Достаем XML первого листа
            $sheetXmlPath = storage_path('app/private/' . $tableName . '_sheet.xml');
            file_put_contents($sheetXmlPath, $zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->close();

            // 3. Потоковое чтение
            $reader = new \XMLReader;
            if (!$reader->open($sheetXmlPath)) {
                throw new \Exception("Не удалось открыть XML-поток листа");
            }

            $headers = [];        // Оригинальные имена из файла для сохранения в метаданные
            $dbColumns = [];      // Безопасные имена для MySQL (очищенные от точек/дефисов)
            $insertBatch = [];
            $isTableCreated = false;
            $currentRowData = [];

            while ($reader->read()) {
                if ($reader->nodeType == \XMLReader::ELEMENT && $reader->name === 'row') {
                    $currentRowData = [];
                }

                if ($reader->nodeType == \XMLReader::ELEMENT && $reader->name === 'c') {
                    $cellType = $reader->getAttribute('t');
                    $coordinate = $reader->getAttribute('r');

                    preg_match('/^[A-Z]+/', $coordinate, $matches);
                    $colLetter = $matches[0] ?? 'A';
                    $currentColIndex = $this->coordinateToColumnIndex($colLetter);

                    $cellValue = '';
                    while ($reader->read()) {
                        if ($reader->nodeType == \XMLReader::ELEMENT && $reader->name === 'v') {
                            $cellValue = $reader->readString();
                            break;
                        }
                        if ($reader->nodeType == \XMLReader::END_ELEMENT && $reader->name === 'c') {
                            break;
                        }
                    }

                    if ($cellType === 's') {
                        $cellValue = $sharedStrings[(int)$cellValue] ?? '';
                    }

                    $currentRowData[$currentColIndex] = trim($cellValue);
                }

                if ($reader->nodeType == \XMLReader::END_ELEMENT && $reader->name === 'row') {
                    if (empty($currentRowData)) continue;

                    // Если это первая строка — формируем структуру 1 в 1
                    if (empty($headers)) {
                        $headers = $currentRowData;
                        $maxHeaderIndex = max(array_keys($headers));

                        // Формируем чистые имена колонок для MySQL
                        for ($i = 0; $i <= $maxHeaderIndex; $i++) {
                            $rawName = isset($headers[$i]) ? trim($headers[$i]) : '';

                            if ($rawName === '') {
                                $rawName = 'column_' . ($i + 1);
                            }

                            // Сохраняем знак "#", буквы, цифры, а пробелы, дефисы и точки меняем на "_"
                            // Приводим к нижнему регистру для стандартизации MySQL
                            $safeColName = preg_replace('/[.\s-]+/', '_', mb_strtolower($rawName));

                            // На всякий случай чистим крайние подчеркивания
                            $safeColName = trim($safeColName, '_');

                            // Если после очистки имя вышло пустым, даем дефолтное
                            if ($safeColName === '') {
                                $safeColName = 'column_' . ($i + 1);
                            }

                            $dbColumns[$i] = $safeColName;
                        }

                        // Записываем очищенные имена колонок в метаданные файла для фронтенда
                        $fileRecord->update(['headers' => $dbColumns]);

                        // Создаем динамическую таблицу БЕЗ автоинкремента id и timestamps
                        Schema::create($tableName, function (Blueprint $table) use ($dbColumns) {
                            foreach ($dbColumns as $colName) {
                                $table->text($colName)->nullable();
                            }
                        });

                        $isTableCreated = true;
                        continue;
                    }

                    // Наполнение массива данными
                    $rowData = [];
                    foreach (array_keys($dbColumns) as $index) {
                        $colName = $dbColumns[$index];
                        $rawValue = $currentRowData[$index] ?? null;

                        // Конвертируем дату Excel, если она попала в поле времени
                        $rowData[$colName] = $this->transformExcelDate($rawValue);
                    }

                    $insertBatch[] = $rowData;

                    if (count($insertBatch) >= 500) {
                        DB::table($tableName)->insert($insertBatch);
                        $insertBatch = [];
                    }
                }
            }

            if (!empty($insertBatch) && $isTableCreated) {
                DB::table($tableName)->insert($insertBatch);
            }

            $reader->close();
            @unlink($sheetXmlPath);

            $fileRecord->update(['status' => 'completed']);
            Storage::delete($this->filePath);
        } catch (\Throwable $e) {
            Log::error('Excel Stream Import Error: ' . $e->getMessage());
            $fileRecord->update(['status' => 'failed']);
            if (isset($sheetXmlPath)) @unlink($sheetXmlPath);
            Schema::dropIfExists($tableName);
            Storage::delete($this->filePath);
        }
    }

    private function transformExcelDate($value)
    {
        if (is_numeric($value) && $value > 40000 && $value < 60000) {
            try {
                $utcDays = floor($value) - 2;
                $fraction = $value - floor($value);
                $seconds = round($fraction * 86400);

                return \Illuminate\Support\Carbon::create(1900, 1, 1, 0, 0, 0)
                    ->addDays($utcDays)
                    ->addSeconds($seconds)
                    ->toDateTimeString();
            } catch (\Throwable $e) {
                return $value;
            }
        }
        return $value;
    }

    private function coordinateToColumnIndex(string $letter): int
    {
        $index = 0;
        $length = strlen($letter);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letter[$i]) - 64);
        }
        return $index - 1;
    }
}
