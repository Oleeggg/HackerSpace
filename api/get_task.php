<?php
require_once(__DIR__ . '/../config.php');

header('Content-Type: application/json');

// Включение подробного логгирования ошибок
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/api_errors.log');

// Функция для стандартизированного ответа с ошибкой
function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

// Инициализация сессии с проверкой
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_secure' => true,
        'cookie_httponly' => true
    ]);
}

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Метод не разрешен', 405);
}

// Ограничение частоты запросов
if (!isset($_SESSION['last_task_request'])) {
    $_SESSION['last_task_request'] = 0;
}

$current_time = time();
if ($current_time - $_SESSION['last_task_request'] < 10) {
    sendError('Слишком частые запросы. Пожалуйста, подождите.', 429);
}

// Получение и валидация входных данных
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError('Неверный формат JSON: ' . json_last_error_msg());
}

$difficulty = $input['difficulty'] ?? 'beginner';
$language = $input['language'] ?? 'javascript';

// Валидация параметров
$allowedDifficulties = ['beginner', 'intermediate', 'advanced'];
$allowedLanguages = ['javascript', 'php', 'python', 'html'];

if (!in_array($difficulty, $allowedDifficulties)) {
    sendError('Недопустимый уровень сложности');
}

if (!in_array($language, $allowedLanguages)) {
    sendError('Недопустимый язык программирования');
}

// Функция для запроса к OpenRouter API
function queryOpenRouter($messages) {
    $data = [
        'model' => DEVSTRAL_MODEL,
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 1500
    ];

    $headers = [
        'Authorization: Bearer ' . OPENROUTER_API_KEY,
        'Content-Type: application/json',
        'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'X-Title: HackerSpaceWorkPage'
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OPENROUTER_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FAILONERROR => true
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        error_log("CURL Error: $error");
        throw new Exception("Ошибка подключения к API: $error");
    }

    if ($httpCode !== 200) {
        error_log("API Response ($httpCode): $response");
        throw new Exception("API вернул код $httpCode");
    }

    return json_decode($response, true);
}

// Формирование промпта
$prompt = <<<PROMPT
Сгенерируй задание по программированию со следующими параметрами:
Язык: $language
Уровень сложности: $difficulty

Требования к формату ответа:
1. Должен быть валидный JSON
2. Обязательные поля: title, description, example, initialCode, difficulty
3. difficulty должно быть на русском: Начинающий, Средний или Продвинутый
4. example и initialCode должны быть на указанном языке

Пример правильного формата:
{
    "title": "Пример задания",
    "description": "Описание задания...",
    "example": "Пример решения...",
    "initialCode": "Базовый код...",
    "difficulty": "Начинающий"
}
PROMPT;

try {
    // Отправка запроса к API
    $response = queryOpenRouter([['role' => 'user', 'content' => $prompt]]);
    
    if (!isset($response['choices'][0]['message']['content'])) {
        throw new Exception("Некорректный ответ от API");
    }

    $task = json_decode($response['choices'][0]['message']['content'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Ошибка декодирования JSON: " . json_last_error_msg());
    }

    // Валидация структуры ответа
    $requiredFields = ['title', 'description', 'example', 'initialCode', 'difficulty'];
    foreach ($requiredFields as $field) {
        if (!isset($task[$field])) {
            throw new Exception("Отсутствует обязательное поле: $field");
        }
    }

    // Дополнительные данные
    $task['language'] = $language;
    $task['difficulty_level'] = $difficulty;
    $task['generated_at'] = date('Y-m-d H:i:s');
    
    // Сохранение в сессии
    $_SESSION['current_task'] = $task;
    $_SESSION['last_task_request'] = $current_time;

    // Успешный ответ
    echo json_encode($task);
    
} catch (Exception $e) {
    error_log("Error in get_task.php: " . $e->getMessage());
    sendError($e->getMessage(), 500);
}