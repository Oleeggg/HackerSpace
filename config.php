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