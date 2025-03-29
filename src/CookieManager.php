<?php

namespace ReverseProxy;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;
use Monolog\Logger;
use Predis\Client as RedisClient;

class CookieManager
{
    private Logger $logger;
    private array $config;
    private RedisClient $redis;

    public function __construct(Logger $logger, array $config, RedisClient $redis)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->redis = $redis;
    }

    public function prepareRequestCookies(Request $request): array
    {
        $cookies = [];
        
        // Получаем все cookies из запроса
        foreach ($request->cookies->all() as $name => $value) {
            $cookies[$name] = $value;
        }
        
        // Логируем cookies
        $this->logger->debug('Подготовлены cookies запроса', [
            'cookies' => $cookies
        ]);
        
        // Сохраняем cookies в Redis для последующего использования
        $this->storeCookies($request->getClientIp(), $cookies);
        
        return $cookies;
    }

    public function processResponseCookies(array $headers, Response $response): void
    {
        // Обрабатываем заголовки Set-Cookie
        if (isset($headers['Set-Cookie'])) {
            $setCookies = is_array($headers['Set-Cookie']) ? $headers['Set-Cookie'] : [$headers['Set-Cookie']];
            
            foreach ($setCookies as $setCookie) {
                // Парсим заголовок Set-Cookie
                $cookieData = $this->parseCookie($setCookie);
                
                if ($cookieData) {
                    // Создаем объект Cookie
                    $cookie = new Cookie(
                        $cookieData['name'],
                        $cookieData['value'],
                        $cookieData['expires'] ? new \DateTime('@' . $cookieData['expires']) : 0,
                        $cookieData['path'],
                        $cookieData['domain'] ? '.' . $_SERVER['HTTP_HOST'] : null,
                        $cookieData['secure'],
                        $cookieData['httponly'],
                        false,
                        $cookieData['samesite'] ?: null
                    );
                    
                    // Добавляем cookie в ответ
                    $response->headers->setCookie($cookie);
                    
                    // Логируем cookie
                    $this->logger->debug('Обработана cookie из ответа', [
                        'name' => $cookieData['name'],
                        'domain' => $cookieData['domain'],
                        'path' => $cookieData['path'],
                        'expires' => $cookieData['expires']
                    ]);
                }
            }
        }
    }

    private function parseCookie(string $setCookie): ?array
    {
        // Разбиваем строку cookie на части
        $parts = explode(';', $setCookie);
        
        // Первая часть содержит имя и значение
        $nameValue = explode('=', $parts[0], 2);
        
        if (count($nameValue) !== 2) {
            return null;
        }
        
        $name = trim($nameValue[0]);
        $value = trim($nameValue[1]);
        
        // Инициализируем результат
        $result = [
            'name' => $name,
            'value' => $value,
            'expires' => 0,
            'path' => '/',
            'domain' => null,
            'secure' => false,
            'httponly' => false,
            'samesite' => null
        ];
        
        // Обрабатываем остальные атрибуты
        for ($i = 1; $i < count($parts); $i++) {
            $part = trim($parts[$i]);
            
            if (empty($part)) {
                continue;
            }
            
            // Обрабатываем атрибуты вида key=value
            if (strpos($part, '=') !== false) {
                list($key, $value) = explode('=', $part, 2);
                $key = strtolower(trim($key));
                $value = trim($value);
                
                if ($key === 'expires') {
                    $result['expires'] = strtotime($value);
                } elseif ($key === 'max-age') {
                    $result['expires'] = time() + (int)$value;
                } elseif ($key === 'path') {
                    $result['path'] = $value;
                } elseif ($key === 'domain') {
                    $result['domain'] = ltrim($value, '.');
                } elseif ($key === 'samesite') {
                    $result['samesite'] = $value;
                }
            } else {
                // Обрабатываем флаги
                $flag = strtolower($part);
                
                if ($flag === 'secure') {
                    $result['secure'] = true;
                } elseif ($flag === 'httponly') {
                    $result['httponly'] = true;
                }
            }
        }
        
        return $result;
    }

    private function storeCookies(string $clientIp, array $cookies): void
    {
        if (empty($cookies)) {
            return;
        }
        
        // Формируем ключ для хранения в Redis
        $key = 'cookies:' . md5($clientIp);
        
        // Сохраняем cookies в Redis
        $this->redis->hmset($key, $cookies);
        
        // Устанавливаем время жизни ключа (24 часа)
        $this->redis->expire($key, 86400);
        
        // Логируем сохранение cookies
        $this->logger->debug('Cookies сохранены в Redis', [
            'client_ip' => $clientIp,
            'count' => count($cookies)
        ]);
    }

    public function getCookies(string $clientIp): array
    {
        // Формируем ключ для получения из Redis
        $key = 'cookies:' . md5($clientIp);
        
        // Получаем cookies из Redis
        $cookies = $this->redis->hgetall($key);
        
        // Логируем получение cookies
        $this->logger->debug('Cookies получены из Redis', [
            'client_ip' => $clientIp,
            'count' => count($cookies)
        ]);
        
        return $cookies ?: [];
    }
}
