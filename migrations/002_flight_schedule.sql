DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM flights WHERE price <> trunc(price))
        OR EXISTS (SELECT 1 FROM bookings WHERE total_price <> trunc(total_price)) THEN
        RAISE EXCEPTION 'Cannot convert fractional prices to whole rubles';
    END IF;
END $$;

ALTER TABLE flights ALTER COLUMN price TYPE INTEGER USING price::INTEGER;
ALTER TABLE bookings ALTER COLUMN total_price TYPE INTEGER USING total_price::INTEGER;

CREATE UNIQUE INDEX IF NOT EXISTS flights_route_departure_unique_idx
    ON flights (departure_city_id, arrival_city_id, departure_at);

CREATE INDEX IF NOT EXISTS flights_search_idx
    ON flights (departure_city_id, arrival_city_id, departure_at, available_seats);