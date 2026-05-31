-- ═══════════════════════════════════════════════
--   TIMETABLE MANAGEMENT SYSTEM - DATABASE
-- ═══════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS timetable_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE timetable_db;

-- ───────────────────────────────────────────────
-- 1. DEPARTMENTS
-- ───────────────────────────────────────────────
CREATE TABLE departments (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    code       VARCHAR(20)  NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ───────────────────────────────────────────────
-- 2. USERS (students, professors, admins)
-- ───────────────────────────────────────────────
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    reg_number    VARCHAR(50)  NOT NULL UNIQUE,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(150) UNIQUE,
    password      VARCHAR(255) NOT NULL,
    role          ENUM('student','professor','admin') NOT NULL,
    department_id INT,
    is_active     TINYINT(1) DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id)
        ON DELETE SET NULL
);

-- ───────────────────────────────────────────────
-- 3. GROUPS (L1, L2, L3 / sections)
-- ───────────────────────────────────────────────
CREATE TABLE groups_table (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    group_name    VARCHAR(50)  NOT NULL,
    level         VARCHAR(20)  NOT NULL,
    department_id INT,
    capacity      INT DEFAULT 30,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id)
        ON DELETE SET NULL
);

-- ───────────────────────────────────────────────
-- 4. STUDENT - GROUP ASSIGNMENT
-- ───────────────────────────────────────────────
CREATE TABLE student_groups (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    group_id   INT NOT NULL,
    UNIQUE KEY unique_student_group (student_id, group_id),
    FOREIGN KEY (student_id) REFERENCES users(id)
        ON DELETE CASCADE,
    FOREIGN KEY (group_id)   REFERENCES groups_table(id)
        ON DELETE CASCADE
);

-- ───────────────────────────────────────────────
-- 5. ROOMS
-- ───────────────────────────────────────────────
CREATE TABLE rooms (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    room_name  VARCHAR(50) NOT NULL UNIQUE,
    capacity   INT         NOT NULL DEFAULT 30,
    type       ENUM('lecture','lab','seminar') DEFAULT 'lecture',
    is_active  TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ───────────────────────────────────────────────
-- 6. SUBJECTS
-- ───────────────────────────────────────────────
CREATE TABLE subjects (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    code          VARCHAR(30)  NOT NULL UNIQUE,
    department_id INT,
    credits       INT DEFAULT 3,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id)
        ON DELETE SET NULL
);

-- ───────────────────────────────────────────────
-- 7. TIMETABLE
-- ───────────────────────────────────────────────
CREATE TABLE timetable (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    professor_id INT NOT NULL,
    group_id     INT NOT NULL,
    room_id      INT NOT NULL,
    subject_id   INT NOT NULL,
    day          ENUM(
                    'Saturday',
                    'Sunday',
                    'Monday',
                    'Tuesday',
                    'Wednesday',
                    'Thursday'
                 ) NOT NULL,
    time_start   TIME NOT NULL,
    time_end     TIME NOT NULL,
    session_type ENUM('lecture','lab','seminar') DEFAULT 'lecture',
    is_active    TINYINT(1) DEFAULT 1,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- prevent double booking same room same time
    UNIQUE KEY no_room_conflict    (room_id, day, time_start),
    -- prevent professor double booking
    UNIQUE KEY no_prof_conflict    (professor_id, day, time_start),
    -- prevent group double booking
    UNIQUE KEY no_group_conflict   (group_id, day, time_start),

    FOREIGN KEY (professor_id) REFERENCES users(id)
        ON DELETE CASCADE,
    FOREIGN KEY (group_id)     REFERENCES groups_table(id)
        ON DELETE CASCADE,
    FOREIGN KEY (room_id)      REFERENCES rooms(id)
        ON DELETE CASCADE,
    FOREIGN KEY (subject_id)   REFERENCES subjects(id)
        ON DELETE CASCADE
);

-- ───────────────────────────────────────────────
-- 8. PROFESSOR AVAILABILITY
-- ───────────────────────────────────────────────
CREATE TABLE availability (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    professor_id INT NOT NULL,
    day          ENUM(
                    'Saturday',
                    'Sunday',
                    'Monday',
                    'Tuesday',
                    'Wednesday',
                    'Thursday'
                 ) NOT NULL,
    time_start   TIME NOT NULL,
    time_end     TIME NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_availability (professor_id, day, time_start),
    FOREIGN KEY (professor_id) REFERENCES users(id)
        ON DELETE CASCADE
);

-- ───────────────────────────────────────────────
-- 9. REQUESTS
-- ───────────────────────────────────────────────
CREATE TABLE requests (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    professor_id INT NOT NULL,
    request_type ENUM(
                    'new_class',
                    'schedule_change',
                    'overload',
                    'room_change'
                 ) NOT NULL,
    subject_id   INT,
    group_id     INT,
    room_id      INT,
    preferred_day        VARCHAR(20),
    preferred_time_start TIME,
    preferred_time_end   TIME,
    details      TEXT,
    status       ENUM('pending','accepted','rejected') DEFAULT 'pending',
    admin_note   TEXT,
    handled_by   INT,
    handled_at   TIMESTAMP NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (professor_id) REFERENCES users(id)
        ON DELETE CASCADE,
    FOREIGN KEY (subject_id)   REFERENCES subjects(id)
        ON DELETE SET NULL,
    FOREIGN KEY (group_id)     REFERENCES groups_table(id)
        ON DELETE SET NULL,
    FOREIGN KEY (room_id)      REFERENCES rooms(id)
        ON DELETE SET NULL,
    FOREIGN KEY (handled_by)   REFERENCES users(id)
        ON DELETE SET NULL
);

