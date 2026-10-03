<template>
  <div class="p-6 max-w-6xl mx-auto space-y-6">
    <!-- БЛОК 1: Форма загрузки -->
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
      <h2 class="text-xl font-bold mb-4 text-gray-800">
        1. Загрузка файла Excel (*.xlsx)
      </h2>

      <form @submit.prevent="uploadFile" class="space-y-4">
        <div class="flex items-center space-x-4">
          <input
            ref="fileInput"
            type="file"
            @input="form.file = $event.target.files[0]"
            accept=".xlsx, .xls"
            class="border p-2 rounded w-full max-w-md text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
          />
          <button
            type="submit"
            :disabled="form.processing || !form.file"
            class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-md font-medium disabled:bg-gray-300 disabled:cursor-not-allowed transition duration-150"
          >
            {{ form.processing ? "Обработка..." : "Загрузить и распарсить" }}
          </button>
        </div>

        <!-- Индикатор прогресса загрузки от Inertia -->
        <div
          v-if="form.progress"
          class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700 max-w-md"
        >
          <div
            class="bg-blue-600 h-2.5 rounded-full"
            :style="{ width: form.progress.percentage + '%' }"
          ></div>
        </div>

        <!-- Ошибки валидации -->
        <div v-if="form.errors.file" class="text-red-500 text-sm mt-1">
          {{ form.errors.file }}
        </div>
      </form>
    </div>

    <!-- БЛОК 2: Список файлов (таблиц) -->
    <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold text-gray-800">
          2. Список загруженных файлов и таблиц БД
        </h2>
        <button
          @click="refreshData"
          class="text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1 rounded border"
        >
          Обновить статусы
        </button>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr
              class="border-b border-gray-200 bg-gray-50 text-xs font-semibold text-gray-600 uppercase tracking-wider"
            >
              <th class="p-3">Имя файла</th>
              <th class="p-3">Имя таблицы в БД</th>
              <th class="p-3">Статус импорта</th>
              <th class="p-3 text-right">Действия</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 text-sm">
            <tr v-if="files.length === 0">
              <td colspan="4" class="p-4 text-center text-gray-500">
                Файлы еще не загружались.
              </td>
            </tr>
            <tr v-for="file in files" :key="file.id" class="hover:bg-gray-50">
              <td class="p-3 font-medium text-gray-900">
                {{ file.original_name }}
              </td>
              <td class="p-3 text-gray-500 font-mono text-xs">
                {{ file.table_name }}
              </td>
              <td class="p-3">
                <span
                  :class="[
                    'px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full',
                    file.status === 'processing'
                      ? 'bg-yellow-100 text-yellow-800'
                      : '',
                    file.status === 'completed'
                      ? 'bg-green-100 text-green-800'
                      : '',
                    file.status === 'failed' ? 'bg-red-100 text-red-800' : '',
                  ]"
                >
                  {{
                    file.status === "processing"
                      ? "В очереди / Обработка"
                      : file.status === "completed"
                        ? "Готово"
                        : "Ошибка"
                  }}
                </span>
              </td>
              <td class="p-3 text-right space-x-2">
                <button
                  v-if="file.status === 'completed'"
                  @click="selectFile(file)"
                  class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1 text-xs rounded transition"
                >
                  Просмотр данных
                </button>
                <button
                  @click="deleteFile(file.id)"
                  class="bg-rose-600 hover:bg-rose-700 text-white px-3 py-1 text-xs rounded transition"
                >
                  Удалить
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- БЛОК 3: Отображение данных динамической таблицы -->
    <div
      v-if="activeFile"
      class="bg-white p-6 rounded-lg shadow-sm border border-gray-200 space-y-4"
    >
      <div
        class="flex flex-col sm:flex-row justify-between sm:items-center gap-4"
      >
        <div>
          <h2 class="text-xl font-bold text-gray-800">
            3. Просмотр таблицы: {{ activeFile.original_name }}
          </h2>
          <p class="text-xs text-gray-500">
            Системное имя: {{ activeFile.table_name }}
          </p>
        </div>

        <div
          class="flex items-center space-x-2 text-sm text-gray-600 self-end sm:self-auto"
        >
          <span>Строк на странице:</span>
          <select
            v-model="perPage"
            @change="fetchTableData(1)"
            class="border border-gray-300 p-1.5 rounded-md text-sm focus:outline-none focus:ring-1 focus:ring-blue-500 pr-8"
          >
            <option :value="5">5</option>
            <option :value="10">10</option>
            <option :value="20">20</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
        </div>
      </div>

      <!-- Сама таблица с оригинальными колонками -->
      <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table
          class="w-full text-left border-collapse divide-y divide-gray-200"
        >
          <thead>
            <tr
              class="bg-gray-50 text-xs font-semibold text-gray-700 uppercase"
            >
              <!-- Выводим РЕАЛЬНЫЕ названия колонок из Excel -->
              <th
                v-for="(headerName, index) in tableHeaders"
                :key="index"
                class="p-3 border-r border-gray-200"
              >
                {{ headerName }}
              </th>
            </tr>
          </thead>
          <tbody
            class="divide-y divide-gray-200 text-xs text-gray-600 bg-white"
          >
            <tr v-if="tableRows.length === 0">
              <td
                :colspan="tableHeaders.length"
                class="p-4 text-center text-gray-500"
              >
                В этой таблице нет записей.
              </td>
            </tr>
            <tr v-for="row in tableRows" :key="row.id" class="hover:bg-gray-50">
              <!-- Выводим ячейки строго по ключу col_индекс, сопоставляя с заголовком -->
              <td
                v-for="(headerName, index) in tableHeaders"
                :key="index"
                class="p-3 border-r border-gray-100 max-w-xs truncate"
                :title="row['col_' + index]"
              >
                {{ row["col_" + index] }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Блок Пагинации -->
      <div
        v-if="pagination.last_page > 1"
        class="flex justify-between items-center pt-2"
      >
        <div class="text-xs text-gray-500">
          Страница {{ pagination.current_page }} из {{ pagination.last_page }}
        </div>
        <div class="flex space-x-1">
          <button
            @click="fetchTableData(pagination.current_page - 1)"
            :disabled="pagination.current_page === 1"
            class="px-3 py-1 border rounded text-xs bg-white hover:bg-gray-50 disabled:opacity-50"
          >
            Назад
          </button>

          <button
            v-for="page in pagination.links"
            :key="page.label"
            v-show="isPageVisible(page.label)"
            @click="page.url ? fetchTableData(parseInt(page.label)) : null"
            :class="[
              'px-3 py-1 border rounded text-xs transition-colors',
              page.active
                ? 'bg-blue-600 text-white border-blue-600'
                : 'bg-white hover:bg-gray-50',
            ]"
          >
            {{ page.label }}
          </button>

          <button
            @click="fetchTableData(pagination.current_page + 1)"
            :disabled="pagination.current_page === pagination.last_page"
            class="px-3 py-1 border rounded text-xs bg-white hover:bg-gray-50 disabled:opacity-50"
          >
            Вперед
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from "vue";
import { useForm, router } from "@inertiajs/vue3";
import axios from "axios";

