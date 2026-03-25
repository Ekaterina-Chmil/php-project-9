<?php

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Slim\Flash\Messages;
use DI\Container;
use Valitron\Validator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Dotenv\Dotenv;
use Slim\Psr7\Response;
use Slim\Exception\HttpNotFoundException;
use Symfony\Component\DomCrawler\Crawler;

require __DIR__ . '/../vendor/autoload.php';

// Запускаем сессию
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Загружаем переменные окружения
if (!isset($_ENV['DATABASE_URL'])) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
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

    // 1. Достаем код и сразу приводим к int, чтобы не было проблем с типами
        $code = (int) $exception->getCode();

    // 2. Проверяем диапазон один раз
        $statusCode = ($code < 400 || $code >= 600) ? 500 : $code;

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
        $response = new Response();

        // Теперь PHP точно знает, что здесь будет int
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
        Validator::lang('ru');
        $v = new Validator($data);

        $v->rule('required', 'name')->message('URL не может быть пустым');
        $v->rule('lengthMax', 'name', 255)->message('URL слишком длинный');
        $v->rule('url', 'name')->message('Некорректный URL');

    if (!$v->validate()) {
        $errors = $v->errors();
        $error = isset($errors['name']) ? reset($errors['name']) : 'Ошибка валидации';

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
    // Собираем чистый URL: схема (http/https) + хост (google.com)
    $scheme = isset($parsedUrl['scheme']) ? strtolower($parsedUrl['scheme']) : 'http';
    $host = isset($parsedUrl['host']) ? strtolower($parsedUrl['host']) : '';
    $normalizedUrl = "{$scheme}://{$host}";

    $stmt = $pdo->prepare("SELECT id FROM urls WHERE name = ?");
    $stmt->execute([$normalizedUrl]);
    $existingUrl = $stmt->fetch();

    if ($existingUrl) {
        $flash->addMessage('info', 'Страница уже существует');
        $urlToShow = $router->urlFor('urls.show', ['id' => $existingUrl['id']]);
        return $response->withRedirect($urlToShow);
    }

    // 4️⃣ Сохраняем в БД
    $stmt = $pdo->prepare("INSERT INTO urls (name, created_at) VALUES (?, NOW())");
    $stmt->execute([$normalizedUrl]);
    $urlId = $pdo->lastInsertId();

    $flash->addMessage('success', 'Страница успешно добавлена');

    // 5️⃣ Редирект на страницу созданного сайта
    $urlAfterCreate = $router->urlFor('urls.show', ['id' => $urlId]);
    return $response->withRedirect($urlAfterCreate);
})->setName('urls.store');

// Страница списка сайтов
$app->get('/urls', function ($request, $response) use ($container, $router) {
    $pdo = $container->get('connectionDB');
    $renderer = $container->get('view');
    $flash = $container->get('flash');

    // Получаем все сайты
    $urls = $pdo->query("SELECT id, name, created_at FROM urls ORDER BY id DESC")->fetchAll();

// Получаем последние проверки для каждого сайта (упрощенно)
    $sql = "SELECT DISTINCT ON (url_id) url_id, status_code, created_at 
                           FROM url_checks 
                           ORDER BY url_id, id DESC";
    $checks = $pdo->query($sql)->fetchAll();

// Превращаем проверки в удобный массив, где ключ — это id сайта
    $lastChecks = [];
    foreach ($checks as $check) {
        $lastChecks[$check['url_id']] = $check;
    }

    return $renderer->render($response, 'urls/index.phtml', [
        'urls' => $urls,
        'lastChecks' => $lastChecks,
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
        throw new HttpNotFoundException($request, 'Сайт не найден');
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
$app->post('/urls/{id:[0-9]+}/checks', function ($request, $response, $args) use ($container, $router) {
    $pdo = $container->get('connectionDB');
    $flash = $container->get('flash');

    // Получаем URL
    $stmt = $pdo->prepare("SELECT * FROM urls WHERE id = ?");
    $stmt->execute([$args['id']]);
    $url = $stmt->fetch();

    if (!$url) {
        // ✅ Выбрасываем исключение — оно перехватится обработчиком ошибок!
        throw new HttpNotFoundException($request, 'Сайт не найден');
    }

    // Выполняем HTTP-запрос
    try {
        $client = new Client(['timeout' => 5]);
        $guzzleResponse = $client->request('GET', $url['name']);
        $statusCode = $guzzleResponse->getStatusCode();
        $html = (string) $guzzleResponse->getBody();

        // Парсим HTML
        $crawler = new Crawler($html);

        // 1️⃣ БЕЗОПАСНО извлекаем данные
        $h1Node = $crawler->filter('h1');
        $h1 = $h1Node->count() ? $h1Node->first()->text() : null;

        $titleNode = $crawler->filter('title');
        $title = $titleNode->count() ? $titleNode->first()->text() : null;

        $description = null;

        // Ищем meta description
        $metaDescription = $crawler->filter('meta[name="description"]')->first();
        if ($metaDescription->count()) {
            $description = $metaDescription->attr('content');
        }

        // Сохраняем ПОЛНЫЕ данные в БД
        $stmt = $pdo->prepare("
            INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$args['id'], $statusCode, $h1, $title, $description]);

        $flash->addMessage('success', 'Страница успешно проверена');
    // 2️⃣ ОШИБКИ
    } catch (ConnectException | RequestException $e) {
        // Группируем два типа ошибок Guzzle в один блок
        $flash->addMessage('error', 'Произошла ошибка при проверке, не удалось подключиться');
    } catch (\Exception $e) {
        // Для всех остальных непредвиденных случаев
        $flash->addMessage('error', 'Произошла непредвиденная ошибка при анализе страницы');
    }

    return $response->withRedirect($router->urlFor('urls.show', ['id' => $args['id']]));
})->setName('urls.check');

// Запуск приложения
$app->run();
