<?php

// Убедимся, что DATABASE_URL существует
if (!isset($_ENV['DATABASE_URL']) || empty($_ENV['DATABASE_URL'])) {
    die('Ошибка: переменная окружения DATABASE_URL не установлена.');
}

// Парсим DATABASE_URL
$databaseUrl = parse_url($_ENV['DATABASE_URL']);
$username = $databaseUrl['user'] ?? 'ekaterinachmil';
$password = $databaseUrl['pass'] ?? '';
$host = $databaseUrl['host'] ?? 'localhost';
$port = $databaseUrl['port'] ?? '5432';
$dbName = ltrim($databaseUrl['path'] ?? '', '/');

// Создаём PDO-соединение
try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbName",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

return $pdo;
