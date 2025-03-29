<?php

namespace ReverseProxy;

use Symfony\Component\HttpFoundation\Request;
use Monolog\Logger;

class HeaderManager
{
    private Logger $logger;
    private array $config;
    
    // Заголовки, которые не должны проксироваться
    private array $excludedRequestHeaders = [
        'host', 'connection', 'content-length', 'content-md5', 
        'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto'
    ];
    
    // Заголовки, которые не должны передаваться клиенту
    private array $excludedResponseHeaders = [
        'transfer-encoding', 'connection', 'keep-alive', 'proxy-authenticate',
        'proxy-authorization', 'te', 'trailer', 'upgrade'
    ];

    public function __construct(Logger $logger, array $config)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    public function prepareRequestHeaders(Request $request): array
    {
        $headers = [];
        
        // Получаем все заголовки запроса
        foreach ($request->headers->all() as $name => $values) {
            // Пропускаем исключенные заголовки
            if (in_array(strtolower($name), $this->excludedRequestHeaders)) {
                continue;
            }
            
            // Добавляем заголовок
            $headers[$name] = $values[0];
        }
        
        // Добавляем X-Forwarded-* заголовки
        $headers['X-Forwarded-For'] = $request->getClientIp();
        $headers['X-Forwarded-Host'] = $request->getHost();
        $headers['X-Forwarded-Proto'] = $request->isSecure() ? 'https' : 'http';
        
        // Устанавливаем заголовок Host, если нужно сохранить оригинальный
        if ($this->config['proxy']['preserve_host'] && $request->headers->has('Host')) {
            $headers['Host'] = $request->headers->get('Host');
        }
        
        // Логируем заголовки
        $this->logger->debug('Подготовлены заголовки запроса', [
            'headers' => $headers
        ]);
        
        return $headers;
    }

    public function prepareResponseHeaders(array $headers): array
    {
        $processedHeaders = [];
        
        foreach ($headers as $name => $values) {
            // Пропускаем исключенные заголовки
            if (in_array(strtolower($name), $this->excludedResponseHeaders)) {
                continue;
            }
            
            // Обрабатываем заголовок Set-Cookie отдельно
            if (strtolower($name) === 'set-cookie') {
                // Set-Cookie обрабатывается в CookieManager
                continue;
            }
            
            // Обрабатываем заголовок Location для редиректов
            if (strtolower($name) === 'location') {
                foreach ($values as $value) {
                    // Если URL абсолютный, заменяем его
                    if (strpos($value, 'http') === 0) {
                        $processedHeaders[$name] = '/proxy?url=' . urlencode($value);
                    } else {
                        $processedHeaders[$name] = $value;
                    }
                }
                continue;
            }
            
            // Обрабатываем заголовок Content-Security-Policy
            if (strtolower($name) === 'content-security-policy') {
                // Модифицируем CSP для разрешения нашего контента
                foreach ($values as $value) {
                    $processedHeaders[$name] = $this->modifyContentSecurityPolicy($value);
                }
                continue;
            }
            
            // Добавляем остальные заголовки
            $processedHeaders[$name] = is_array($values) ? $values[0] : $values;
        }
        
        // Добавляем заголовок X-Proxy
        $processedHeaders['X-Proxy'] = 'ReverseProxy';
        
        // Логируем заголовки
        $this->logger->debug('Подготовлены заголовки ответа', [
            'headers' => $processedHeaders
        ]);
        
        return $processedHeaders;
    }

    private function modifyContentSecurityPolicy(string $csp): string
    {
        // Разбиваем CSP на директивы
        $directives = explode(';', $csp);
        $modifiedDirectives = [];
        
        foreach ($directives as $directive) {
            $directive = trim($directive);
            
            if (empty($directive)) {
                continue;
            }
            
            // Разбиваем директиву на имя и значения
            $parts = explode(' ', $directive, 2);
            $name = $parts[0];
            $values = isset($parts[1]) ? $parts[1] : '';
            
            // Модифицируем директивы, связанные с источниками
            if (in_array($name, ['default-src', 'script-src', 'style-src', 'img-src', 'connect-src', 'font-src', 'media-src', 'object-src', 'frame-src', 'worker-src', 'manifest-src'])) {
                // Добавляем 'self' и 'unsafe-inline' для разрешения нашего контента
                if (strpos($values, "'self'") === false) {
                    $values .= " 'self'";
                }
                
                if (strpos($values, "'unsafe-inline'") === false && in_array($name, ['script-src', 'style-src'])) {
                    $values .= " 'unsafe-inline'";
                }
                
                if (strpos($values, "'unsafe-eval'") === false && $name === 'script-src') {
                    $values .= " 'unsafe-eval'";
                }
            }
            
            // Собираем модифицированную директиву
            $modifiedDirectives[] = $name . ' ' . $values;
        }
        
        // Собираем модифицированный CSP
        return implode('; ', $modifiedDirectives);
    }
}
