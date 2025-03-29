const puppeteer = require('puppeteer');
const cheerio = require('cheerio');
const winston = require('winston');

// Создаем логгер
const logger = winston.createLogger({
    level: 'info',
    format: winston.format.combine(
        winston.format.timestamp(),
        winston.format.json()
    ),
    defaultMeta: { service: 'browser-module' },
    transports: [
        new winston.transports.File({ filename: '/var/log/node/browser-error.log', level: 'error' }),
        new winston.transports.File({ filename: '/var/log/node/browser.log' })
    ]
});

// Добавляем вывод в консоль в режиме разработки
if (process.env.NODE_ENV !== 'production') {
    logger.add(new winston.transports.Console({
        format: winston.format.simple()
    }));
}

// Хранилище для браузеров
const browsers = new Map();

// Функция для получения или создания браузера
async function getBrowser() {
    // Если браузер уже запущен, возвращаем его
    if (browsers.has('main') && browsers.get('main').browser) {
        return browsers.get('main').browser;
    }
    
    // Запускаем новый браузер
    logger.info('Запуск нового экземпляра браузера');
    
    const browser = await puppeteer.launch({
        executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium-browser',
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-accelerated-2d-canvas',
            '--no-first-run',
            '--no-zygote',
            '--disable-gpu',
            '--disable-web-security', // Отключаем проверку CORS
            '--disable-features=IsolateOrigins,site-per-process' // Отключаем изоляцию источников
        ],
        headless: true
    });
    
    // Сохраняем браузер в хранилище
    browsers.set('main', { 
        browser,
        lastUsed: Date.now()
    });
    
    // Обработчик закрытия браузера
    browser.on('disconnected', () => {
        logger.info('Браузер отключен');
        browsers.delete('main');
    });
    
    return browser;
}

// Функция для получения содержимого страницы
async function getPageContent(url) {
    logger.info(`Получение содержимого страницы: ${url}`);
    
    let browser;
    let page;
    
    try {
        // Получаем браузер
        browser = await getBrowser();
        
        // Создаем новую страницу
        page = await browser.newPage();
        
        // Настраиваем перехват запросов
        await setupRequestInterception(page);
        
        // Устанавливаем User-Agent
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        
        // Разрешаем JavaScript
        await page.setJavaScriptEnabled(true);
        
        // Устанавливаем размер окна
        await page.setViewport({ width: 1920, height: 1080 });
        
        // Отключаем кэш
        await page.setCacheEnabled(false);
        
        // Настраиваем обработку диалоговых окон
        page.on('dialog', async dialog => {
            logger.info(`Диалоговое окно: ${dialog.type()}, ${dialog.message()}`);
            await dialog.dismiss();
        });
        
        // Переходим на страницу
        await page.goto(url, {
            waitUntil: 'networkidle2',
            timeout: 30000
        });
        
        // Ждем, пока страница полностью загрузится
        await page.waitForTimeout(2000);
        
        // Получаем содержимое страницы
        const content = await page.content();
        
        // Получаем cookies
        const cookies = await page.cookies();
        
        // Получаем заголовки ответа
        const response = page.mainFrame().url() === url ? 
            await page.mainFrame()._client.send('Network.getResponseBody', { requestId: page.mainFrame()._id }) : 
            null;
        
        // Получаем скриншот
        const screenshot = await page.screenshot({ encoding: 'base64', type: 'jpeg', quality: 80 });
        
        // Парсим HTML с помощью cheerio
        const $ = cheerio.load(content);
        
        // Модифицируем ссылки
        $('a').each((i, el) => {
            const href = $(el).attr('href');
            if (href && href.startsWith('http')) {
                $(el).attr('href', `/proxy?url=${encodeURIComponent(href)}`);
            }
        });
        
        // Модифицируем формы
        $('form').each((i, el) => {
            const action = $(el).attr('action');
            if (action && action.startsWith('http')) {
                $(el).attr('action', `/proxy?url=${encodeURIComponent(action)}`);
            }
        });
        
        // Получаем модифицированный HTML
        const modifiedContent = $.html();
        
        // Возвращаем результат
        return {
            url: page.url(),
            content: modifiedContent,
            cookies,
            headers: response ? response.headers : {},
            screenshot: `data:image/jpeg;base64,${screenshot}`
        };
    } catch (err) {
        logger.error(`Ошибка при получении содержимого страницы: ${err.message}`, { stack: err.stack });
        throw err;
    } finally {
        // Закрываем страницу
        if (page) {
            await page.close();
        }
        
        // Обновляем время последнего использования браузера
        if (browsers.has('main')) {
            browsers.get('main').lastUsed = Date.now();
        }
    }
}

