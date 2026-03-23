<?php

$databaseUrl = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');

if (!$databaseUrl) {
    throw new \RuntimeException('DATABASE_URL environment variable is not set');
}

$parsedUrl = parse_url($databaseUrl);

$host = $parsedUrl['host'] ?? 'localhost';
$port = $parsedUrl['port'] ?? '5432';
$dbName = ltrim($parsedUrl['path'] ?? '', '/');
$username = $parsedUrl['user'] ?? '';
$password = $parsedUrl['pass'] ?? '';

$dsn = "pgsql:host={$host};port={$port};dbname={$dbName}";
if (getenv('APP_ENV') === 'production') {
    $dsn .= ';sslmode=require';
}

// Возвращаем ТОЛЬКО параметры (массив), без создания PDO
return [
    'dsn' => $dsn,
    'username' => $username,
    'password' => $password,
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // PDO будет кидать исключения сам
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
];
