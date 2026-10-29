/* =========================================================
   PARKING SYSTEM - SAFE DATABASE UPDATE
   =========================================================
   IMPORTANT:
   - Does NOT DROP DATABASE
   - Does NOT DROP TABLES
   - Does NOT DELETE OLD DATA
   - Adds missing structures only
   ========================================================= */

CREATE DATABASE IF NOT EXISTS parking_system
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE parking_system;


/* =========================================================
   1. USERS TABLE
   ========================================================= */

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(20),
    password VARCHAR(255) NOT NULL,

    role ENUM('admin','staff','user')
        NOT NULL DEFAULT 'user',

    location_id INT NULL,

    account_status ENUM(
        'pending',
        'approved',
        'rejected',
        'blocked'
    ) NOT NULL DEFAULT 'pending',

    rejection_reason VARCHAR(255) NULL,

    approved_by INT NULL,

    approved_at TIMESTAMP NULL,

    last_login TIMESTAMP NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);


/* =========================================================
   1A. ADD STAFF TO THE EXISTING ROLE ENUM
   ========================================================= */

SET @role_type = (
    SELECT column_type
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'role'
);

SET @sql = IF(
    @role_type <> 'enum(''admin'',''staff'',''user'')',
    'ALTER TABLE users
     MODIFY COLUMN role ENUM(''admin'',''staff'',''user'')
     NOT NULL DEFAULT ''user''',
    'SELECT ''staff role already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


/* =========================================================
   2. ADD account_status IF IT DOES NOT EXIST
   ========================================================= */

SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'account_status'
);

SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE users
     ADD COLUMN account_status ENUM(
        ''pending'',
        ''approved'',
        ''rejected'',
        ''blocked''
     ) NOT NULL DEFAULT ''pending''
     AFTER role',
    'SELECT ''account_status already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


/* =========================================================
   3. ADD rejection_reason IF MISSING
   ========================================================= */

SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'rejection_reason'
);

SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE users
     ADD COLUMN rejection_reason VARCHAR(255) NULL
     AFTER account_status',
    'SELECT ''rejection_reason already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


/* =========================================================
   4. ADD approved_by IF MISSING
   ========================================================= */

SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'approved_by'
);

SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE users
     ADD COLUMN approved_by INT NULL
     AFTER rejection_reason',
    'SELECT ''approved_by already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


/* =========================================================
   5. ADD approved_at IF MISSING
   ========================================================= */

SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'approved_at'
);

SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE users
     ADD COLUMN approved_at TIMESTAMP NULL
     AFTER approved_by',
    'SELECT ''approved_at already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


/* =========================================================
   6. ADD last_login IF MISSING
   ========================================================= */

SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'last_login'
);

SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE users
     ADD COLUMN last_login TIMESTAMP NULL
     AFTER approved_at',
    'SELECT ''last_login already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


/* =========================================================
   7. ADD created_at IF MISSING
   ========================================================= */

SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'created_at'
);

SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE users
     ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    'SELECT ''created_at already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


/* =========================================================
   8. ADD updated_at IF MISSING
   ========================================================= */

SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'updated_at'
);

SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE users
     ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
     ON UPDATE CURRENT_TIMESTAMP',
    'SELECT ''updated_at already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


/* =========================================================
   9. USERS INDEX - ROLE
   ========================================================= */

SET @index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND index_name = 'idx_users_role'
);

SET @sql = IF(
    @index_exists = 0,
    'CREATE INDEX idx_users_role
     ON users(role)',
    'SELECT ''idx_users_role already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


/* =========================================================
   10. USERS INDEX - ACCOUNT STATUS
   ========================================================= */

SET @index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND index_name = 'idx_users_account_status'
);

SET @sql = IF(
    @index_exists = 0,
    'CREATE INDEX idx_users_account_status
     ON users(account_status)',
    'SELECT ''idx_users_account_status already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


/* =========================================================
   11. USERS INDEX - ROLE + STATUS
   ========================================================= */

SET @index_exists = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND index_name = 'idx_users_role_status'
);

SET @sql = IF(
    @index_exists = 0,
    'CREATE INDEX idx_users_role_status
     ON users(role, account_status)',
    'SELECT ''idx_users_role_status already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


/* =========================================================
   12. ACCOUNT APPROVAL HISTORY
   ========================================================= */

CREATE TABLE IF NOT EXISTS account_approval_history (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,

    action ENUM(
        'approved',
        'rejected',
        'blocked',
        'unblocked'
    ) NOT NULL,

    performed_by INT NULL,

    reason VARCHAR(255) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_approval_user (user_id),
    INDEX idx_approval_action (action),

    CONSTRAINT fk_approval_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_approval_staff
        FOREIGN KEY (performed_by)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);


/* =========================================================
   13. LOCATIONS
   ========================================================= */

CREATE TABLE IF NOT EXISTS locations (
    id INT AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(150) NOT NULL,

    address VARCHAR(255),

    city VARCHAR(100),

    state VARCHAR(100),

    pincode VARCHAR(20),

    description TEXT,

    status ENUM(
        'active',
        'inactive'
    ) NOT NULL DEFAULT 'active',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_locations_status (status),
    INDEX idx_locations_city (city)
);


/* =========================================================
   13A. ASSIGN STAFF TO ONE PARKING LOCATION
   ========================================================= */

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'location_id'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE users ADD COLUMN location_id INT NULL AFTER role', 'SELECT ''users.location_id already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_location'
);
SET @sql = IF(@index_exists = 0, 'CREATE INDEX idx_users_location ON users(location_id)', 'SELECT ''users location index already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @constraint_exists = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'users' AND constraint_name = 'fk_users_location'
);
SET @sql = IF(@constraint_exists = 0, 'ALTER TABLE users ADD CONSTRAINT fk_users_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT ''users location foreign key already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE users SET location_id = NULL WHERE role IN ('admin', 'user');


/* =========================================================
   14. PARKING SLOTS
   ========================================================= */

CREATE TABLE IF NOT EXISTS parking_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,

    location_id INT NOT NULL,

    slot_number VARCHAR(50) NOT NULL,

    vehicle_type ENUM(
        'Car',
        'Bike',
        'EV'
    ) NOT NULL DEFAULT 'Car',

    floor VARCHAR(50),

    status ENUM(
        'available',
        'occupied',
        'reserved',
        'maintenance'
    ) NOT NULL DEFAULT 'available',

    price_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_location_slot (
        location_id,
        slot_number
    ),

    INDEX idx_slots_location (
        location_id
    ),

    INDEX idx_slots_status (
        status
    ),

    INDEX idx_slots_vehicle_type (
        vehicle_type
    ),

    CONSTRAINT fk_slots_location
        FOREIGN KEY (location_id)
        REFERENCES locations(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


/* =========================================================
   15. VEHICLES
   ========================================================= */

CREATE TABLE IF NOT EXISTS vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,

    vehicle_number VARCHAR(30) NOT NULL,

    vehicle_type ENUM(
        'Car',
        'Bike',
        'EV'
    ) NOT NULL,

    vehicle_model VARCHAR(100),

    vehicle_color VARCHAR(50),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_vehicle_number (
        vehicle_number
    ),

    INDEX idx_vehicle_user (
        user_id
    ),

    CONSTRAINT fk_vehicle_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


/* =========================================================
   16. BOOKINGS
   ========================================================= */

CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,

    booking_code VARCHAR(30) NOT NULL UNIQUE,

    user_id INT NOT NULL,

    vehicle_id INT NULL,

    vehicle_number VARCHAR(20) NOT NULL,

    slot_id INT NOT NULL,

    booking_date DATE NOT NULL,

    start_time TIME NOT NULL,

    end_time TIME NOT NULL,

    duration_hours DECIMAL(5,2) NOT NULL DEFAULT 1.00,

    price_per_hour DECIMAL(8,2) NOT NULL DEFAULT 0.00,

    total_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,

    status ENUM(
        'pending',
        'confirmed',
        'active',
        'completed',
        'cancelled',
        'rejected'
    ) NOT NULL DEFAULT 'pending',

    payment_status ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',

    checked_in_at TIMESTAMP NULL,

    checked_out_at TIMESTAMP NULL,

    cancellation_reason VARCHAR(255),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_booking_user (
        user_id
    ),

    INDEX idx_booking_vehicle (
        vehicle_id
    ),

    INDEX idx_booking_slot (
        slot_id
    ),

    INDEX idx_booking_date (
        booking_date
    ),

    INDEX idx_booking_status (
        status
    ),

    CONSTRAINT fk_booking_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_booking_vehicle
        FOREIGN KEY (vehicle_id)
        REFERENCES vehicles(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_booking_slot
        FOREIGN KEY (slot_id)
        REFERENCES parking_slots(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


/* =========================================================
   17. PAYMENTS
   ========================================================= */

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,

    booking_id INT NOT NULL,

    user_id INT NOT NULL,

    amount DECIMAL(10,2) NOT NULL,

    payment_method VARCHAR(40) NOT NULL DEFAULT 'upi',

    transaction_id VARCHAR(150),

    payment_status ENUM(
        'pending',
        'completed',
        'failed',
        'refunded'
    ) NOT NULL DEFAULT 'pending',

    confirmed_by INT NULL,

    paid_at TIMESTAMP NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_payment_booking (
        booking_id
    ),

    INDEX idx_payment_user (
        user_id
    ),

    INDEX idx_payment_status (
        payment_status
    ),

    CONSTRAINT fk_payment_booking
        FOREIGN KEY (booking_id)
        REFERENCES bookings(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_payment_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_payment_confirmed_by
        FOREIGN KEY (confirmed_by)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);


/* =========================================================
   17A. UPGRADE EXISTING PAYMENT AND BOOKING STATUS DATA
   ========================================================= */

ALTER TABLE payments
    MODIFY COLUMN payment_method VARCHAR(40) NOT NULL DEFAULT 'upi';

ALTER TABLE payments
    MODIFY COLUMN payment_status ENUM(
        'pending',
        'success',
        'completed',
        'failed',
        'refunded'
    ) NOT NULL DEFAULT 'pending';

UPDATE payments SET payment_status = 'completed' WHERE payment_status = 'success';
UPDATE payments SET payment_method = 'upi' WHERE payment_method = 'UPI';
UPDATE payments SET payment_method = 'cash_on_delivery' WHERE payment_method = 'Cash';

ALTER TABLE payments
    MODIFY COLUMN payment_status ENUM(
        'pending',
        'completed',
        'failed',
        'refunded'
    ) NOT NULL DEFAULT 'pending';

ALTER TABLE bookings
    MODIFY COLUMN status ENUM(
        'pending',
        'confirmed',
        'active',
        'completed',
        'cancelled',
        'rejected'
    ) NOT NULL DEFAULT 'pending';

ALTER TABLE bookings
    MODIFY COLUMN payment_status ENUM(
        'pending',
        'paid',
        'completed',
        'failed',
        'refunded'
    ) NOT NULL DEFAULT 'pending';

UPDATE bookings SET payment_status = 'completed' WHERE payment_status = 'paid';

ALTER TABLE bookings
    MODIFY COLUMN payment_status ENUM(
        'pending',
        'completed',
        'failed',
        'refunded'
    ) NOT NULL DEFAULT 'pending';

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'confirmed_by'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE payments ADD COLUMN confirmed_by INT NULL AFTER payment_status', 'SELECT ''confirmed_by already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'paid_at'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE payments ADD COLUMN paid_at TIMESTAMP NULL AFTER confirmed_by', 'SELECT ''paid_at already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'created_at'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE payments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'SELECT ''created_at already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'updated_at'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE payments ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', 'SELECT ''updated_at already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'payments' AND column_name = 'payment_date'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE payments ADD COLUMN payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'SELECT ''payment_date already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @constraint_exists = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'payments' AND constraint_name = 'fk_payment_confirmed_by'
);
SET @sql = IF(@constraint_exists = 0, 'ALTER TABLE payments ADD CONSTRAINT fk_payment_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT ''payment confirmer foreign key already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'bookings' AND column_name = 'checked_in_at'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE bookings ADD COLUMN checked_in_at TIMESTAMP NULL', 'SELECT ''checked_in_at already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'bookings' AND column_name = 'checked_out_at'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE bookings ADD COLUMN checked_out_at TIMESTAMP NULL', 'SELECT ''checked_out_at already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS payment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    booking_id INT NOT NULL,
    user_id INT NOT NULL,
    staff_id INT NULL,
    previous_status VARCHAR(30) NOT NULL,
    new_status VARCHAR(30) NOT NULL,
    payment_method VARCHAR(40) NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_history_payment (payment_id),
    INDEX idx_payment_history_staff (staff_id),
    INDEX idx_payment_history_changed_at (changed_at),
    CONSTRAINT fk_payment_history_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_history_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_history_staff FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE SET NULL
);


/* =========================================================
   18. BOOKING HISTORY
   ========================================================= */

CREATE TABLE IF NOT EXISTS booking_history (
    id INT AUTO_INCREMENT PRIMARY KEY,

    booking_id INT NOT NULL,

    user_id INT NOT NULL,

    action ENUM('created','modified','cancelled','completed','confirmed','checked_in','checked_out','rejected') NOT NULL,

    old_date DATE NULL,

    old_start_time TIME NULL,

    old_end_time TIME NULL,

    new_date DATE NULL,

    new_start_time TIME NULL,

    new_end_time TIME NULL,

    remarks VARCHAR(255),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_booking_history_booking (
        booking_id
    ),

    INDEX idx_booking_history_user (
        user_id
    ),

    CONSTRAINT fk_history_booking
        FOREIGN KEY (booking_id)
        REFERENCES bookings(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_history_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

    ALTER TABLE booking_history
        MODIFY COLUMN action ENUM('created','modified','cancelled','completed','confirmed','checked_in','checked_out','rejected') NOT NULL;


/* =========================================================
   19. PARKING SESSIONS
   ========================================================= */

CREATE TABLE IF NOT EXISTS parking_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,

    booking_id INT NOT NULL,

    slot_id INT NOT NULL,

    user_id INT NOT NULL,

    check_in DATETIME NULL,

    check_out DATETIME NULL,

    status ENUM(
        'active',
        'completed',
        'cancelled'
    ) NOT NULL DEFAULT 'active',

    amount DECIMAL(10,2) DEFAULT 0.00,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_session_booking (
        booking_id
    ),

    INDEX idx_session_slot (
        slot_id
    ),

    INDEX idx_session_user (
        user_id
    ),

    INDEX idx_session_status (
        status
    ),

    CONSTRAINT fk_session_booking
        FOREIGN KEY (booking_id)
        REFERENCES bookings(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_session_slot
        FOREIGN KEY (slot_id)
        REFERENCES parking_slots(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_session_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


/* =========================================================
   20. STAFF TASKS
   ========================================================= */

CREATE TABLE IF NOT EXISTS staff_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,

    staff_id INT NOT NULL,

    title VARCHAR(150) NOT NULL,

    description TEXT,

    status ENUM(
        'pending',
        'in_progress',
        'completed',
        'cancelled'
    ) NOT NULL DEFAULT 'pending',

    priority ENUM(
        'low',
        'medium',
        'high'
    ) NOT NULL DEFAULT 'medium',

    due_date DATETIME NULL,

    completed_at DATETIME NULL,

    created_by INT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_task_staff (
        staff_id
    ),

    INDEX idx_task_status (
        status
    ),

    INDEX idx_task_priority (
        priority
    ),

    CONSTRAINT fk_task_staff
        FOREIGN KEY (staff_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_task_creator
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);


/* =========================================================
   21. NOTIFICATIONS
   ========================================================= */

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,

    location_id INT NULL,

    title VARCHAR(150) NOT NULL,

    message TEXT NOT NULL,

    type ENUM(
        'booking',
        'payment',
        'cancellation',
        'reminder',
        'system',
        'approval',
        'rejection'
    ) NOT NULL DEFAULT 'system',

    is_read TINYINT(1) NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_notification_user (
        user_id
    ),

    INDEX idx_notification_read (
        is_read
    ),

    INDEX idx_notification_user_read (
        user_id,
        is_read
    ),

    CONSTRAINT fk_notification_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'location_id'
);
SET @sql = IF(@column_exists = 0, 'ALTER TABLE notifications ADD COLUMN location_id INT NULL AFTER user_id', 'SELECT ''notifications.location_id already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE notifications MODIFY COLUMN type ENUM('booking','payment','cancellation','reminder','system','approval','rejection') NOT NULL DEFAULT 'system';

SET @index_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'notifications' AND index_name = 'idx_notifications_location'
);
SET @sql = IF(@index_exists = 0, 'CREATE INDEX idx_notifications_location ON notifications(location_id)', 'SELECT ''notification location index already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @constraint_exists = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'notifications' AND constraint_name = 'fk_notifications_location'
);
SET @sql = IF(@constraint_exists = 0, 'ALTER TABLE notifications ADD CONSTRAINT fk_notifications_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL ON UPDATE CASCADE', 'SELECT ''notification location foreign key already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


/* =========================================================
   22. USERS SELF FOREIGN KEY
   ========================================================= */

SET @fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND CONSTRAINT_NAME = 'fk_users_approved_by'
);

SET @sql = IF(
    @fk_exists = 0,
    'ALTER TABLE users
     ADD CONSTRAINT fk_users_approved_by
     FOREIGN KEY (approved_by)
     REFERENCES users(id)
     ON DELETE SET NULL
     ON UPDATE CASCADE',
    'SELECT ''fk_users_approved_by already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


/* =========================================================
   23. FINAL CHECK
   ========================================================= */

SELECT
    id,
    name,
    email,
    role,
    account_status,
    rejection_reason,
    approved_by,
    approved_at,
    last_login,
    created_at,
    updated_at
FROM users;


/* =========================================================
   24. SHOW ALL TABLES
   ========================================================= */

SHOW TABLES;


/* =========================================================
   25. CHECK USERS STRUCTURE
   ========================================================= */

DESCRIBE users;