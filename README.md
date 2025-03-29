# Reverse Proxy

Проект Reverse Proxy для перехвата и модификации HTTP-трафика между клиентом и целевым сервером. Позволяет обходить защитные механизмы CORS и HSTS, а также перехватывать cookies и другие данные пользователя.

## Схема работы

```
[Жертва] → [Фишинговый сайт (прокси) (может модифицировать контент)] → [Реальный сайт]
```

## Особенности

- Обход CORS через серверные запросы
- Обход HSTS через специальную конфигурацию Nginx и Puppeteer
- Перехват и модификация cookies
- Внедрение вредоносного JavaScript-кода
- Перехват форм и данных аутентификации
- Модификация контента (HTML, CSS, JavaScript)

## Технологический стек

- PHP 8.2 (Guzzle, Symfony HTTP Foundation, Monolog)
- Node.js 18+ (Puppeteer, Express)
- Nginx
- Redis
- Docker и Docker Compose

## Требования

- Docker и Docker Compose
- Git

## Установка на Ubuntu

1. Клонируйте репозиторий:

```bash
git clone https://github.com/yourusername/reverseproxy.git
cd reverseproxy
```

2. Запустите проект с помощью Docker Compose:

```bash
docker-compose up -d
```

3. Проверьте, что все контейнеры запущены:

```bash
docker-compose ps
```

4. Добавьте запись в файл `/etc/hosts` для локального тестирования:

```
127.0.0.1 reverseproxy.local
```

## Использование

1. Откройте в браузере `https://reverseproxy.local`
2. Введите URL целевого сайта в форму
3. Нажмите кнопку "Перейти"

Все запросы будут проксироваться через сервер, а контент будет модифицироваться согласно настройкам.

## Структура проекта

```
reverseproxy/
├── config/                 # Файлы конфигурации
│   ├── config.php          # Основная конфигурация PHP
│   └── nginx/              # Конфигурация Nginx
│       ├── nginx.conf
│       └── reverseproxy.conf
├── docker/                 # Файлы для Docker
│   ├── Dockerfile.nginx    # Сборка Nginx-контейнера
│   ├── Dockerfile.php      # Сборка PHP-контейнера
│   └── Dockerfile.node     # Сборка Node.js-контейнера
├── node/                   # Node.js сервис с Puppeteer
│   ├── browser.js          # Работа с Puppeteer
│   ├── package.json        # Зависимости
│   └── server.js           # Основной файл сервера
├── public/                 # Точка входа и клиентские скрипты
│   ├── index.php           # Точка входа
│   ├── js/                 # Клиентские JavaScript файлы
│   └── css/                # Стили
├── src/                    # Исходный код PHP-классов
│   ├── RequestHandler.php  # Обработка HTTP-запросов
│   ├── ResponseHandler.php # Обработка HTTP-ответов
│   ├── ContentModifier.php # Модификация контента
│   ├── CookieManager.php   # Управление cookies
│   └── HeaderManager.php   # Управление HTTP-заголовками
├── var/                    # Временные файлы и cookies
│   ├── cache/              # Кэш
│   ├── logs/               # Логи
│   └── sessions/           # Сессии
├── docker-compose.yml      # Конфигурация Docker Compose
├── composer.json           # Зависимости PHP
├── README.md               # Общая информация о проекте
├── tech.md                 # Описание технического задания
└── structure.md            # Структура проекта
```

## Настройка

Основные настройки проекта находятся в файле `config/config.php`. Здесь можно изменить:

- Целевой сайт по умолчанию
- Параметры модификации контента
- Настройки Redis
- Пути к директориям

## Логирование

Логи доступны в директории `var/logs/`:

- `app.log` - логи PHP-приложения
- `node/combined.log` - логи Node.js-сервиса
- `node/browser.log` - логи Puppeteer
- `nginx/access.log` - логи доступа Nginx
- `nginx/error.log` - логи ошибок Nginx

## Безопасность

**ВНИМАНИЕ!** Этот проект предназначен только для образовательных целей и тестирования безопасности с соответствующими разрешениями. Использование для атак на реальные системы без разрешения владельцев является незаконным.

## Лицензия

MIT
