-- Blue Team: Jonah Aney, Justin Marucci, Nardos Gabremedhin, Amanda Wedergren

CREATE DATABASE IF NOT EXISTS moffat_bay
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;
USE moffat_bay;

DROP USER IF EXISTS 'marina_user'@'localhost';
CREATE USER 'marina_user'@'localhost'
  IDENTIFIED BY 'moffatbaymarina';
GRANT ALL PRIVILEGES ON moffat_bay.* TO 'marina_user'@'localhost';
FLUSH PRIVILEGES;

-- EMPLOYEES
CREATE TABLE IF NOT EXISTS employees (
employee_ID INT AUTO_INCREMENT PRIMARY KEY,
email VARCHAR(255) NOT NULL UNIQUE,
password VARCHAR(255) NOT NULL,
first_name VARCHAR(100) NOT NULL,
last_name VARCHAR(100) NOT NULL,
position VARCHAR(100),
date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- USERS
CREATE TABLE IF NOT EXISTS users (
user_ID INT AUTO_INCREMENT PRIMARY KEY,
email VARCHAR(255) NOT NULL UNIQUE,
password VARCHAR(255) NOT NULL,
first_name VARCHAR(100) NOT NULL,
last_name VARCHAR(100) NOT NULL,
phone VARCHAR(50),
date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
last_modified_by INT NULL,
last_modified_at TIMESTAMP NULL,
CONSTRAINT fk_users_last_modified_by FOREIGN KEY (last_modified_by) REFERENCES employees(employee_ID) ON DELETE SET NULL
) ;

-- BOATS
CREATE TABLE IF NOT EXISTS boats (
boat_ID INT AUTO_INCREMENT PRIMARY KEY,
user_ID INT NOT NULL,
boat_name VARCHAR(255) NOT NULL,
boat_length INT NOT NULL,
date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
CONSTRAINT fk_boats_user FOREIGN KEY (user_ID) REFERENCES users(user_ID) ON DELETE CASCADE
) ;

-- SLIPS
CREATE TABLE IF NOT EXISTS slips (
slip_ID INT AUTO_INCREMENT PRIMARY KEY,
slip_size VARCHAR(10) NOT NULL,
is_available BOOLEAN NOT NULL DEFAULT TRUE,
location_code VARCHAR(20) NOT NULL
) ;

-- RESERVATIONS
CREATE TABLE IF NOT EXISTS reservations (
reservation_ID INT AUTO_INCREMENT PRIMARY KEY,
confirmation_number VARCHAR(100) UNIQUE,
user_ID INT NOT NULL,
boat_ID INT NULL,
slip_ID INT NULL,
start_date DATE NOT NULL,
end_date DATE NOT NULL,
months_duration INT NULL,
total_cost DECIMAL(10,2) DEFAULT 0.00,
reservation_status ENUM('confirmed','canceled','checked_in') NOT NULL DEFAULT 'confirmed',
checked_in_by INT NULL,
checked_in_at TIMESTAMP NULL,
date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
last_modified_by INT NULL,
last_modified_at TIMESTAMP NULL,
CONSTRAINT fk_reservations_user FOREIGN KEY (user_ID) REFERENCES users(user_ID) ON DELETE CASCADE,
CONSTRAINT fk_reservations_boat FOREIGN KEY (boat_ID) REFERENCES boats(boat_ID) ON DELETE SET NULL,
CONSTRAINT fk_reservations_slip FOREIGN KEY (slip_ID) REFERENCES slips(slip_ID) ON DELETE SET NULL,
CONSTRAINT fk_reservations_checked_in_by FOREIGN KEY (checked_in_by) REFERENCES employees(employee_ID) ON DELETE SET NULL,
CONSTRAINT fk_reservations_last_modified_by FOREIGN KEY (last_modified_by) REFERENCES employees(employee_ID) ON DELETE SET NULL
) ;

-- WAITLIST
CREATE TABLE IF NOT EXISTS waitlist (
waitlist_ID INT AUTO_INCREMENT PRIMARY KEY,
user_ID INT NOT NULL,
boat_ID INT NULL,
preferred_slip_size VARCHAR(10) NULL,
preferred_start_date DATE NULL,
preferred_end_date DATE NULL,
months_duration INT NULL,
position_in_queue INT NOT NULL DEFAULT 1,
date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
CONSTRAINT fk_waitlist_user FOREIGN KEY (user_ID) REFERENCES users(user_ID) ON DELETE CASCADE,
CONSTRAINT fk_waitlist_boat FOREIGN KEY (boat_ID) REFERENCES boats(boat_ID) ON DELETE SET NULL
) ;


DROP TRIGGER IF EXISTS trg_reservations_before_update;
DELIMITER $$
CREATE TRIGGER trg_reservations_before_update
BEFORE UPDATE ON reservations
FOR EACH ROW
BEGIN
DECLARE new_days INT;
DECLARE old_days INT;
SET new_days = DATEDIFF(NEW.end_date, NEW.start_date);
SET old_days = DATEDIFF(OLD.end_date, OLD.start_date);

IF ((NEW.checked_in_by IS NOT NULL OR NEW.checked_in_at IS NOT NULL) AND (OLD.reservation_status <> 'checked_in')) THEN
SET NEW.reservation_status = 'checked_in';
IF NEW.checked_in_at IS NULL THEN
SET NEW.checked_in_at = CURRENT_TIMESTAMP;
END IF;
SET NEW.last_modified_at = CURRENT_TIMESTAMP;
END IF;

IF (NEW.total_cost <> OLD.total_cost) AND (new_days = old_days) THEN
SET NEW.total_cost = OLD.total_cost;
END IF;

IF (NEW.last_modified_by IS NOT NULL) THEN
SET NEW.last_modified_at = CURRENT_TIMESTAMP;
END IF;
END$$
DELIMITER ;

-- =====================
-- Seed data (3+ rows per table)
-- =====================

-- Employees
INSERT INTO employees (employee_ID,email,password,first_name,last_name,position,date_created) VALUES
(1,'eve.tucker@example.com','<hashed_pw>','Eve','Tucker','manager','2026-01-01 08:00:00'),
(2,'charlie.roberts@example.com','<hashed_pw>','Charlie','Roberts','dockhand','2026-01-02 08:30:00'),
(3,'sam.lewis@example.com','<hashed_pw>','Sam','Lewis','supervisor','2026-01-03 09:00:00');

-- Users
INSERT INTO users (user_ID,email,password,first_name,last_name,phone,date_created,last_modified_by,last_modified_at) VALUES
(1,'alice@example.com','<hashed_pw>','Alice','Smith','555-123-0100','2026-01-01 09:00:00',NULL,NULL),
(2,'bob@example.com','<hashed_pw>','Bob','Jones','555-456-0101','2026-01-02 10:00:00',1,'2026-02-01 10:15:00'),
(3,'carol@example.com','<hashed_pw>','Carol','Adams','555-890-0102','2026-01-03 11:00:00',NULL,NULL),
(4,'lisa@example.com','<hashed_pw>','Lisa','Luke','555-345-0107','2026-01-02 10:00:00',1,'2026-02-01 10:15:00');

-- Slips (full dataset provided)
INSERT INTO slips (slip_ID, slip_size, is_available, location_code) VALUES
(1, '26', TRUE, 'A-08'),
(2, '26', TRUE, 'A-09'),
(3, '26', TRUE, 'A-10'),
(4, '26', TRUE, 'A-11'),
(5, '26', TRUE, 'A-12'),
(6, '26', TRUE, 'A-20'),
(7, '26', TRUE, 'A-21'),
(8, '26', TRUE, 'A-22'),
(9, '26', TRUE, 'A-23'),
(10, '26', TRUE, 'A-24'),
(11, '40', TRUE, 'A-04'),
(12, '40', TRUE, 'A-05'),
(13, '40', TRUE, 'A-06'),
(14, '40', TRUE, 'A-07'),
(15, '40', TRUE, 'A-16'),
(16, '40', TRUE, 'A-17'),
(17, '40', TRUE, 'A-18'),
(18, '40', TRUE, 'A-19'),
(19, '50', TRUE, 'A-01'),
(20, '50', TRUE, 'A-02'),
(21, '50', TRUE, 'A-03'),
(22, '50', TRUE, 'A-13'),
(23, '50', TRUE, 'A-14'),
(24, '50', TRUE, 'A-15'),
(25, '26', TRUE, 'B-08'),
(26, '26', TRUE, 'B-09'),
(27, '26', TRUE, 'B-10'),
(28, '26', TRUE, 'B-11'),
(29, '26', TRUE, 'B-12'),
(30, '26', TRUE, 'B-20'),
(31, '26', TRUE, 'B-21'),
(32, '26', TRUE, 'B-22'),
(33, '26', TRUE, 'B-23'),
(34, '26', TRUE, 'B-24'),
(35, '40', TRUE, 'B-04'),
(36, '40', TRUE, 'B-05'),
(37, '40', TRUE, 'B-06'),
(38, '40', TRUE, 'B-07'),
(39, '40', TRUE, 'B-16'),
(40, '40', TRUE, 'B-17'),
(41, '40', TRUE, 'B-18'),
(42, '40', TRUE, 'B-19'),
(43, '50', TRUE, 'B-01'),
(44, '50', TRUE, 'B-02'),
(45, '50', TRUE, 'B-03'),
(46, '50', TRUE, 'B-13'),
(47, '50', TRUE, 'B-14'),
(48, '50', TRUE, 'B-15'),
(49, '26', TRUE, 'C-08'),
(50, '26', TRUE, 'C-09'),
(51, '26', TRUE, 'C-10'),
(52, '26', TRUE, 'C-11'),
(53, '26', TRUE, 'C-12'),
(54, '26', TRUE, 'C-20'),
(55, '26', TRUE, 'C-21'),
(56, '26', TRUE, 'C-22'),
(57, '26', TRUE, 'C-23'),
(58, '26', TRUE, 'C-24'),
(59, '40', TRUE, 'C-04'),
(60, '40', TRUE, 'C-05'),
(61, '40', TRUE, 'C-06'),
(62, '40', TRUE, 'C-07'),
(63, '40', TRUE, 'C-16'),
(64, '40', TRUE, 'C-17'),
(65, '40', TRUE, 'C-18'),
(66, '40', TRUE, 'C-19'),
(67, '50', TRUE, 'C-01'),
(68, '50', TRUE, 'C-02'),
(69, '50', TRUE, 'C-03'),
(70, '50', TRUE, 'C-13'),
(71, '50', TRUE, 'C-14'),
(72, '50', TRUE, 'C-15');

-- Boats
INSERT INTO boats (boat_ID,user_ID,boat_name,boat_length) VALUES
(1,1,'Black Pearl',34),
(2,2,'Interceptor',22),
(3,3,'Flying Dutchman',48),
(4,1,'Charlotte',25),
(5,3,'The Kraken',42),
(6,3,'Mischievious',49),
(7,2,'Journey',28),
(8,4,'Tinman',10);

-- Reservations
INSERT INTO reservations (reservation_ID,confirmation_number,user_ID,boat_ID,slip_ID,start_date,end_date,months_duration,total_cost,reservation_status,date_created) VALUES
(1,'CONF-0001',1,1,59,'2026-02-10','2026-04-10',2,700.00,'confirmed','2026-01-10 12:00:00');

INSERT INTO reservations (reservation_ID,confirmation_number,user_ID,boat_ID,slip_ID,start_date,end_date,months_duration,total_cost,reservation_status,checked_in_by,checked_in_at,date_created,last_modified_by,last_modified_at) VALUES
(2,'CONF-0002',2,2,29,'2026-01-15','2026-02-15',1,230.00,'checked_in',2,'2026-01-15 09:30:00','2026-01-05 09:00:00',2,'2026-01-15 09:30:00');

INSERT INTO reservations (reservation_ID,confirmation_number,user_ID,boat_ID,slip_ID,start_date,end_date,months_duration,total_cost,reservation_status,date_created,last_modified_by,last_modified_at) VALUES
(3,'CONF-0003',3,3,19,'2026-03-01','2026-04-01',1,490.00,'canceled','2026-01-20 10:00:00',3,'2026-02-20 12:00:00');

INSERT INTO reservations (reservation_ID,confirmation_number,user_ID,boat_ID,slip_ID,start_date,end_date,months_duration,total_cost,reservation_status,date_created,last_modified_by,last_modified_at) VALUES
(4,'CONF-0004',4,8,34,'2026-03-01','2026-04-01',1,110.00,'confirmed','2026-01-20 10:00:00',3,'2026-02-20 12:00:00');

-- Waitlist
INSERT INTO waitlist (waitlist_ID,user_ID,boat_ID,preferred_slip_size,preferred_start_date,preferred_end_date,months_duration,position_in_queue,date_created) VALUES
(1,2,2,'26','2026-04-20','2026-05-20',1,1,'2026-02-01 08:00:00'),
(2,1,1,'40','2026-06-10','2026-08-10',2,1,'2026-01-05 09:00:00'),
(3,3,3,'50','2026-05-25','2026-06-25',1,1,'2026-01-15 11:30:00');

-- ======================================================
-- Slip Availability Update 
-- Confirmed reservations => slip unavailable
-- ======================================================
UPDATE slips
SET is_available = TRUE;

UPDATE slips s
JOIN reservations r ON r.slip_ID = s.slip_ID
SET s.is_available = FALSE
WHERE r.reservation_status IN ('confirmed','checked_in');

-- End of file