// Принимаем массив файлов из бэкенда через Inertia Props
const props = defineProps({
  files: {
    type: Array,
    default: () => [],
  },
});

// Форма Inertia для отправки файла
const form = useForm({
  file: null,
});

// Состояние для Блока 3 (Просмотр данных)
const activeFile = ref(null);
const tableRows = ref([]);
const perPage = ref(10);
const pagination = ref({});

// Переменная для хранения ID интервала (таймера)
let pollingInterval = null;

const fileInput = ref(null);

// Действие 1: Отправка файла на сервер
const uploadFile = () => {
  form.post(route("import.upload"), {
    onSuccess: () => {
      form.reset(); // Сбрасываем данные формы в Inertia

      // Очищаем сам селект (инпут) в браузере
      if (fileInput.value) {
        fileInput.value.value = "";
      }
    },
  });
};

// Действие 2: Мягкое обновление пропсов файлов через Inertia (без перезагрузки страницы)
const refreshData = () => {
  router.reload({
    only: ["files"],
    preserveScroll: true,
  });
};

// Функция запуска фонового опроса сервера
const startPolling = () => {
  if (pollingInterval) return; // Если таймер уже запущен, дублировать не нужно

  pollingInterval = setInterval(() => {
    // Проверяем актуальный статус: есть ли файлы в обработке?
    const hasProcessingFiles = props.files.some(
      (file) => file.status === "processing",
    );

    if (hasProcessingFiles) {
      refreshData();
    } else {
      stopPolling(); // Если обрабатывать больше нечего — выключаем таймер
    }
  }, 2000); // Опрос каждые 2 секунды
};

// Функция остановки таймера
const stopPolling = () => {
  if (pollingInterval) {
    clearInterval(pollingInterval);
    pollingInterval = null;
  }
};

// Следим за изменением списка файлов: если появился файл со статусом 'processing' — включаем опрос
watch(
  () => props.files,
  (newFiles) => {
    const hasProcessing = newFiles.some((file) => file.status === "processing");
    if (hasProcessing) {
      startPolling();
    } else {
      stopPolling();
    }
  },
  { immediate: true },
); // immediate проверит статус сразу при загрузке страницы

// Очищаем таймер при уходе со страницы, чтобы избежать утечек памяти
onUnmounted(() => {
  stopPolling();
});

// Действие 2: Удаление файла и его таблицы
const deleteFile = (id) => {
  if (
    !confirm(
      "Вы уверены? Это безвозвратно удалит динамическую таблицу из базы данных.",
    )
  )
    return;

  router.delete(route("import.destroy", id), {
    onSuccess: () => {
      if (activeFile.value?.id === id) {
        activeFile.value = null;
        tableRows.value = [];
      }
    },
  });
};

// Действие 3: Выбор файла для просмотра
const selectFile = (file) => {
  activeFile.value = file;
  fetchTableData(1);
};

// Добавляем реактивную переменную для заголовков
const tableHeaders = ref([]);

// Действие 3: Асинхронное получение строк таблицы с пагинацией через Axios
const fetchTableData = async (page = 1) => {
  if (!activeFile.value) return;

  try {
    const response = await axios.get(
      route("import.data", activeFile.value.id),
      {
        params: {
          page: page,
          per_page: perPage.value,
        },
      },
    );

    // Перезаписываем состояния из нового формата ответа
    tableRows.value = response.data.rows;
    tableHeaders.value = response.data.headers; // Сохраняем оригинальные заголовки

    pagination.value = {
      current_page: response.data.current_page,
      last_page: response.data.last_page,
      links: response.data.links.filter(
        (link) => !link.label.includes("Prev") && !link.label.includes("Next"),
      ),
    };
  } catch (error) {
    alert(
      error.response?.data?.error ||
        "Произошла ошибка при загрузке данных таблицы",
    );
  }
};

// Хелпер для ограничения количества отображаемых кнопок страниц в пагинации
const isPageVisible = (label) => {
  const pageNum = parseInt(label);
  if (isNaN(pageNum)) return false;
  return (
    Math.abs(pageNum - pagination.value.current_page) <= 2 ||
    pageNum === 1 ||
    pageNum === pagination.value.last_page
  );
};
</script>
