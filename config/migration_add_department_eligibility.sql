ALTER TABLE users
    ADD COLUMN IF NOT EXISTS department VARCHAR(100) NULL AFTER student_id_number;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS account_type ENUM('Student', 'Faculty') NOT NULL DEFAULT 'Student',
    ADD COLUMN IF NOT EXISTS year_level VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS faculty_rank VARCHAR(100) NULL;

UPDATE users
SET account_type = 'Faculty'
WHERE faculty_rank IS NOT NULL AND TRIM(faculty_rank) <> '';

UPDATE users
SET account_type = 'Student'
WHERE account_type IS NULL OR account_type NOT IN ('Student', 'Faculty');

ALTER TABLE programs
    ADD COLUMN IF NOT EXISTS eligible_department VARCHAR(100) NOT NULL DEFAULT 'All Departments' AFTER program_type;

ALTER TABLE programs
    ADD COLUMN IF NOT EXISTS eligible_account_type ENUM('Student', 'Faculty') NOT NULL DEFAULT 'Student' AFTER eligible_department;

ALTER TABLE program_requirements
    ADD COLUMN IF NOT EXISTS requirement_id INT NULL AFTER program_id;

ALTER TABLE program_requirements
    ADD COLUMN IF NOT EXISTS requirement_file VARCHAR(255) NULL;

UPDATE program_requirements pr
INNER JOIN requirements r ON r.requirement_name = pr.requirement_name
SET pr.requirement_id = r.id
WHERE pr.requirement_id IS NULL;

ALTER TABLE program_requirements
    MODIFY requirement_name VARCHAR(150) NULL,
    MODIFY requirement_id INT NOT NULL,
    ADD CONSTRAINT fk_program_requirements_requirement
        FOREIGN KEY (requirement_id) REFERENCES requirements(id)
        ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS user_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    requirement_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_document (user_id, requirement_id),
    CONSTRAINT fk_user_documents_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_documents_requirement
        FOREIGN KEY (requirement_id) REFERENCES program_requirements(id) ON DELETE CASCADE
) ENGINE=InnoDB;
