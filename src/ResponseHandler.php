<?php

namespace ReverseProxy;

use Symfony\Component\HttpFoundation\Response;
use Monolog\Logger;

class ResponseHandler
{
    private ContentModifier $contentModifier;
    private Logger $logger;
    private array $config;

    public function __construct(
        ContentModifier $contentModifier,
        Logger $logger,
        array $config
    ) {
        $this->contentModifier = $contentModifier;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function handleResponse(Response $response): Response
    {
        // Проверяем, нужно ли модифицировать контент
        if (!$this->shouldModifyContent($response)) {
            return $response;
        }

        // Получаем контент ответа
        $content = $response->getContent();

        // Модифицируем контент
        $modifiedContent = $this->contentModifier->modify($content, $response->headers->get('Content-Type'));

        // Устанавливаем модифицированный контент
        $response->setContent($modifiedContent);

        // Обновляем заголовок Content-Length
        $response->headers->set('Content-Length', strlen($modifiedContent));

        // Логируем модификацию
        $this->logger->info('Контент ответа модифицирован', [
            'content_type' => $response->headers->get('Content-Type'),
            'original_size' => strlen($content),
            'modified_size' => strlen($modifiedContent)
        ]);

        return $response;
    }

    private function shouldModifyContent(Response $response): bool
    {
        // Проверяем, включена ли модификация контента
        if (!$this->config['content_modifier']['enabled']) {
            return false;
        }

        // Получаем Content-Type
        $contentType = $response->headers->get('Content-Type', '');

        // Проверяем, является ли контент HTML, CSS или JavaScript
        return (
            strpos($contentType, 'text/html') !== false ||
            strpos($contentType, 'text/css') !== false ||
            strpos($contentType, 'application/javascript') !== false ||
            strpos($contentType, 'text/javascript') !== false
        );
    }
}
