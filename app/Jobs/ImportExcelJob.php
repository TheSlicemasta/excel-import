<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use App\Models\File;
use Illuminate\Support\Facades\Storage;
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

        // Повышаем лимит памяти для самого воркера на время выполнения тяжелого импорта
        ini_set('memory_limit', '512M');

        $zip = new \ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            $fileRecord->update(['status' => 'failed']);
            return;
        }

        try {
            // 1. Извлекаем словарь общих строк во временный файл для потокового чтения
            $sharedStrings = [];
            $stringsEntry = $zip->getFromName('xl/sharedStrings.xml');
            if ($stringsEntry) {
                $xml = simplexml_load_string($stringsEntry);
                foreach ($xml->si as $val) {
                    $sharedStrings[] = (string)($val->t ?? $val->r->t ?? '');
                }
                unset($xml, $stringsEntry);
            }

            // 2. Достаем XML первого листа и сохраняем на диск для XMLReader
            $sheetXmlPath = storage_path('app/private/' . $tableName . '_sheet.xml');
            file_put_contents($sheetXmlPath, $zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->close();

            // 3. Начинаем потоковое чтение через XMLReader (тратит минимум памяти)
            $reader = new \XMLReader;
            if (!$reader->open($sheetXmlPath)) {
                throw new \Exception("Не удалось открыть XML-поток листа");
            }

            $headers = [];
            $insertBatch = [];
            $isTableCreated = false;
            $currentRowData = [];
            $currentColIndex = 0;

            while ($reader->read()) {
                // Если зашли в тег строки <row>
                if ($reader->nodeType == \XMLReader::ELEMENT && $reader->name === 'row') {
                    $currentRowData = [];
                }

                // Если зашли в тег ячейки <c>
                if ($reader->nodeType == \XMLReader::ELEMENT && $reader->name === 'c') {
                    $cellType = $reader->getAttribute('t');
                    $coordinate = $reader->getAttribute('r');

                    // Высчитываем точный индекс колонки по букве (A=0, B=1, Z=25...)
                    preg_match('/^[A-Z]+/', $coordinate, $matches);
                    $colLetter = $matches[0] ?? 'A';
                    $currentColIndex = $this->coordinateToColumnIndex($colLetter);

                    // Читаем значение внутри <v>
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

                    // Если это строка из словаря
                    if ($cellType === 's') {
                        $cellValue = $sharedStrings[(int)$cellValue] ?? '';
                    }

                    $currentRowData[$currentColIndex] = $cellValue;
                }

                // Когда тег строки закрывается </row>
                if ($reader->nodeType == \XMLReader::END_ELEMENT && $reader->name === 'row') {
                    if (empty($currentRowData)) continue;

                    // Если это самая первая заполненная строка — это заголовки
                    if (empty($headers)) {
                        $headers = $currentRowData;
                        // Заполняем пропуски в заголовках, если они есть
                        $maxHeaderIndex = max(array_keys($headers));
                        for ($i = 0; $i <= $maxHeaderIndex; $i++) {
                            if (!isset($headers[$i]) || $headers[$i] === '') {
                                $headers[$i] = 'Колонка ' . ($i + 1);
                            }
                        }

                        $fileRecord->update(['headers' => $headers]);

                        // Создаем динамическую таблицу в MySQL
                        Schema::create($tableName, function (Blueprint $table) use ($headers) {
                            $table->id();
                            foreach (array_keys($headers) as $index) {
                                $table->text('col_' . $index)->nullable();
                            }
                            $table->timestamps();
                        });
                        $isTableCreated = true;
                        continue; // Переходим к следующей строке (данным)
                    }

                    // Наполнение массива для вставки данных
                    $rowData = [];
                    foreach (array_keys($headers) as $index) {
                        $rowData['col_' . $index] = $currentRowData[$index] ?? null;
                    }
                    $rowData['created_at'] = now();
                    $rowData['updated_at'] = now();
                    $insertBatch[] = $rowData;

                    // Вставляем пачками по 500 строк, чтобы разгрузить буфер инсертов
                    if (count($insertBatch) >= 500) {
                        DB::table($tableName)->insert($insertBatch);
                        $insertBatch = [];
                    }
                }
            }

            // Дозаписываем остатки пачки
            if (!empty($insertBatch) && $isTableCreated) {
                DB::table($tableName)->insert($insertBatch);
            }

            $reader->close();
            @unlink($sheetXmlPath); // Чистим за собой временный файл

            // Успех!
            $fileRecord->update(['status' => 'completed']);
            Storage::delete($this->filePath);
        } catch (\Throwable $e) {
            Log::error('Excel Stream Import Error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            $fileRecord->update(['status' => 'failed']);
            if (isset($sheetXmlPath)) {
                @unlink($sheetXmlPath);
            }
            Schema::dropIfExists($tableName);
            Storage::delete($this->filePath);
        }
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
