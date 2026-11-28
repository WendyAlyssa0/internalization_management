-- Registration & profile fields for outbound PSU participants.
-- Requires admin/migrations/20261125_admin_comments.sql (campuses, degree_programs,
-- users.campus_id, users.account_type as VARCHAR) to have been applied first.

-- Optional personal email; the institutional email stays in users.email (login + notices).
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS alternate_email VARCHAR(150) NULL AFTER email;

-- users.faculty_rank now holds the academic rank (faculty) or the position
-- title (non-teaching personnel). Widen it slightly for longer position titles.
ALTER TABLE users
    MODIFY COLUMN faculty_rank VARCHAR(120) NULL;

-- Normalise legacy capitalised values to the lowercase convention used by
-- admin/users.php, programs eligibility and the seeders.
UPDATE users
SET account_type = LOWER(account_type)
WHERE account_type IN ('Student', 'Faculty', 'Non-teaching', 'Non-Teaching');
