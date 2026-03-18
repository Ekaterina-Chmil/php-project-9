-- Удалить таблицы 'urls', 'url_checks' если существуют
DROP TABLE IF EXISTS urls, url_checks CASCADE;

-- Создать таблицу 'urls'
CREATE TABLE IF NOT EXISTS urls (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT LOCALTIMESTAMP(0) NOT NULL
);

-- Создать таблицу 'url_checks'
CREATE TABLE IF NOT EXISTS url_checks (
    id SERIAL PRIMARY KEY,
    url_id INTEGER REFERENCES urls(id) NOT NULL,
    status_code INTEGER,
    h1 TEXT,
    title TEXT,
    description TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT LOCALTIMESTAMP(0) NOT NULL
);