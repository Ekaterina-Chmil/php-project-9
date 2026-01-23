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
    $stmt = $pdo->query("SELECT * FROM urls ORDER BY id DESC");
    $urls = $stmt->fetchAll();

    $renderer = $container->get('view');
    return $renderer->render($response, 'urls.phtml', [
        'urls' => $urls
    ]);
});

// Запуск приложения
$app->run();
