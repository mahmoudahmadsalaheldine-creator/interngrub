-- ═══════════════════════════════════════════════════════════════
--  InternGrub  –  Full Database Schema
--  Tables: core system + HR recruitment module
-- ═══════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────
--  CORE TABLES
-- ─────────────────────────────────────────────────────────────

CREATE TABLE department (
    department_id   INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(100) NOT NULL
);

CREATE TABLE user (
    user_id       INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    full_name     VARCHAR(150) NOT NULL,
    email         VARCHAR(150) NULL,
    password      VARCHAR(255) NOT NULL,
    phone         VARCHAR(30),
    role          ENUM('admin','manager','intern','hr') NOT NULL DEFAULT 'intern',
    department_id INT DEFAULT NULL,
    status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES department(department_id) ON DELETE SET NULL
);

CREATE TABLE intern_profile (
    intern_id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id            INT NOT NULL,
    department_id      INT,
    position           VARCHAR(100),
    start_date         DATE,
    end_date           DATE,
    internship_status  ENUM('active','completed','terminated','on_leave') DEFAULT 'active',
    supervisor_id      INT,
    FOREIGN KEY (user_id)       REFERENCES user(user_id)       ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES department(department_id) ON DELETE SET NULL,
    FOREIGN KEY (supervisor_id) REFERENCES user(user_id)       ON DELETE SET NULL
);

CREATE TABLE attendance (
    attendance_id   INT AUTO_INCREMENT PRIMARY KEY,
    intern_id       INT NOT NULL,
    attendance_date DATE NOT NULL,
    check_in        TIME,
    check_out       TIME,
    total_hours     FLOAT,
    status          ENUM('present','absent','late','on_leave','weekend') DEFAULT 'absent',
    notes           TEXT,
    FOREIGN KEY (intern_id) REFERENCES intern_profile(intern_id) ON DELETE CASCADE
);

