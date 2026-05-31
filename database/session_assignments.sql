-- ─────────────────────────────────────────────
-- SESSION ASSIGNMENTS TABLE
-- Defines WHAT needs to be scheduled.
-- The CSP solver reads from this table and
-- assigns each row a (day, time, room).
-- ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS session_assignments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    professor_id INT NOT NULL,
    subject_id   INT NOT NULL,
    group_id     INT NOT NULL,
    session_type ENUM('lecture','lab','seminar') NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (professor_id)
        REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id)
        REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id)
        REFERENCES groups_table(id) ON DELETE CASCADE
);

-- Example data
INSERT INTO session_assignments
    (professor_id, subject_id, group_id, session_type)
VALUES
-- Dr. Ahmed → Algorithms → Group L1-A
(2, 1, 1, 'lecture'),
(2, 1, 1, 'lab'),
-- Dr. Ahmed → Algorithms → Group L1-B
(2, 1, 2, 'lecture'),
(2, 1, 2, 'lab'),
-- Dr. Sara → Database → Group L2-A
(3, 3, 3, 'lecture'),
(3, 3, 3, 'seminar'),
-- Dr. Karim → Linear Algebra → Group L2-B
(4, 5, 4, 'lecture'),
(4, 5, 4, 'seminar');