# AGENTS.md

This file provides guidance to the AI agent when working with code in this repository.

- Отвечай всегда на Русском языке
- Run `make lint` and relevant manual checks for changes. There is no local test suite; `npm test` is a failing placeholder.
- `make install` installs PHP dependencies; `make validate` validates Composer metadata; `make lint-fix` rewrites PHP files in `public` and `src`.
- Follow PSR-12; PHPStan runs at level 8.
- PostgreSQL connection settings for the host are in `docker/.env`; the app's container connection is `DATABASE_URL` in `.env`. Examples are in the corresponding `.env.example` files. The hostname `db` is available inside Compose, not on the host.
- `database.sql` runs on every request; keep migrations idempotent.
- Compose maps host port 8080 to container port 8000, but `make start` defaults to port 8080. Check the port configuration before running the app.
- `make docker-down` removes Docker volumes (`down -v`).
- Do not edit, delete, or rename `.github/workflows/hexlet-check.yml`, or rename the repository. Hexlet runs external checks on pushes.