-- ───────────────────────────────────────────────
-- 10. NOTIFICATIONS
-- ───────────────────────────────────────────────
CREATE TABLE notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    title      VARCHAR(150) NOT NULL,
    message    TEXT         NOT NULL,
    type       ENUM(
                  'info',
                  'success',
                  'warning',
                  'error'
               ) DEFAULT 'info',
    is_read    TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
);

-- ═══════════════════════════════════════════════
--   SAMPLE DATA
-- ═══════════════════════════════════════════════

-- ─── Departments ────────────────────────────
INSERT INTO departments (name, code) VALUES
('Computer Science',    'CS'),
('Mathematics',         'MATH'),
('Physics',             'PHY');

-- ─── Rooms ──────────────────────────────────
INSERT INTO rooms (room_name, capacity, type) VALUES
('Room A101',  40, 'lecture'),
('Room A102',  40, 'lecture'),
('Room B201',  35, 'seminar'),
('Lab C301',   40, 'lab'),
('Lab C302',   40, 'lab');

-- ─── Subjects ───────────────────────────────
INSERT INTO subjects (name, code, department_id, credits) VALUES
('Algorithms',            'CS101', 1, 4),
('Data Structures',       'CS102', 1, 4),
('Database Systems',      'CS201', 1, 3),
('Web Development',       'CS202', 1, 3),
('Linear Algebra',        'MATH101',2, 3),
('Discrete Mathematics',  'MATH102',2, 3),
('Physics Fundamentals',  'PHY101', 3, 3);

-- ─── Groups ─────────────────────────────────
INSERT INTO groups_table (group_name, level, department_id, capacity) VALUES
('Group L1-A', 'L1', 1, 35),
('Group L1-B', 'L1', 1, 35),
('Group L2-A', 'L2', 1, 30),
('Group L2-B', 'L2', 1, 30),
('Group L3-A', 'L3', 1, 25),
('Group L3-B', 'L3', 1, 25);

-- ─── Users (passwords = "password123") ──────
INSERT INTO users (reg_number, name, email, password, role, department_id)
VALUES
-- Admin
(
    'ADMIN001',
    'System Admin',
    'admin@school.dz',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin',
    NULL
),
-- Professors
(
    'PROF001',
    'Dr. Ahmed Benali',
    'ahmed@school.dz',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'professor',
    1
),
(
    'PROF002',
    'Dr. Sara Mansouri',
    'sara@school.dz',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'professor',
    1
),
(
    'PROF003',
    'Dr. Karim Hadj',
    'karim@school.dz',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'professor',
    2
),
-- Students
(
    'STU001',
    'Youcef Amrani',
    'youcef@school.dz',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'student',
    1
),
(
    'STU002',
    'Lina Cherif',
    'lina@school.dz',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'student',
    1
),
(
    'STU003',
    'Omar Bouzid',
    'omar@school.dz',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'student',
    1
);

-- ─── Assign Students to Groups ───────────────
INSERT INTO student_groups (student_id, group_id) VALUES
(5, 1),   -- Youcef → Group L1-A
(6, 1),   -- Lina   → Group L1-A
(7, 2);   -- Omar   → Group L1-B

-- ─── Professor Availability ──────────────────
INSERT INTO availability
    (professor_id, day, time_start, time_end)
VALUES
(2, 'Monday',    '08:00:00', '12:00:00'),
(2, 'Tuesday',   '08:00:00', '16:00:00'),
(2, 'Wednesday', '10:00:00', '14:00:00'),
(3, 'Monday',    '10:00:00', '16:00:00'),
(3, 'Thursday',  '08:00:00', '12:00:00'),
(4, 'Tuesday',   '08:00:00', '12:00:00'),
(4, 'Sunday',    '08:00:00', '14:00:00');

-- ─── Sample Timetable ────────────────────────
INSERT INTO timetable
    (professor_id, group_id, room_id, subject_id,
     day, time_start, time_end, session_type)
VALUES
(2, 1, 1, 1, 'Monday',    '08:00:00', '09:30:00', 'lecture'),
(2, 2, 2, 1, 'Monday',    '10:00:00', '11:30:00', 'lecture'),
(3, 3, 3, 3, 'Tuesday',   '08:00:00', '09:30:00', 'seminar'),
(2, 1, 4, 2, 'Wednesday', '10:00:00', '11:30:00', 'lab'),
(4, 4, 1, 5, 'Thursday',  '08:00:00', '09:30:00', 'lecture'),
(3, 5, 2, 4, 'Sunday',    '10:00:00', '11:30:00', 'lecture');

-- ─── Sample Requests ─────────────────────────
INSERT INTO requests
    (professor_id, request_type, subject_id,
     group_id, details, status)
VALUES
(
    2,
    'schedule_change',
    1,
    1,
    'Request to move Monday 8AM class to Tuesday afternoon.',
    'pending'
),
(
    3,
    'new_class',
    3,
    3,
    'Need an extra session for Database Systems exam prep.',
    'pending'
),
(
    4,
    'overload',
    5,
    4,
    'Currently teaching 5 subjects, requesting overload review.',
    'pending'
);

-- ─── Sample Notifications ────────────────────
INSERT INTO notifications
    (user_id, title, message, type)
VALUES
(2, 'Schedule Updated',
    'Your Monday class has been updated.',
    'info'),
(5, 'New Class Added',
    'A new Algorithms lab has been added on Wednesday 10:00.',
    'success'),
(1, 'Pending Request',
    'Dr. Ahmed Benali submitted a schedule change request.',
    'warning');