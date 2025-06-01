<?php
declare(strict_types=1);

// Очистка буфера и настройка заголовков
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-CSRF-Token, X-Requested-With');

// Логирование ошибок
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/evaluate_errors.log');
error_reporting(E_ALL);

require_once(__DIR__ . '/../config.php');

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Метод не поддерживается']));
}

// Инициализация сессии
session_start([
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'cookie_httponly' => true,
    'use_strict_mode' => true
]);

// Проверка CSRF
if (empty($_SERVER['HTTP_X_CSRF_TOKEN']) || empty($_SESSION['csrf_token']) || 
    !hash_equals($_SESSION['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'])) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Ошибка проверки CSRF']));
}

// Получение входных данных
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Неверный JSON формат']));
}

// Валидация
$required = ['solution', 'language'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        die(json_encode(['success' => false, 'error' => "Отсутствует поле: $field"]));
    }
}

$allowedLanguages = ['javascript', 'php', 'python', 'html', 'css'];
if (!in_array(strtolower($input['language']), $allowedLanguages)) {
    http_response_code(400);
    die(json_encode(['success' => false, 'error' => 'Неподдерживаемый язык']));
}

if (empty($_SESSION['current_task'])) {
    http_response_code(404);
    die(json_encode(['success' => false, 'error' => 'Задание не найдено']));
}

$task = $_SESSION['current_task'];

try {
    // Формирование промпта
    $prompt = [
        'model' => DEVSTRAL_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Ты — ассистент для проверки кода. Отвечай ТОЛЬКО в формате JSON. Шаблон ответа: {
  "score": 0-100,
  "correctness": 0-100,
  "efficiency": 0-100,
  "readability": 0-100,
  "message": "Краткий вердикт",
  "details": "Подробный анализ",
  "suggestions": ["Конкретные", "рекомендации"]
}'
            ],
            [
                'role' => 'user',
                'content' => "Задание: {$task['description']}\nЯзык: {$input['language']}\nРешение:\n{$input['solution']}"
            ]
        ],
        'temperature' => 0.2,
        'max_tokens' => 1500,
        'response_format' => ['type' => 'json_object']
    ];

    // Отправка запроса
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OPENROUTER_API_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($prompt),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'Content-Type: application/json',
            'HTTP-Referer: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'X-Title: HackerSpaceWorkPage'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        throw new Exception("Ошибка CURL: " . curl_error($ch));
    }
    
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("API вернул код $httpCode");
    }

    // Улучшенный парсинг ответа
    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Неверный формат ответа API");
    }

    if (empty($responseData['choices'][0]['message']['content'])) {
        throw new Exception("Пустой ответ от API");
    }

    $content = $responseData['choices'][0]['message']['content'];
    
    // Пытаемся распарсить JSON разными способами
    $evaluation = json_decode($content, true);
    if ($evaluation === null) {
        // Попробуем извлечь JSON из строки
        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $evaluation = json_decode($matches[0], true);
        }
        
        if ($evaluation === null) {
            throw new Exception("Не удалось распарсить оценку");
        }
    }

    // Нормализация данных
    $evaluation = array_merge([
        'score' => 0,
        'message' => 'Оценка не предоставлена',
        'details' => '',
        'suggestions' => []
    ], $evaluation);

    // Формирование ответа
    echo json_encode([
        'success' => true,
        'evaluation' => $evaluation
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Ошибка: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'evaluation' => [
            'score' => 0,
            'message' => 'Ошибка проверки',
            'details' => $e->getMessage()
        ]
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Отправка запроса к API
 */
function makeApiRequest(array $data): array {
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
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 100,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("CURL error: $error");
    }
    
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response
    ];
}