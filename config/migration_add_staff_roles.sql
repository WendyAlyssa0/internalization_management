ALTER TABLE users
    MODIFY COLUMN user_role ENUM(
        'super admin',
        'admin',
        'applicant',
        'faculty',
        'campus coordinator',
        'ovplia staff',
        'approving official',
        'bor secretary'
    ) DEFAULT 'applicant';

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS year_level VARCHAR(50) NULL AFTER program,
    ADD COLUMN IF NOT EXISTS faculty_rank VARCHAR(100) NULL AFTER year_level;
