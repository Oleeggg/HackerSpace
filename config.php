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