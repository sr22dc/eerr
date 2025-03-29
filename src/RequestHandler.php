<?php

namespace ReverseProxy;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Monolog\Logger;

class RequestHandler
{
    private Client $client;
    private HeaderManager $headerManager;
    private CookieManager $cookieManager;
    private Logger $logger;
    private array $config;

    public function __construct(
        HeaderManager $headerManager,
        CookieManager $cookieManager,
        Logger $logger,
        array $config
    ) {
        $this->headerManager = $headerManager;
        $this->cookieManager = $cookieManager;
        $this->logger = $logger;
        $this->config = $config;
        
        $this->client = new Client([
            'timeout' => $this->config['proxy']['timeout'],
            'verify' => $this->config['proxy']['verify_ssl'],
            'http_errors' => false, // Отключаем автоматическое выбрасывание исключений для HTTP-ошибок
        ]);
    }

    public function handleRequest(Request $request, string $targetUrl = null): Response
    {
        // Если целевой URL не указан, используем URL по умолчанию
        $targetUrl = $targetUrl ?: $this->config['proxy']['default_target'];
        
        // Логируем запрос
        $this->logger->info('Обработка запроса', [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'target' => $targetUrl,
            'client_ip' => $request->getClientIp()
        ]);
        
        // Подготавливаем заголовки для проксирования
        $headers = $this->headerManager->prepareRequestHeaders($request);
        
        // Подготавливаем cookies для проксирования
        $cookies = $this->cookieManager->prepareRequestCookies($request);
        
        // Формируем полный URL для запроса
        $fullUrl = $this->buildTargetUrl($targetUrl, $request->getPathInfo(), $request->getQueryString());
        
        try {
            // Выполняем запрос к целевому серверу
            $response = $this->client->request(
                $request->getMethod(),
                $fullUrl,
                [
                    'headers' => $headers,
                    'cookies' => $cookies,
                    'body' => $request->getContent(),
                    'allow_redirects' => false, // Отключаем автоматические редиректы
                ]
            );
            
            // Логируем успешный ответ
            $this->logger->info('Получен ответ от целевого сервера', [
                'status_code' => $response->getStatusCode(),
                'content_type' => $response->getHeaderLine('Content-Type'),
                'content_length' => $response->getHeaderLine('Content-Length')
            ]);
            
            // Создаем объект Response
            $proxyResponse = new Response(
                $response->getStatusCode(),
                $this->headerManager->prepareResponseHeaders($response->getHeaders()),
                (string) $response->getBody()
            );
            
            // Обрабатываем cookies из ответа
            $this->cookieManager->processResponseCookies($response->getHeaders(), $proxyResponse);
            
            return $proxyResponse;
        } catch (ClientException $e) {
            // Ошибка клиента (4xx)
            $this->logger->warning('Ошибка клиента при проксировании запроса', [
                'message' => $e->getMessage(),
                'target' => $fullUrl,
                'status_code' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : 400
            ]);
            
            return $this->createErrorResponse($e, 'Ошибка при обращении к целевому серверу');
        } catch (ServerException $e) {
            // Ошибка сервера (5xx)
            $this->logger->error('Ошибка сервера при проксировании запроса', [
                'message' => $e->getMessage(),
                'target' => $fullUrl,
                'status_code' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500
            ]);
            
            return $this->createErrorResponse($e, 'Ошибка на целевом сервере');
        } catch (ConnectException $e) {
            // Ошибка соединения
            $this->logger->error('Ошибка соединения с целевым сервером', [
                'message' => $e->getMessage(),
                'target' => $fullUrl
            ]);
            
            return new Response(
                503,
                ['Content-Type' => 'text/html'],
                $this->renderErrorPage(503, 'Ошибка соединения с целевым сервером', $e->getMessage())
            );
        } catch (RequestException $e) {
            // Другие ошибки запроса
            $this->logger->error('Ошибка при проксировании запроса', [
                'message' => $e->getMessage(),
                'target' => $fullUrl,
                'class' => get_class($e)
            ]);
            
            return $this->createErrorResponse($e, 'Ошибка при проксировании запроса');
        } catch (\Exception $e) {
            // Непредвиденные ошибки
            $this->logger->critical('Непредвиденная ошибка при проксировании запроса', [
                'message' => $e->getMessage(),
                'target' => $fullUrl,
                'class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new Response(
                500,
                ['Content-Type' => 'text/html'],
                $this->renderErrorPage(500, 'Внутренняя ошибка сервера', $this->config['app']['debug'] ? $e->getMessage() : null)
            );
        }
    }

    private function buildTargetUrl(string $baseUrl, string $path, ?string $queryString): string
    {
        // Удаляем завершающий слэш из базового URL, если он есть
        $baseUrl = rtrim($baseUrl, '/');
        
        // Добавляем начальный слэш к пути, если его нет
        $path = '/' . ltrim($path, '/');
        
        // Формируем полный URL
        $fullUrl = $baseUrl . $path;
        
        // Добавляем query-параметры, если они есть
        if ($queryString) {
            $fullUrl .= '?' . $queryString;
        }
        
        return $fullUrl;
    }

    private function createErrorResponse(RequestException $e, string $defaultMessage): Response
    {
        $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;
        $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
        
        // Если есть тело ответа и это HTML, возвращаем его
        if (!empty($responseBody) && strpos($e->getResponse()->getHeaderLine('Content-Type'), 'text/html') !== false) {
            return new Response(
                $statusCode,
                $this->headerManager->prepareResponseHeaders($e->getResponse()->getHeaders()),
                $responseBody
            );
        }
        
        // Иначе создаем страницу ошибки
        return new Response(
            $statusCode,
            ['Content-Type' => 'text/html'],
            $this->renderErrorPage($statusCode, $defaultMessage, $this->config['app']['debug'] ? $e->getMessage() : null)
        );
    }

    private function renderErrorPage(int $statusCode, string $title, ?string $details = null): string
    {
        $statusText = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout'
        ][$statusCode] ?? 'Error';
        
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>{$statusCode} {$statusText}</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        h1 {
            color: #d9534f;
            margin-top: 0;
        }
        .error-details {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            margin-top: 20px;
            border-left: 4px solid #d9534f;
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
        <h1>{$statusCode} {$statusText}</h1>
        <p>{$title}</p>
        
HTML;
        
        if ($details && $this->config['app']['debug']) {
            $html .= <<<HTML
        <div class="error-details">
            <strong>Детали ошибки:</strong>
            <p>{$details}</p>
        </div>
HTML;
        }
        
        $html .= <<<HTML
        <a href="/" class="back-link">← Вернуться на главную</a>
    </div>
</body>
</html>
HTML;
        
        return $html;
    }
}
