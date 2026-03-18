<?php

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Slim\Flash\Messages;
use DI\Container;
use Valitron\Validator;

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
    // Используем константы: гибко и понятно
    $config = require __DIR__ . '/../src/Connection.php';

    // Создаём PDO здесь, в контейнере
    return new PDO(
        $config['dsn'],
        $config['username'],
        $config['password'],
        $config['options']
    );
});

$container->set('view', function () {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $renderer->setLayout('layout.phtml');  // ✅ Задаём лейаут ОДИН раз здесь!
    return $renderer;
});

$container->set('flash', function () {
    return new Messages();
});

// Устанавливаем контейнер для Slim
AppFactory::setContainer($container);

// Создаём приложение
$app = AppFactory::create();

$routeCollector = $app->getRouteCollector();
$router = $routeCollector->getRouteParser();

// ✅ Обработка ошибок
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$errorMiddleware->setDefaultErrorHandler(
    function (
        $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use (
        $container,
        $router
    ) {

    // Определяем статус код
        $statusCode = (int) $exception->getCode();
        if ($statusCode < 400 || $statusCode >= 600) {
            $statusCode = 500;
        }

    // Определяем сообщение
        $message = $exception->getMessage();
        if ($statusCode === 404) {
            $message = 'Страница не найдена';
        } elseif (empty($message) || $statusCode === 500) {
            $message = 'Произошла ошибка сервера';
        }

        // ✅ Берём настроенный рендерер из контейнера
        $renderer = $container->get('view');

        // Создаём ответ (или берём из аргументов, если есть)
        $response = new \Slim\Psr7\Response();

        return $renderer->render($response->withStatus($statusCode), 'error.phtml', [
            'statusCode' => $statusCode,
            'message' => $message,
            'router' => $router,
            'title' => 'Ошибка ' . $statusCode,
        ]);
    }
);

// Главная страница
$app->get('/', function ($request, $response) use ($container, $router) {
    $renderer = $container->get('view');
    $flash = $container->get('flash');

    return $renderer->render($response, 'home.phtml', [
        'flash' => $flash,
        'router' => $router,
        'title' => 'Главная - Анализатор страниц',
        'error' => null,
        'urlValue' => null,
    ]);
})->setName('home');

// Обработчик добавления URL
$app->post('/urls', function ($request, $response) use ($container, $router) {
        $pdo = $container->get('connectionDB');
        $flash = $container->get('flash');
        $renderer = $container->get('view');

    // 1️⃣ Получаем данные из формы
        $data = $request->getParsedBody()['url'] ?? [];

    // 2️⃣ ВАЛИДАЦИЯ через Valitron
        \Valitron\Validator::lang('ru');
        $v = new \Valitron\Validator($data);

        $v->rule('required', 'name')->message('URL не может быть пустым');
        $v->rule('lengthMax', 'name', 255)->message('URL слишком длинный');
        $v->rule('url', 'name')->message('Некорректный URL');

    if (!$v->validate()) {
        $errors = $v->errors();
        $error = $errors['name'][0] ?? 'Ошибка валидации';

        $renderer->setLayout('layout.phtml');
        return $renderer->render($response->withStatus(422), 'home.phtml', [
            'error' => $error, // Здесь будет конкретный текст (пусто, длинно или криво)
            'urlValue' => $data['name'] ?? '',
            'router' => $router,
            'title' => 'Ошибка - Анализатор страниц',
        ]);
    }

    // ✅ Валидация прошла — работаем с проверенными данными
    $url = strtolower(trim($data['name']));
    // 3️⃣ БИЗНЕС-ЛОГИКА: извлекаем хост и проверяем уникальность
    $parsedUrl = parse_url($url);
    $host = $parsedUrl['host'] ?? '';
    $stmt = $pdo->prepare("
        SELECT id FROM urls 
        WHERE SUBSTRING(name FROM '://([^/]+)') = ?
    ");
    $stmt->execute([$host]);
    $existingUrl = $stmt->fetch();
    if ($existingUrl) {
        $flash->addMessage('info', 'Страница уже существует');
        return $response->withHeader('Location', "/urls/{$existingUrl['id']}")->withStatus(302);
    }
    // 4️⃣ Сохраняем в БД
    $stmt = $pdo->prepare("INSERT INTO urls (name, created_at) VALUES (?, NOW())");
    $stmt->execute([$url]);
    $urlId = $pdo->lastInsertId();
    $flash->addMessage('success', 'Страница успешно добавлена');
    // 5️⃣ Редирект на страницу созданного сайта
    return $response->withHeader('Location', "/urls/{$urlId}")->withStatus(302);
})->setName('urls.store');

// Страница списка сайтов
$app->get('/urls', function ($request, $response) use ($container, $router) {
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

    return $renderer->render($response, 'urls/index.phtml', [
        'urls' => $urls,
        'flash' => $flash,
        'router' => $router,
        'title' => 'Сайты - Анализатор страниц',  // ✅ Свой заголовок

    ]);
})->setName('urls.index');

// Страница деталей сайта
$app->get('/urls/{id:[0-9]+}', function ($request, $response, $args) use ($container, $router) {
    $pdo = $container->get('connectionDB');
    $flash = $container->get('flash');

    // Получаем сайт
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = ?");
    $stmt->execute([$args['id']]);
    $url = $stmt->fetch();

    if (!$url) {
        throw new \Slim\Exception\HttpNotFoundException($request, 'Сайт не найден');
    }

    // Получаем проверки
    $stmt = $pdo->prepare("SELECT * FROM url_checks WHERE url_id = ? ORDER BY id DESC");
    $stmt->execute([$args['id']]);
    $checks = $stmt->fetchAll();

    $renderer = $container->get('view');
    return $renderer->render($response, 'urls/show.phtml', [
        'url' => $url,
        'checks' => $checks,
        'flash' => $flash,
        'router' => $router
    ]);
})->setName('urls.show');

// Обработчик проверки
$app->post('/urls/{id:[0-9]+}/checks', function ($request, $response, $args) use ($container) {
    $pdo = $container->get('connectionDB');
    $flash = $container->get('flash');

    // Получаем URL
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = ?");
    $stmt->execute([$args['id']]);
    $url = $stmt->fetch();

    if (!$url) {
        // ✅ Выбрасываем исключение — оно перехватится обработчиком ошибок!
        throw new \Slim\Exception\HttpNotFoundException($request, 'Сайт не найден');
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
        $h1 = $crawler->filter('h1')->first()->text();
        $title = $crawler->filter('title')->first()->text();
        $description = null;

        // Ищем meta description
        $metaDescription = $crawler->filter('meta[name="description"]')->first();
        if ($metaDescription->count()) {
            $description = $metaDescription->attr('content');
        }

        // ОБРЕЗКА текста если слишком длинный
        $maxLength = 100;

        if (strlen($h1) > $maxLength) {
            $h1 = mb_substr($h1, 0, $maxLength - 3) . '...';
        }

        if (strlen($title) > $maxLength) {
            $title = mb_substr($title, 0, $maxLength - 3) . '...';
        }

        if ($description && strlen($description) > $maxLength) {
            $description = mb_substr($description, 0, $maxLength - 3) . '...';
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
        $flash->addMessage('error', 'Произошла ошибка при проверке, не удалось подключиться');
    } catch (\Exception $e) {
        $flash->addMessage('error', 'Произошла ошибка при проверке, не удалось подключиться');
    }

    return $response->withHeader('Location', "/urls/{$args['id']}")->withStatus(302);
})->setName('urls.check');

// Запуск приложения
$app->run();
