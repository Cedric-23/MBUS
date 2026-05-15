-- MBus seed data (from local MySQL export)
-- Run after 01_schema.sql in Supabase SQL Editor

INSERT INTO users (user_id, full_name, email, phone_number, password, user_type) VALUES
(2, 'Caleb Bueno', 'cedyyygaming@gmail.com', '01234567890', '$2y$10$dxKRqZUVWziEpUItSp.j/uwsc4T.FfqWNPMbVTy7oIi7Z6R25obE.', 'Commuter'),
(8, 'Yana Bueno', 'Cedric23bueno@gmail.com', '09318340689', '$2y$10$/KFKGMlQVJubmzUmXRCbMes6PYJoctnKtzjSJEYmJvOdDS.V69W2i', 'Operator'),
(9, 'Cedric Bueno', '202410995@gordoncollege.edu.ph', '09518189487', '$2y$10$uwqu9DfylrdqFBkNp.BB9eig.uiV7rgyHhBEJI5H5S3EfZq67TgW2', 'admin');

INSERT INTO buses (bus_id, bus_number, bus_type, capacity, driver_id) VALUES
(1, 'BUS-101', 'Aircon', 28, NULL),
(2, 'BUS-102', 'Ordinary', 28, NULL),
(3, 'BUS-103', 'Ordinary', 28, NULL),
(4, 'BUS_23', 'Aircon', 28, NULL);

INSERT INTO routes (route_id, origin, destination, fare) VALUES
(1, 'Morong Terminal', 'SBMA', 75.00),
(2, 'SBMA', 'Morong Terminal', 75.00);

INSERT INTO schedule (schedule_id, bus_id, route, departure_time, arrival_time, schedule_status, route_id, trip_status) VALUES
(24, 1, '', '2026-05-05 10:32:00', '2026-05-05 11:32:00', 'Active', 1, 'Scheduled'),
(25, 1, '', '2026-05-05 20:00:00', '2026-05-05 21:00:00', 'Active', 1, 'Scheduled'),
(26, 2, '', '2026-05-06 19:13:00', '2026-05-06 20:13:00', 'Active', 2, 'Scheduled'),
(27, 3, '', '2026-05-07 20:13:00', '2026-05-07 22:13:00', 'Active', 1, 'Scheduled'),
(28, 4, '', '2026-05-08 19:13:00', '2026-05-08 21:14:00', 'Active', 2, 'Scheduled'),
(29, 4, '', '2026-05-14 20:14:00', '2026-05-14 20:14:00', 'Active', 2, 'Scheduled'),
(30, 3, '', '2026-05-17 20:14:00', '2026-05-17 20:14:00', 'Active', 1, 'Scheduled'),
(31, 4, '', '2026-05-05 11:15:00', '2026-05-05 12:15:00', 'Active', 1, 'Scheduled');

INSERT INTO operator_bus_assignments (assignment_id, operator_id, bus_id) VALUES
(1, 8, 1),
(2, 8, 2),
(3, 8, 4);

INSERT INTO reservation (reservation_id, user_id, schedule_id, seat_number, pickup_location, reservation_date, status, destination, created_at, ticket_code) VALUES
(178, 9, 24, '1', 'MABAYO', '2026-05-05 08:40:11', 'Cancelled', 'HARBOR POINT', '2026-05-05 00:40:11', '4041'),
(179, 9, 24, '2', 'MABAYO', '2026-05-05 08:40:11', 'Cancelled', 'HARBOR POINT', '2026-05-05 00:40:11', '8352'),
(180, 9, 24, '1', 'MINANGA', '2026-05-05 08:47:03', 'Boarded', 'TECHNO', '2026-05-05 00:47:03', '5123'),
(181, 9, 25, '8', 'MABAYO', '2026-05-05 19:17:33', 'Paid', 'MAIN GATE', '2026-05-05 11:17:33', '5177'),
(182, 9, 25, '1', 'APPAREL', '2026-05-05 19:20:17', 'Boarded', 'PETRON', '2026-05-05 11:20:17', '9681'),
(183, 9, 25, '3', 'APPAREL', '2026-05-05 19:20:17', 'Paid', 'PETRON', '2026-05-05 11:20:17', '5762'),
(184, 9, 25, '2', 'APPAREL', '2026-05-05 19:20:17', 'Paid', 'PETRON', '2026-05-05 11:20:17', '6462'),
(185, 9, 25, '4', 'APPAREL', '2026-05-05 19:20:17', 'Paid', 'PETRON', '2026-05-05 11:20:17', '2750'),
(186, 9, 25, '5', 'APPAREL', '2026-05-05 19:20:17', 'Paid', 'PETRON', '2026-05-05 11:20:17', '4293'),
(187, 9, 25, '6', 'APPAREL', '2026-05-05 19:20:17', 'Paid', 'PETRON', '2026-05-05 11:20:17', '5302'),
(188, 9, 25, '7', 'APPAREL', '2026-05-05 19:20:17', 'Paid', 'PETRON', '2026-05-05 11:20:17', '8140'),
(189, 9, 25, '9', 'APPAREL', '2026-05-05 19:20:17', 'Paid', 'PETRON', '2026-05-05 11:20:17', '5322'),
(190, 9, 25, '10', 'APPAREL', '2026-05-05 19:20:17', 'Boarded', 'PETRON', '2026-05-05 11:20:17', '5124'),
(191, 9, 25, '12', 'APPAREL', '2026-05-05 19:20:17', 'Paid', 'PETRON', '2026-05-05 11:20:17', '3437'),
(192, 9, 25, '11', 'APPAREL', '2026-05-05 19:20:17', 'Boarded', 'PETRON', '2026-05-05 11:20:17', '2834');

INSERT INTO payment (payment_id, reservation_id, amount, payment_method, payment_reference, payment_status, ticket_code, payment_date) VALUES
(23, 178, 44.98, 'GCash', '241234', 'Paid', 'MB-2946', '2026-05-05 08:47:10'),
(24, 181, 0.00, 'GCash', '423423', 'Paid', 'MB-8617', '2026-05-05 19:18:47'),
(25, 181, 298.89, 'GCash', '32124213', 'Paid', 'MB-2028', '2026-05-05 19:20:28');

-- Reset SERIAL sequences so new inserts get correct IDs
SELECT setval(pg_get_serial_sequence('users', 'user_id'), COALESCE((SELECT MAX(user_id) FROM users), 1));
SELECT setval(pg_get_serial_sequence('buses', 'bus_id'), COALESCE((SELECT MAX(bus_id) FROM buses), 1));
SELECT setval(pg_get_serial_sequence('routes', 'route_id'), COALESCE((SELECT MAX(route_id) FROM routes), 1));
SELECT setval(pg_get_serial_sequence('schedule', 'schedule_id'), COALESCE((SELECT MAX(schedule_id) FROM schedule), 1));
SELECT setval(pg_get_serial_sequence('reservation', 'reservation_id'), COALESCE((SELECT MAX(reservation_id) FROM reservation), 1));
SELECT setval(pg_get_serial_sequence('payment', 'payment_id'), COALESCE((SELECT MAX(payment_id) FROM payment), 1));
SELECT setval(pg_get_serial_sequence('operator_bus_assignments', 'assignment_id'), COALESCE((SELECT MAX(assignment_id) FROM operator_bus_assignments), 1));
