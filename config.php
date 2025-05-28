<?php

// Настройки OpenRouter API
define('OPENROUTER_API_KEY', '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');
define('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions');
define('DEVSTRAL_MODEL', 'mistralai/devstral-small:free');

// Настройки безопасности
header('Content-Security-Policy: default-src \'self\' https://cdnjs.cloudflare.com https://openrouter.ai');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
?>