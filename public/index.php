<?php

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Slim\Flash\Messages;
use DI\Container;

require __DIR__ . '/../vendor/autoload.php';

// Запускаем сессию
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Загружаем переменные окружения
if (!isset($_ENV['DATABASE_URL'])) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Создаём контейнер
$container = new Container();

// Регистрируем зависимости
$container->set('connectionDB', function () {
    return require __DIR__ . '/../src/Connection.php';
});

$container->set('view', function () {
    return new PhpRenderer(__DIR__ . '/../templates');
});

$container->set('flash', function () {
    return new Messages();
});

// Устанавливаем контейнер для Slim
AppFactory::setContainer($container);

// Создаём приложение
$app = AppFactory::create();

// Главная страница
$app->get('/', function ($request, $response) use ($container) {
    $renderer = $container->get('view');
    $flash = $container->get('flash');

    return $renderer->render($response, 'home.phtml', [
        'flash' => $flash
    ]);
});

// Обработчик добавления URL
$app->post('/urls', function ($request, $response) use ($container) {
    $pdo = $container->get('connectionDB');
    $flash = $container->get('flash');

    // Получаем URL из формы
    $url = trim($request->getParsedBody()['url']['name'] ?? '');

    // Валидация: не пустой
    if (empty($url)) {
        $flash->addMessage('error', 'URL обязателен');
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    // Валидация: не длиннее 255 символов
    if (strlen($url) > 255) {
        $flash->addMessage('error', 'URL превышает 255 символов');
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    // Валидация: корректный URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $flash->addMessage('error', 'Некорректный URL');
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    // Проверка уникальности
    $stmt = $pdo->prepare("SELECT id FROM urls WHERE name = ?");
    $stmt->execute([$url]);
    if ($stmt->fetch()) {
        $flash->addMessage('info', 'Страница уже существует');
        return $response->withHeader('Location', '/urls')->withStatus(302);
    }

    // Сохраняем в БД
    $stmt = $pdo->prepare("INSERT INTO urls (name, created_at) VALUES (?, NOW())");
    $stmt->execute([$url]);

    $flash->addMessage('success', 'Страница успешно добавлена');
    return $response->withHeader('Location', '/urls')->withStatus(302);
});

// Страница списка сайтов
$app->get('/urls', function ($request, $response) use ($container) {
    $pdo = $container->get('connectionDB');
    $stmt = $pdo->query("
        SELECT 
            u.id,
            u.name,
            u.created_at,
            c.status_code,
            c.created_at as last_check
        FROM urls u
        LEFT JOIN (
            SELECT DISTINCT ON (url_id) *
            FROM url_checks
            ORDER BY url_id, id DESC
        ) c ON u.id = c.url_id
        ORDER BY u.id DESC
    ");
    $urls = $stmt->fetchAll();

    $renderer = $container->get('view');
    return $renderer->render($response, 'urls.phtml', [
        'urls' => $urls
    ]);
});

// Страница деталей сайта
$app->get('/urls/{id}', function ($request, $response, $args) use ($container) {
    $pdo = $container->get('connectionDB');
    $flash = $container->get('flash');

    // Получаем сайт
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = ?");
    $stmt->execute([$args['id']]);
    $url = $stmt->fetch();

    if (!$url) {
        return $response->withStatus(404);
    }

    // Получаем проверки
    $stmt = $pdo->prepare("SELECT * FROM url_checks WHERE url_id = ? ORDER BY id DESC");
    $stmt->execute([$args['id']]);
    $checks = $stmt->fetchAll();

    $renderer = $container->get('view');
    return $renderer->render($response, 'url.phtml', [
        'url' => $url,
        'checks' => $checks,
        'flash' => $flash
    ]);
});

// Обработчик проверки
$app->post('/urls/{id}/checks', function ($request, $response, $args) use ($container) {
    $pdo = $container->get('connectionDB');
    $flash = $container->get('flash');
    
    // Получаем URL
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = ?");
    $stmt->execute([$args['id']]);
    $url = $stmt->fetch();
    
    if (!$url) {
        return $response->withStatus(404);
    }
    
    // Выполняем HTTP-запрос
    try {
        $client = new \GuzzleHttp\Client(['timeout' => 10]);
        $guzzleResponse = $client->request('GET', $url['name']);
        $statusCode = $guzzleResponse->getStatusCode();
        
        // Сохраняем проверку с кодом ответа
        $stmt = $pdo->prepare("
            INSERT INTO url_checks (url_id, status_code, created_at) 
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$args['id'], $statusCode]);
        
        $flash->addMessage('success', 'Страница успешно проверена');
        
    } catch (\Exception $e) {
        // При ошибке — не создаём запись
        $flash->addMessage('error', 'Произошла ошибка при проверке');
    }
    
    return $response->withHeader('Location', "/urls/{$args['id']}")->withStatus(302);
});

// Запуск приложения
$app->run();
