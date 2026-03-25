# Анализатор страниц

Сервис для мониторинга доступности веб-страниц. Анализирует сайты по URL, проверяет их доступность и собирает метаданные (заголовок h1, title, description).

### Hexlet tests and linter status:
[![Actions Status](https://github.com/Ekaterina-Chmil/php-project-9/actions/workflows/hexlet-check.yml/badge.svg)](https://github.com/Ekaterina-Chmil/php-project-9/actions) [![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=Ekaterina-Chmil_php-project-9&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=Ekaterina-Chmil_php-project-9)

Демо: [https://php-project-9-iz48.onrender.com](https://php-project-9-iz48.onrender.com)

## Системные требования

- PHP 8.3 или выше
- PostgreSQL 14 или выше
- Composer
- Docker и Docker Compose (для запуска в контейнере)

## Установка

1. Клонируйте репозиторий:
```bash
git clone https://github.com/Ekaterina-Chmil/php-project-9.git
cd php-project-9
```

2. Установите зависимости:
```bash
make install
```

3. Настройте базу данных (переменная окружения DATABASE_URL):
```bash
make migrate
```

4. Запустите сервер:
```bash
make start
```

5. Откройте в браузере:
http://localhost:8000

## Использование

1. На главной странице введите URL сайта (например, https://hexlet.io)
2. Нажмите «Добавить» — сайт сохранится в базе
3. На странице сайта нажмите «Запустить проверку» — сервис проверит доступность и сохранит метаданные

## Технологии

- PHP 8.3
- Slim Framework
- PostgreSQL
- Twig/PHP Renderer
- Bootstrap 5
- Playwright (тесты)
