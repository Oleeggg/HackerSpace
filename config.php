<?php

// Убедитесь, что файл сохранен без BOM (используйте редактор типа VS Code и сохраните как UTF-8 без BOM)
if (ob_get_level()) ob_end_clean(); // Очистка буфера на случай лишних символов

// Настройки OpenRouter API
define('OPENROUTER_API_KEY', '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');
define('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions');
define('DEVSTRAL_MODEL', 'mistralai/devstral-small:free');

// Настройки безопасности
header('Content-Security-Policy: default-src \'self\' https://cdnjs.cloudflare.com https://openrouter.ai');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Content-Type: application/json; charset=utf-8'); // Добавлено явное указание charset
?>