-- MBus schema for Supabase (PostgreSQL)
-- Run in Supabase Dashboard → SQL Editor

CREATE TYPE notification_status AS ENUM ('Unread', 'Read');
CREATE TYPE payment_status AS ENUM ('Pending', 'Paid', 'Failed');
CREATE TYPE reservation_status AS ENUM ('Pending', 'Paid', 'Confirmed', 'Cancelled', 'Boarded');
CREATE TYPE schedule_status AS ENUM ('Active', 'Cancelled', 'Completed');

CREATE TABLE users (
    user_id SERIAL PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone_number VARCHAR(20),
    password VARCHAR(255) NOT NULL,
    user_type VARCHAR(50) NOT NULL
);

CREATE TABLE buses (
    bus_id SERIAL PRIMARY KEY,
    bus_number VARCHAR(50) NOT NULL,
    bus_type VARCHAR(50) NOT NULL,
    capacity INT NOT NULL,
    driver_id INT REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE routes (
    route_id SERIAL PRIMARY KEY,
    origin VARCHAR(100) NOT NULL,
    destination VARCHAR(100) NOT NULL,
    fare DECIMAL(10,2) NOT NULL
);

CREATE TABLE schedule (
    schedule_id SERIAL PRIMARY KEY,
    bus_id INT REFERENCES buses(bus_id) ON DELETE CASCADE,
    route VARCHAR(100) NOT NULL DEFAULT '',
    departure_time TIMESTAMP NOT NULL,
    arrival_time TIMESTAMP NOT NULL,
    schedule_status schedule_status DEFAULT 'Active',
    route_id INT NOT NULL REFERENCES routes(route_id),
    trip_status VARCHAR(50) DEFAULT 'Scheduled'
);

CREATE TABLE reservation (
    reservation_id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(user_id) ON DELETE CASCADE,
    schedule_id INT REFERENCES schedule(schedule_id) ON DELETE CASCADE,
    seat_number VARCHAR(10),
    pickup_location VARCHAR(100),
    reservation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status reservation_status DEFAULT 'Pending',
    destination VARCHAR(100),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ticket_code VARCHAR(20) NOT NULL
);

CREATE TABLE payment (
    payment_id SERIAL PRIMARY KEY,
    reservation_id INT REFERENCES reservation(reservation_id) ON DELETE CASCADE,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50),
    payment_reference VARCHAR(100) NOT NULL,
    payment_status payment_status DEFAULT 'Pending',
    ticket_code VARCHAR(30) NOT NULL,
    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE emergency_report (
    emergency_id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(user_id) ON DELETE CASCADE,
    reservation_id INT REFERENCES reservation(reservation_id) ON DELETE CASCADE,
    location VARCHAR(255),
    emergency_type VARCHAR(100),
    time_reported TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE feedback (
    feedback_id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(user_id) ON DELETE CASCADE,
    reservation_id INT REFERENCES reservation(reservation_id) ON DELETE CASCADE,
    rating INT,
    comment TEXT,
    schedule_id INT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE notification (
    notification_id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(user_id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    notification_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status notification_status DEFAULT 'Unread'
);

CREATE TABLE operator_bus_assignments (
    assignment_id SERIAL PRIMARY KEY,
    operator_id INT NOT NULL,
    bus_id INT NOT NULL
);

CREATE INDEX idx_buses_driver ON buses(driver_id);
CREATE INDEX idx_schedule_bus ON schedule(bus_id);
CREATE INDEX idx_schedule_route ON schedule(route_id);
CREATE INDEX idx_reservation_user ON reservation(user_id);
CREATE INDEX idx_reservation_schedule ON reservation(schedule_id);
CREATE INDEX idx_payment_reservation ON payment(reservation_id);
