#!/bin/bash

# Скрипт установки Reverse Proxy на Ubuntu

# Цвета для вывода
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Функция для вывода сообщений
log() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
    exit 1
}

# Проверка наличия sudo прав
if [ "$EUID" -ne 0 ]; then
    error "Этот скрипт должен быть запущен с правами суперпользователя (sudo)"
fi

# Проверка наличия необходимых утилит
log "Проверка наличия необходимых утилит..."
command -v docker >/dev/null 2>&1 || { error "Docker не установлен. Установите Docker и попробуйте снова."; }
command -v docker-compose >/dev/null 2>&1 || { error "Docker Compose не установлен. Установите Docker Compose и попробуйте снова."; }

# Установка необходимых пакетов
log "Установка необходимых пакетов..."
apt-get update
apt-get install -y curl git nano

# Настройка хоста
log "Настройка хоста..."
if ! grep -q "reverseproxy.local" /etc/hosts; then
    echo "127.0.0.1 reverseproxy.local" >> /etc/hosts
    log "Добавлена запись в /etc/hosts для reverseproxy.local"
else
    warn "Запись для reverseproxy.local уже существует в /etc/hosts"
fi

# Создание директорий для логов
log "Создание директорий для логов..."
mkdir -p var/logs/nginx var/logs/php var/logs/node

# Установка прав доступа
log "Установка прав доступа..."
chmod -R 755 .
chmod -R 777 var

# Запуск Docker Compose
log "Запуск Docker Compose..."
docker-compose down
docker-compose up -d

# Проверка статуса контейнеров
log "Проверка статуса контейнеров..."
docker-compose ps

# Установка зависимостей PHP через Composer
log "Установка зависимостей PHP через Composer..."
docker-compose exec php composer install

# Установка зависимостей Node.js
log "Установка зависимостей Node.js..."
docker-compose exec node npm install

# Вывод информации о доступе
log "Установка завершена!"
echo -e "${GREEN}Reverse Proxy успешно установлен и запущен!${NC}"
echo -e "Доступ к приложению:"
echo -e "- Основной интерфейс: ${YELLOW}https://reverseproxy.local${NC}"
echo -e "- Панель администратора: ${YELLOW}https://reverseproxy.local/admin.php${NC} (пароль: admin123)"
echo -e "- Логи доступны в директории: ${YELLOW}var/logs/${NC}"
echo -e "${YELLOW}Примечание:${NC} При первом доступе к HTTPS-сайту вы увидите предупреждение о небезопасном соединении."
echo -e "Это нормально, так как используется самоподписанный сертификат. Вы можете добавить исключение в браузере."
