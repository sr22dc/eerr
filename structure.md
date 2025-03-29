# Структура проекта Reverse Proxy

## Основные директории

- **config/** - файлы конфигурации
  - `config.php` - основная конфигурация PHP
  - `nginx.conf` - конфигурация Nginx
  - `reverseproxy.conf` - конфигурация виртуального хоста

- **public/** - точка входа и клиентские скрипты
  - `index.php` - точка входа
  - `js/` - клиентские JavaScript файлы
  - `css/` - стили

- **src/** - исходный код PHP-классов
  - `RequestHandler.php` - обработка HTTP-запросов
  - `ResponseHandler.php` - обработка HTTP-ответов
  - `ContentModifier.php` - модификация контента
  - `CookieManager.php` - управление cookies
  - `HeaderManager.php` - управление HTTP-заголовками

- **node/** - Node.js сервис с Puppeteer
  - `server.js` - основной файл сервера
  - `browser.js` - работа с Puppeteer
  - `package.json` - зависимости

- **var/** - временные файлы и cookies
  - `cache/` - кэш
  - `logs/` - логи
  - `sessions/` - сессии

- **docker/** - файлы для Docker
  - `Dockerfile.php` - сборка PHP-контейнера
  - `Dockerfile.node` - сборка Node.js-контейнера
  - `nginx/` - конфигурация Nginx для Docker

- **tests/** - тесты
  - `unit/` - модульные тесты
  - `integration/` - интеграционные тесты

## Основные файлы

- `docker-compose.yml` - конфигурация Docker Compose
- `composer.json` - зависимости PHP
- `tech.md` - описание технического задания
- `structure.md` - структура проекта (этот файл)
- `work.md` - текущие задачи и прогресс
- `README.md` - общая информация о проекте
