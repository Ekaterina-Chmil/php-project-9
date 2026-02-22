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
        $renderer = $container->get('view');
        return $renderer->render(
            $response->withStatus(422),
            'home.phtml',
            [
            'error' => 'Некорректный URL',
            'urlValue' => $url
            ]
        );
    }

    // Валидация: не длиннее 255 символов
    if (strlen($url) > 255) {
        $renderer = $container->get('view');
        return $renderer->render(
            $response->withStatus(422),
            'home.phtml',
            [
            'error' => 'Некорректный URL',
            'urlValue' => $url
            ]
        );
    }

    // Валидация: корректный URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $renderer = $container->get('view');
        return $renderer->render(
            $response->withStatus(422),
            'home.phtml',
            [
            'error' => 'Некорректный URL',
            'urlValue' => $url
            ]
        );
    }

    // Проверка уникальности
    $stmt = $pdo->prepare("SELECT id FROM urls WHERE name = ?");
    $stmt->execute([$url]);
    $existingUrl = $stmt->fetch(); // ← Сохраняем результат

    if ($existingUrl) {
        $flash->addMessage('info', 'Страница уже существует');
        return $response->withHeader('Location', "/urls/{$existingUrl['id']}")->withStatus(302);
    }

    // Сохраняем в БД
    $stmt = $pdo->prepare("INSERT INTO urls (name, created_at) VALUES (?, NOW())");
    $stmt->execute([$url]);

    // Получаем ID только что вставленной записи
    $urlId = $pdo->lastInsertId();

    $flash->addMessage('success', 'Страница успешно добавлена');
    return $response->withHeader('Location', "/urls/{$urlId}")->withStatus(302);
});

// Страница списка сайтов
$app->get('/urls', function ($request, $response) use ($container) {
    $pdo = $container->get('connectionDB');
    $renderer = $container->get('view');
    $flash = $container->get('flash');

    // Получаем все сайты
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

    return $renderer->render($response, 'urls.phtml', [
        'urls' => $urls,
        'flash' => $flash
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
        $client = new \GuzzleHttp\Client([
            'timeout' => 5,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; PageAnalyzer/1.0)'
            ]
        ]);
        $guzzleResponse = $client->request('GET', $url['name']);
        $statusCode = $guzzleResponse->getStatusCode();
        $html = (string) $guzzleResponse->getBody();

        // Парсим HTML
        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);

        // Извлекаем данные
        $h1 = $crawler->filter('h1')->first()->text() ?? null;
        $title = $crawler->filter('title')->first()->text() ?? null;
        $description = null;

        // Ищем meta description
        $metaDescription = $crawler->filter('meta[name="description"]')->first();
        if ($metaDescription->count()) {
            $description = $metaDescription->attr('content');
        }

        // Сохраняем всё в БД
        $stmt = $pdo->prepare("
            INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $args['id'],
            $statusCode,
            $h1,
            $title,
            $description
        ]);

        $flash->addMessage('success', 'Страница успешно проверена');
    } catch (\GuzzleHttp\Exception\ConnectException $e) {
        $flash->addMessage('error', 'Произошла ошибка при проверке');
    } catch (\Exception $e) {
        $flash->addMessage('error', 'Произошла ошибка при проверке');
    }

    return $response->withHeader('Location', "/urls/{$args['id']}")->withStatus(302);
});

// Запуск приложения
$app->run();