// Функция для выполнения JavaScript на странице
async function executeScript(url, script) {
    logger.info(`Выполнение скрипта на странице: ${url}`);
    
    let browser;
    let page;
    
    try {
        // Получаем браузер
        browser = await getBrowser();
        
        // Создаем новую страницу
        page = await browser.newPage();
        
        // Настраиваем перехват запросов
        await setupRequestInterception(page);
        
        // Устанавливаем User-Agent
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        
        // Разрешаем JavaScript
        await page.setJavaScriptEnabled(true);
        
        // Устанавливаем размер окна
        await page.setViewport({ width: 1920, height: 1080 });
        
        // Отключаем кэш
        await page.setCacheEnabled(false);
        
        // Настраиваем обработку диалоговых окон
        page.on('dialog', async dialog => {
            logger.info(`Диалоговое окно: ${dialog.type()}, ${dialog.message()}`);
            await dialog.dismiss();
        });
        
        // Переходим на страницу
        await page.goto(url, {
            waitUntil: 'networkidle2',
            timeout: 30000
        });
        
        // Ждем, пока страница полностью загрузится
        await page.waitForTimeout(2000);
        
        // Выполняем скрипт
        const result = await page.evaluate(script);
        
        // Получаем содержимое страницы после выполнения скрипта
        const content = await page.content();
        
        // Получаем cookies
        const cookies = await page.cookies();
        
        // Получаем скриншот
        const screenshot = await page.screenshot({ encoding: 'base64', type: 'jpeg', quality: 80 });
        
        // Возвращаем результат
        return {
            url: page.url(),
            result,
            content,
            cookies,
            screenshot: `data:image/jpeg;base64,${screenshot}`
        };
    } catch (err) {
        logger.error(`Ошибка при выполнении скрипта: ${err.message}`, { stack: err.stack });
        throw err;
    } finally {
        // Закрываем страницу
        if (page) {
            await page.close();
        }
        
        // Обновляем время последнего использования браузера
        if (browsers.has('main')) {
            browsers.get('main').lastUsed = Date.now();
        }
    }
}

// Функция для настройки перехвата запросов
async function setupRequestInterception(page) {
    await page.setRequestInterception(true);
    
    page.on('request', request => {
        // Модифицируем заголовки запроса
        const headers = request.headers();
        
        // Удаляем заголовки CORS
        delete headers['origin'];
        delete headers['sec-fetch-site'];
        delete headers['sec-fetch-mode'];
        delete headers['sec-fetch-dest'];
        
        // Разрешаем запрос
        request.continue({ headers });
    });
    
    page.on('response', response => {
        // Логируем ответы
        logger.debug(`Ответ: ${response.status()} ${response.url()}`);
    });
}

// Функция для очистки неиспользуемых браузеров
function cleanupBrowsers() {
    const now = Date.now();
    
    for (const [id, { browser, lastUsed }] of browsers.entries()) {
        // Если браузер не использовался более 30 минут, закрываем его
        if (now - lastUsed > 30 * 60 * 1000) {
            logger.info(`Закрытие неиспользуемого браузера: ${id}`);
            browser.close();
            browsers.delete(id);
        }
    }
}

// Запускаем периодическую очистку браузеров
setInterval(cleanupBrowsers, 5 * 60 * 1000);

// Экспортируем функции
module.exports = {
    getPageContent,
    executeScript
};
