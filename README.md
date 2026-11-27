# Бекенд для бронирования авиабилетов (PHP)

[![hexlet-check](https://github.com/NONstop5/test-program-please-ignore-5-middle-php-project-427/actions/workflows/hexlet-check.yml/badge.svg)](https://github.com/NONstop5/test-program-please-ignore-5-middle-php-project-427/actions)
[![PHP CI](https://github.com/NONstop5/test-program-please-ignore-5-middle-php-project-427/actions/workflows/main.yml/badge.svg)](https://github.com/NONstop5/test-program-please-ignore-5-middle-php-project-427/actions/workflows/main.yml)

Реализуйте бекенд сервиса бронирования авиабилетов: справочник городов, поиск рейсов,
оформление, просмотр и отмену брони. Фреймворк выбираете сами, данные храните в PostgreSQL.
Фронтенд предоставляет Хекслет — готовое приложение, которое подключается к вашему API
и работает только тогда, когда API отвечает по описанному контракту.

Учебный проект Хекслета: https://ru.hexlet.io/programs/test-program-please-ignore-5-middle-php
Как это должно работать: https://files.hexlet.app/a/5bi6gu

## Стек

- PHP

## Установка

Необходимы PHP 8.2 с расширением PDO PostgreSQL, Composer, Node.js 26 и PostgreSQL.

```bash
git clone https://github.com/NONstop5/test-program-please-ignore-5-middle-php-project-427.git
cd test-program-please-ignore-5-middle-php-project-427
make install
make build
```

Настройте `DATABASE_URL` в окружении или в `.env` по образцу `.env.example`.
При запуске PHP на хосте используйте адрес и порт PostgreSQL на хосте; имя `db`
доступно только внутри Docker Compose. Секреты в репозиторий не добавляются.

## Использование

```bash
make start
```

При каждом запуске применяются миграции и дополняется расписание на 30 дней
от текущей даты UTC. Существующие брони и количество доступных мест сохраняются.
Миграции хранятся в `migrations/` и применяются в порядке, заданном в `src/Migrations.php`;
`database.sql` сохранён как первоначальная версия схемы.
Фронтенд и API доступны на порту 8080 (или на порту из `PORT`).

```bash
make lint
make test
```

Для тестов задайте `DATABASE_URL` или отдельный `TEST_DATABASE_URL` с адресом
PostgreSQL, доступным из окружения запуска. Тесты создают временные схемы,
поэтому пользователю базы нужны права на их создание.

---

<details>
<summary>Автоматические тесты Хекслета</summary>

Тесты запускаются на каждый коммит. За запуск отвечает файл `.github/workflows/hexlet-check.yml` — не удаляйте и не переименовывайте ни его, ни репозиторий.

</details>

## О Хекслете

[Хекслет](https://ru.hexlet.io/) — школа программирования: авторские программы обучения с практикой, поддержкой наставников и реальными проектами, которые остаются в резюме. Этот репозиторий — один из таких проектов.