CREATE TABLE leave_request (
    leave_id        INT AUTO_INCREMENT PRIMARY KEY,
    intern_id       INT NOT NULL,
    start_date      DATE NOT NULL,
    end_date        DATE NOT NULL,
    reason          TEXT,
    approval_status ENUM('pending','approved','rejected') DEFAULT 'pending',
    approved_by     INT,
    request_date    DATETIME DEFAULT CURRENT_TIMESTAMP,
    approval_date   DATETIME,
    supervisor_note TEXT,
    FOREIGN KEY (intern_id)   REFERENCES intern_profile(intern_id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES user(user_id)             ON DELETE SET NULL
);

CREATE TABLE task (
    task_id     INT AUTO_INCREMENT PRIMARY KEY,
    created_by  INT NOT NULL,
    title       VARCHAR(200) NOT NULL,
    description TEXT,
    due_date    DATE,
    priority    ENUM('low','medium','high') DEFAULT 'medium',
    task_status ENUM('to_do','in_progress','pending_review','done') DEFAULT 'to_do',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES user(user_id) ON DELETE CASCADE
);

CREATE TABLE task_assignment (
    assignment_id     INT AUTO_INCREMENT PRIMARY KEY,
    task_id           INT NOT NULL,
    intern_id         INT NOT NULL,
    assigned_date     DATE DEFAULT (CURRENT_DATE),
    assignment_status ENUM('active','reassigned','completed') DEFAULT 'active',
    FOREIGN KEY (task_id)   REFERENCES task(task_id)           ON DELETE CASCADE,
    FOREIGN KEY (intern_id) REFERENCES intern_profile(intern_id) ON DELETE CASCADE
);

CREATE TABLE task_update (
    update_id   INT AUTO_INCREMENT PRIMARY KEY,
    task_id     INT NOT NULL,
    user_id     INT NOT NULL,
    update_text TEXT NOT NULL,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES task(task_id)   ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES user(user_id)   ON DELETE CASCADE
);

CREATE TABLE password_reset (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    otp        CHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    used       TINYINT(1) DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES user(user_id) ON DELETE CASCADE
);

CREATE TABLE notification (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    message         TEXT NOT NULL,
    type            ENUM('leave','task','attendance','general') DEFAULT 'general',
    is_read         BOOLEAN DEFAULT FALSE,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES user(user_id) ON DELETE CASCADE
);

-- ─────────────────────────────────────────────────────────────
--  HR RECRUITMENT MODULE
-- ─────────────────────────────────────────────────────────────

CREATE TABLE job (
    job_id        INT AUTO_INCREMENT PRIMARY KEY,
    title         VARCHAR(150) NOT NULL,
    department_id INT  DEFAULT NULL,
    description   TEXT DEFAULT NULL,
    requirements  TEXT DEFAULT NULL,
    status        ENUM('open','closed') DEFAULT 'open',
    openings      INT  DEFAULT 1,
    created_by    INT  DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES department(department_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by)    REFERENCES user(user_id)             ON DELETE SET NULL
);

CREATE TABLE candidate (
    candidate_id     INT AUTO_INCREMENT PRIMARY KEY,
    full_name        VARCHAR(120) NOT NULL,
    email            VARCHAR(150) DEFAULT NULL,
    phone            VARCHAR(40)  DEFAULT NULL,
    cv_link          VARCHAR(500) DEFAULT NULL,
    linkedin_profile VARCHAR(500) DEFAULT NULL,
    evaluation       TINYINT      DEFAULT 0,
    user_id          INT          DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES user(user_id) ON DELETE SET NULL
);

CREATE TABLE application (
    application_id   INT AUTO_INCREMENT PRIMARY KEY,
    candidate_id     INT  NOT NULL,
    job_id           INT  NOT NULL,
    application_date DATE NOT NULL,
    status           ENUM('new','qualification','first_interview','second_interview','contract_proposal','contract_signed','refused') DEFAULT 'new',
    kanban_state     ENUM('normal','ready','blocked') DEFAULT 'normal',
    hire_date        DATETIME     DEFAULT NULL,
    refuse_reason    VARCHAR(255) DEFAULT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (candidate_id) REFERENCES candidate(candidate_id) ON DELETE CASCADE,
    FOREIGN KEY (job_id)       REFERENCES job(job_id)             ON DELETE CASCADE
);

CREATE TABLE application_note (
    note_id          INT AUTO_INCREMENT PRIMARY KEY,
    application_id   INT  NOT NULL UNIQUE,
    screening_notes  TEXT DEFAULT NULL,
    interview_notes  TEXT DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    followup_date    DATE DEFAULT NULL,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES application(application_id) ON DELETE CASCADE
);

CREATE TABLE application_activity (
    activity_id    INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    user_id        INT DEFAULT NULL,
    activity_type  VARCHAR(50) DEFAULT 'note',
    description    TEXT NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES application(application_id) ON DELETE CASCADE
);

CREATE TABLE interview (
    interview_id   INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT  NOT NULL,
    interview_date DATE DEFAULT NULL,
    interview_time TIME DEFAULT NULL,
    result         ENUM('pending','passed','failed') DEFAULT 'pending',
    notes          TEXT DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES application(application_id) ON DELETE CASCADE
);

-- ─────────────────────────────────────────────────────────────
--  SEED DATA
-- ─────────────────────────────────────────────────────────────

INSERT INTO department (department_name) VALUES
('Engineering'),
('Marketing'),
('Design'),
('HR'),
('Operations');

-- All passwords are bcrypt hash of "1234"
INSERT INTO user (username, full_name, email, password, phone, role, status) VALUES
('yassmine', 'Yassmine Khalil', 'yassmine@company.com', '$2y$10$YUFYddSxZ0OQw4SlT3IqIOXBxHPRhG5ww02Hrd39sk6M1BysxiqRK', '+961 70 123 456', 'admin',  'active'),
('sara.m',   'Sara Mansour',    'sara.m@company.com',   '$2y$10$YUFYddSxZ0OQw4SlT3IqIOXBxHPRhG5ww02Hrd39sk6M1BysxiqRK', '+961 71 234 567', 'intern', 'active'),
('karim.n',  'Karim Nassar',    'karim.n@company.com',  '$2y$10$YUFYddSxZ0OQw4SlT3IqIOXBxHPRhG5ww02Hrd39sk6M1BysxiqRK', '+961 71 345 678', 'intern', 'active'),
('lara.k',   'Lara Khoury',     'lara.k@company.com',   '$2y$10$YUFYddSxZ0OQw4SlT3IqIOXBxHPRhG5ww02Hrd39sk6M1BysxiqRK', '+961 71 456 789', 'intern', 'active'),
('omar.h',   'Omar Haddad',     'omar.h@company.com',   '$2y$10$YUFYddSxZ0OQw4SlT3IqIOXBxHPRhG5ww02Hrd39sk6M1BysxiqRK', '+961 71 567 890', 'intern', 'active'),
('maya.s',   'Maya Saleh',      'maya.s@company.com',   '$2y$10$YUFYddSxZ0OQw4SlT3IqIOXBxHPRhG5ww02Hrd39sk6M1BysxiqRK', '+961 71 678 901', 'intern', 'active'),
('hr',       'HR Manager',      NULL,                   '$2y$12$/iKX6zEAD/sO8o7gus9MTOktvjWE2vfpJ9L/bvWR/VcXkjNHwAe2K', NULL,              'hr',     'active');

-- intern_profile: user_id 2..6 → intern_id 1..5
INSERT INTO intern_profile (user_id, department_id, position, start_date, end_date, internship_status, supervisor_id) VALUES
(2, 1, 'Frontend Developer', '2026-03-01', '2026-08-31', 'active', 1),
(3, 2, 'Content Writer',     '2026-02-15', '2026-07-15', 'active', 1),
(4, 3, 'UI Designer',        '2026-04-01', '2026-09-30', 'active', 1),
(5, 1, 'Backend Developer',  '2026-01-10', '2026-06-30', 'active', 1),
(6, 4, 'HR Assistant',       '2026-03-15', '2026-09-15', 'active', 1);

-- Attendance: two weeks of records for each of the 5 interns
INSERT INTO attendance (intern_id, attendance_date, check_in, check_out, total_hours, status, notes) VALUES
-- Sara (intern 1)
(1, CURDATE() - INTERVAL 13 DAY, '08:55:00', '17:05:00', 8.2, 'present', NULL),
(1, CURDATE() - INTERVAL 12 DAY, '09:10:00', '17:00:00', 7.8, 'late',    NULL),
(1, CURDATE() - INTERVAL 9 DAY,  '08:50:00', '17:15:00', 8.4, 'present', NULL),
(1, CURDATE() - INTERVAL 8 DAY,  '08:58:00', '17:02:00', 8.1, 'present', NULL),
(1, CURDATE() - INTERVAL 7 DAY,  NULL,        NULL,        NULL,'absent',  'No show, no notice'),
(1, CURDATE() - INTERVAL 6 DAY,  '08:45:00', '17:10:00', 8.4, 'present', NULL),
(1, CURDATE() - INTERVAL 5 DAY,  '08:52:00', '16:58:00', 8.1, 'present', NULL),
(1, CURDATE() - INTERVAL 2 DAY,  '09:05:00', '17:00:00', 7.9, 'late',    NULL),
(1, CURDATE() - INTERVAL 1 DAY,  '08:50:00', '17:08:00', 8.3, 'present', NULL),
(1, CURDATE(),                   '08:54:00', NULL,        NULL,'present', NULL),
-- Karim (intern 2)
(2, CURDATE() - INTERVAL 13 DAY, '09:00:00', '17:00:00', 8.0, 'present', NULL),
(2, CURDATE() - INTERVAL 12 DAY, '09:02:00', '17:05:00', 8.0, 'present', NULL),
(2, CURDATE() - INTERVAL 9 DAY,  NULL,        NULL,        NULL,'on_leave','Approved leave'),
(2, CURDATE() - INTERVAL 8 DAY,  '08:58:00', '16:55:00', 7.9, 'present', NULL),
(2, CURDATE() - INTERVAL 7 DAY,  '09:15:00', '17:20:00', 8.1, 'late',    NULL),
(2, CURDATE() - INTERVAL 6 DAY,  '09:00:00', '17:00:00', 8.0, 'present', NULL),
(2, CURDATE() - INTERVAL 5 DAY,  '08:55:00', '17:02:00', 8.1, 'present', NULL),
(2, CURDATE() - INTERVAL 2 DAY,  '09:00:00', '17:00:00', 8.0, 'present', NULL),
(2, CURDATE() - INTERVAL 1 DAY,  NULL,        NULL,        NULL,'absent',  NULL),
(2, CURDATE(),                   '09:12:00', NULL,        NULL,'late',    NULL),
-- Lara (intern 3)
(3, CURDATE() - INTERVAL 13 DAY, '08:40:00', '16:50:00', 8.2, 'present', NULL),
(3, CURDATE() - INTERVAL 12 DAY, '08:45:00', '16:55:00', 8.2, 'present', NULL),
(3, CURDATE() - INTERVAL 9 DAY,  '08:42:00', '17:00:00', 8.3, 'present', NULL),
(3, CURDATE() - INTERVAL 8 DAY,  '08:50:00', '16:45:00', 7.9, 'present', NULL),
(3, CURDATE() - INTERVAL 7 DAY,  '08:48:00', '17:05:00', 8.3, 'present', NULL),
(3, CURDATE() - INTERVAL 6 DAY,  NULL,        NULL,        NULL,'absent',  NULL),
(3, CURDATE() - INTERVAL 5 DAY,  '08:55:00', '16:50:00', 7.9, 'present', NULL),
(3, CURDATE() - INTERVAL 2 DAY,  '08:50:00', '17:00:00', 8.2, 'present', NULL),
(3, CURDATE() - INTERVAL 1 DAY,  '08:47:00', '16:58:00', 8.2, 'present', NULL),
(3, CURDATE(),                   NULL,        NULL,        NULL,'absent',  NULL),
-- Omar (intern 4)
(4, CURDATE() - INTERVAL 13 DAY, '08:30:00', '16:40:00', 8.2, 'present', NULL),
(4, CURDATE() - INTERVAL 12 DAY, '08:35:00', '16:45:00', 8.2, 'present', NULL),
(4, CURDATE() - INTERVAL 9 DAY,  '08:33:00', '16:50:00', 8.3, 'present', NULL),
(4, CURDATE() - INTERVAL 8 DAY,  '09:20:00', '17:00:00', 7.7, 'late',    NULL),
(4, CURDATE() - INTERVAL 7 DAY,  '08:30:00', '16:45:00', 8.3, 'present', NULL),
(4, CURDATE() - INTERVAL 6 DAY,  '08:31:00', '16:42:00', 8.2, 'present', NULL),
(4, CURDATE() - INTERVAL 5 DAY,  NULL,        NULL,        NULL,'on_leave','Approved leave'),
(4, CURDATE() - INTERVAL 2 DAY,  '08:30:00', '16:48:00', 8.3, 'present', NULL),
(4, CURDATE() - INTERVAL 1 DAY,  '08:32:00', '16:50:00', 8.3, 'present', NULL),
(4, CURDATE(),                   '08:30:00', NULL,        NULL,'present', NULL),
-- Maya (intern 5)
(5, CURDATE() - INTERVAL 13 DAY, '09:05:00', '17:10:00', 8.1, 'present', NULL),
(5, CURDATE() - INTERVAL 12 DAY, '09:00:00', '17:00:00', 8.0, 'present', NULL),
(5, CURDATE() - INTERVAL 9 DAY,  '09:10:00', '17:05:00', 7.9, 'late',    NULL),
(5, CURDATE() - INTERVAL 8 DAY,  '09:00:00', '17:00:00', 8.0, 'present', NULL),
(5, CURDATE() - INTERVAL 7 DAY,  NULL,        NULL,        NULL,'absent',  NULL),
(5, CURDATE() - INTERVAL 6 DAY,  '09:02:00', '17:00:00', 7.9, 'present', NULL),
(5, CURDATE() - INTERVAL 5 DAY,  '08:58:00', '17:05:00', 8.1, 'present', NULL),
(5, CURDATE() - INTERVAL 2 DAY,  '09:00:00', '17:00:00', 8.0, 'present', NULL),
(5, CURDATE() - INTERVAL 1 DAY,  '09:01:00', '17:02:00', 8.0, 'present', NULL),
(5, CURDATE(),                   NULL,        NULL,        NULL,'absent',  NULL);

INSERT INTO task (created_by, title, description, due_date, priority, task_status, created_at) VALUES
(1, 'Design onboarding flow',       'Create UI/UX for the intern onboarding experience.',         CURDATE() + INTERVAL 3 DAY,  'high',   'to_do',          NOW() - INTERVAL 6 DAY),
(1, 'Write blog post draft',        'Draft a blog post about company culture.',                   CURDATE() + INTERVAL 5 DAY,  'medium', 'to_do',          NOW() - INTERVAL 5 DAY),
(1, 'Fix auth API bug',             'Resolve authentication token expiry issue.',                 CURDATE() - INTERVAL 1 DAY,  'high',   'in_progress',    NOW() - INTERVAL 8 DAY),
(1, 'Setup DB migrations',          'Create and test database migration scripts.',                CURDATE() + INTERVAL 2 DAY,  'medium', 'in_progress',    NOW() - INTERVAL 4 DAY),
(1, 'HR policy document',           'Update the HR policy handbook for 2026.',                   CURDATE() - INTERVAL 10 DAY, 'low',    'done',           NOW() - INTERVAL 14 DAY),
(1, 'Landing page redesign mockup', 'Produce high-fidelity mockups for the new landing page.',   CURDATE() + INTERVAL 1 DAY,  'high',   'pending_review', NOW() - INTERVAL 7 DAY),
(1, 'Weekly social media report',   'Compile engagement metrics for the past week.',              CURDATE() - INTERVAL 2 DAY,  'low',    'done',           NOW() - INTERVAL 9 DAY),
(1, 'API integration testing',      'Write integration tests for the payments API.',              CURDATE() + INTERVAL 4 DAY,  'medium', 'to_do',          NOW() - INTERVAL 2 DAY);

INSERT INTO task_assignment (task_id, intern_id, assigned_date, assignment_status) VALUES
(1, 3, CURDATE() - INTERVAL 6 DAY,  'active'),
(2, 2, CURDATE() - INTERVAL 5 DAY,  'active'),
(3, 1, CURDATE() - INTERVAL 8 DAY,  'active'),
(4, 4, CURDATE() - INTERVAL 4 DAY,  'active'),
(5, 5, CURDATE() - INTERVAL 14 DAY, 'completed'),
(6, 3, CURDATE() - INTERVAL 7 DAY,  'active'),
(7, 2, CURDATE() - INTERVAL 9 DAY,  'completed'),
(8, 1, CURDATE() - INTERVAL 2 DAY,  'active');

INSERT INTO task_update (task_id, user_id, update_text, updated_at) VALUES
(3, 2, 'Investigated the token expiry logic, found the root cause in the refresh handler.', NOW() - INTERVAL 3 DAY),
(3, 2, 'Pushed a fix branch, currently testing against staging.',                          NOW() - INTERVAL 1 DAY),
(6, 4, 'First draft of the homepage and pricing page mockups uploaded for review.',        NOW() - INTERVAL 1 DAY),
(5, 6, 'Finalized and submitted the updated HR handbook.',                                 NOW() - INTERVAL 11 DAY),
(7, 3, 'Report compiled and shared in the team channel.',                                  NOW() - INTERVAL 3 DAY);

INSERT INTO leave_request (intern_id, start_date, end_date, reason, approval_status, approved_by, request_date, approval_date, supervisor_note) VALUES
(3, CURDATE() + INTERVAL 5 DAY,  CURDATE() + INTERVAL 8 DAY,  'Family emergency — needs to travel out of town.', 'pending',  NULL, NOW() - INTERVAL 1 DAY,  NULL,                    NULL),
(5, CURDATE() - INTERVAL 9 DAY,  CURDATE() - INTERVAL 5 DAY,  'Medical leave for a minor procedure.',            'approved', 1,    NOW() - INTERVAL 12 DAY, NOW() - INTERVAL 11 DAY, 'Get well soon, take the time you need.'),
(2, CURDATE() - INTERVAL 8 DAY,  CURDATE() - INTERVAL 8 DAY,  'Personal errand, short notice.',                  'rejected', 1,    NOW() - INTERVAL 9 DAY,  NOW() - INTERVAL 9 DAY,  'Please give more notice next time.'),
(1, CURDATE() + INTERVAL 10 DAY, CURDATE() + INTERVAL 11 DAY, 'Family travel for a wedding.',                    'pending',  NULL, NOW() - INTERVAL 2 DAY,  NULL,                    NULL),
(4, CURDATE() - INTERVAL 6 DAY,  CURDATE() - INTERVAL 6 DAY,  'Doctor appointment.',                             'approved', 1,    NOW() - INTERVAL 7 DAY,  NOW() - INTERVAL 7 DAY,  'Approved, feel better.');

INSERT INTO notification (user_id, message, type, is_read, created_at) VALUES
(1, 'New leave request from Lara Khoury — requesting leave from upcoming dates.',    'leave',      FALSE, NOW() - INTERVAL 1 DAY),
(1, 'Task update: Fix auth API bug — Sara Mansour posted an update on the task.',   'task',       FALSE, NOW() - INTERVAL 1 DAY),
(1, 'Leave approved — Maya Saleh — leave request was approved.',                    'leave',      TRUE,  NOW() - INTERVAL 11 DAY),
(1, 'Absent intern alert — Maya Saleh did not check in today.',                     'attendance', TRUE,  NOW()),
(1, 'Task submitted for review: Landing page redesign mockup by Omar Haddad.',      'task',       FALSE, NOW() - INTERVAL 1 DAY),
(2, 'Your leave request for Jun 15 was rejected by Yassmine Khalil.',               'leave',      TRUE,  NOW() - INTERVAL 9 DAY),
(3, 'You have been assigned a new task: Design onboarding flow.',                   'task',       TRUE,  NOW() - INTERVAL 6 DAY),
(4, 'Your leave request was approved by Yassmine Khalil.',                          'leave',      FALSE, NOW() - INTERVAL 7 DAY),
(5, 'Your leave request was approved by Yassmine Khalil.',                          'leave',      TRUE,  NOW() - INTERVAL 11 DAY),
(6, 'Welcome to InternGrub! Your HR assistant onboarding is complete.',             'general',    TRUE,  NOW() - INTERVAL 14 DAY);
