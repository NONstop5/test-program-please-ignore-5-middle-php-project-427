CREATE TABLE IF NOT EXISTS cities
(
    id      BIGSERIAL PRIMARY KEY,
    code    VARCHAR(10) NOT NULL UNIQUE,
    name    VARCHAR(255) NOT NULL,
    country VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS airlines
(
    id   BIGSERIAL PRIMARY KEY,
    code VARCHAR(10) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS flights
(
    id                BIGSERIAL PRIMARY KEY,
    airline_id        BIGINT NOT NULL REFERENCES airlines (id),
    flight_number     VARCHAR(20) NOT NULL,
    departure_city_id BIGINT NOT NULL REFERENCES cities (id),
    arrival_city_id   BIGINT NOT NULL REFERENCES cities (id),
    departure_at      TIMESTAMPTZ NOT NULL,
    arrival_at        TIMESTAMPTZ NOT NULL,
    duration_minutes  INTEGER NOT NULL CHECK (duration_minutes > 0),
    price             NUMERIC(12, 2) NOT NULL CHECK (price >= 0),
    available_seats   INTEGER NOT NULL CHECK (available_seats >= 0),
    CHECK (departure_city_id <> arrival_city_id),
    CHECK (arrival_at > departure_at)
);

CREATE TABLE IF NOT EXISTS bookings
(
    id            BIGSERIAL PRIMARY KEY,
    code          VARCHAR(20) NOT NULL UNIQUE,
    flight_id     BIGINT NOT NULL REFERENCES flights (id),
    status        VARCHAR(20) NOT NULL DEFAULT 'confirmed' CHECK (status IN ('confirmed', 'cancelled')),
    total_price   NUMERIC(12, 2) NOT NULL CHECK (total_price >= 0),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    contact_email VARCHAR(255) NOT NULL,
    contact_phone VARCHAR(50) NOT NULL
);

CREATE TABLE IF NOT EXISTS passengers
(
    id              BIGSERIAL PRIMARY KEY,
    booking_id      BIGINT NOT NULL REFERENCES bookings (id) ON DELETE CASCADE,
    first_name      VARCHAR(255) NOT NULL,
    last_name       VARCHAR(255) NOT NULL,
    date_of_birth   DATE NOT NULL,
    document_number VARCHAR(255) NOT NULL
);

CREATE INDEX IF NOT EXISTS flights_route_departure_idx
    ON flights (departure_city_id, arrival_city_id, departure_at);

CREATE INDEX IF NOT EXISTS bookings_flight_id_idx ON bookings (flight_id);
CREATE INDEX IF NOT EXISTS passengers_booking_id_idx ON passengers (booking_id);