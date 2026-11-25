-- Apply once to the internalization_management database before deploying
-- the admin pages that use these fields.

CREATE TABLE campuses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campus_name VARCHAR(120) NOT NULL UNIQUE,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active'
);

INSERT INTO campuses (campus_name) VALUES
    ('Alaminos City Campus'),
    ('Asingan Campus'),
    ('Bayambang Campus'),
    ('Binmaley Campus'),
    ('Infanta Campus'),
    ('Lingayen Campus'),
    ('San Carlos City Campus'),
    ('Sta. Maria Campus'),
    ('Urdaneta City Campus'),
    ('OUS'),
    ('SAS');

ALTER TABLE users
    MODIFY COLUMN account_type VARCHAR(40) NOT NULL DEFAULT 'Student',
    ADD COLUMN campus_id INT UNSIGNED NULL AFTER account_type,
    ADD CONSTRAINT fk_users_campus
        FOREIGN KEY (campus_id) REFERENCES campuses(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    ADD COLUMN coordinator_campus_id INT UNSIGNED
        GENERATED ALWAYS AS (
            CASE
                WHEN LOWER(REPLACE(user_role, '_', ' ')) = 'campus coordinator' THEN campus_id
                ELSE NULL
            END
        ) STORED,
    ADD UNIQUE KEY uq_users_campus_coordinator (coordinator_campus_id);

CREATE TABLE degree_programs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    campus_id INT UNSIGNED NOT NULL,
    program_name VARCHAR(255) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    UNIQUE KEY uq_degree_program_campus (campus_id, program_name),
    CONSTRAINT fk_degree_program_campus
        FOREIGN KEY (campus_id) REFERENCES campuses(id)
        ON UPDATE CASCADE ON DELETE CASCADE
);

INSERT INTO degree_programs (campus_id, program_name)
SELECT c.id, seed.program_name
FROM (
    SELECT 'Alaminos City Campus' AS campus_name, 'BEEd' AS program_name
    UNION ALL SELECT 'Alaminos City Campus', 'BSHM'
    UNION ALL SELECT 'Alaminos City Campus', 'BSTM'
    UNION ALL SELECT 'Sta. Maria Campus', 'BSEd Math'
    UNION ALL SELECT 'Sta. Maria Campus', 'BSEd English'
    UNION ALL SELECT 'Sta. Maria Campus', 'BSA'
    UNION ALL SELECT 'Sta. Maria Campus', 'BSABM'
    UNION ALL SELECT 'Sta. Maria Campus', 'BSABE'
    UNION ALL SELECT 'Urdaneta City Campus', 'BSEd Filipino'
    UNION ALL SELECT 'Urdaneta City Campus', 'BSEd Science'
    UNION ALL SELECT 'Urdaneta City Campus', 'BECEd'
    UNION ALL SELECT 'Urdaneta City Campus', 'AB English'
    UNION ALL SELECT 'Urdaneta City Campus', 'BSCE'
    UNION ALL SELECT 'Urdaneta City Campus', 'BSME'
    UNION ALL SELECT 'Urdaneta City Campus', 'BSEE'
    UNION ALL SELECT 'Urdaneta City Campus', 'BSCpE Embedded'
    UNION ALL SELECT 'Urdaneta City Campus', 'BSCpE SysNet'
    UNION ALL SELECT 'Urdaneta City Campus', 'BS Math'
    UNION ALL SELECT 'Urdaneta City Campus', 'BSArch'
    UNION ALL SELECT 'Lingayen Campus', 'BSBA OM'
    UNION ALL SELECT 'Lingayen Campus', 'BIT Auto'
    UNION ALL SELECT 'Lingayen Campus', 'BIT Electrical'
    UNION ALL SELECT 'Lingayen Campus', 'BIT FSM'
    UNION ALL SELECT 'Lingayen Campus', 'AB Econ'
    UNION ALL SELECT 'Lingayen Campus', 'BTLEd HE'
    UNION ALL SELECT 'Lingayen Campus', 'BTVTEd FSM'
    UNION ALL SELECT 'Lingayen Campus', 'BTVTEd GFD'
    UNION ALL SELECT 'Lingayen Campus', 'BS Bio'
    UNION ALL SELECT 'Lingayen Campus', 'BSCS'
    UNION ALL SELECT 'Lingayen Campus', 'BST'
    UNION ALL SELECT 'Lingayen Campus', 'BSND'
    UNION ALL SELECT 'Lingayen Campus', 'BSSW'
    UNION ALL SELECT 'Lingayen Campus', 'BS Math CIT'
    UNION ALL SELECT 'Lingayen Campus', 'BS Math Pure'
    UNION ALL SELECT 'Lingayen Campus', 'BS Math Stat'
    UNION ALL SELECT 'Lingayen Campus', 'BIT Drafting'
    UNION ALL SELECT 'Lingayen Campus', 'BIT Electronics'
    UNION ALL SELECT 'Lingayen Campus', 'BIT GFD'
    UNION ALL SELECT 'Lingayen Campus', 'BIT Mechanical'
    UNION ALL SELECT 'San Carlos City Campus', 'BSBA FM'
    UNION ALL SELECT 'San Carlos City Campus', 'BSBA MM'
    UNION ALL SELECT 'San Carlos City Campus', 'BSEd Social Studies'
    UNION ALL SELECT 'San Carlos City Campus', 'BSBA HRDM'
    UNION ALL SELECT 'San Carlos City Campus', 'BSOA'
    UNION ALL SELECT 'Asingan Campus', 'BSIT'
    UNION ALL SELECT 'Asingan Campus', 'BTLEd'
    UNION ALL SELECT 'Infanta Campus', 'BEEd'
    UNION ALL SELECT 'Bayambang Campus', 'BPEd'
    UNION ALL SELECT 'Bayambang Campus', 'BSN'
    UNION ALL SELECT 'Bayambang Campus', 'BPA'
    UNION ALL SELECT 'Bayambang Campus', 'BSIT Web/Mobile'
    UNION ALL SELECT 'Bayambang Campus', 'BSIT Data Analytics'
    UNION ALL SELECT 'Binmaley Campus', 'BSFAS'
    UNION ALL SELECT 'Binmaley Campus', 'BSES'
    UNION ALL SELECT 'Binmaley Campus', 'BSCrim'
) AS seed
INNER JOIN campuses c ON c.campus_name = seed.campus_name;

INSERT INTO countries (country_name, status)
SELECT country_name, 'Active'
FROM (
    SELECT 'Vietnam' AS country_name
    UNION ALL SELECT 'Thailand'
    UNION ALL SELECT 'Indonesia'
    UNION ALL SELECT 'Malaysia'
    UNION ALL SELECT 'South Korea'
) AS partner_countries
WHERE NOT EXISTS (
    SELECT 1 FROM countries existing WHERE existing.country_name = partner_countries.country_name
);

ALTER TABLE programs
    ADD COLUMN application_start_date DATE NULL AFTER end_date,
    ADD COLUMN application_end_date DATE NULL AFTER application_start_date,
    ADD COLUMN mobility_direction ENUM('outbound', 'inbound') NOT NULL DEFAULT 'outbound' AFTER status,
    ADD COLUMN nature_of_partnership VARCHAR(255) NULL AFTER description,
    ADD COLUMN program_types TEXT NULL AFTER program_type,
    ADD COLUMN eligible_participant_types TEXT NULL AFTER eligible_account_type,
    ADD COLUMN eligible_campuses TEXT NULL AFTER eligible_department,
    ADD COLUMN eligible_degree_programs TEXT NULL AFTER eligible_campuses;

ALTER TABLE partners
    ADD COLUMN address_record_id INT NULL AFTER country_id,
    ADD COLUMN tagline VARCHAR(255) NULL AFTER institution_name,
    ADD COLUMN background TEXT NULL AFTER tagline,
    ADD COLUMN offerings TEXT NULL AFTER background,
    ADD COLUMN collaboration_areas TEXT NULL AFTER notes,
    ADD COLUMN profile_photos TEXT NULL AFTER collaboration_areas,
    ADD COLUMN brochure_path VARCHAR(500) NULL AFTER profile_photos,
    ADD COLUMN moa_path VARCHAR(500) NULL AFTER brochure_path;

ALTER TABLE announcements
    MODIFY COLUMN status ENUM('Published', 'Draft', 'ongoing', 'completed') NOT NULL DEFAULT 'ongoing',
    ADD COLUMN expiry_date DATE NULL AFTER publish_date,
    ADD COLUMN attachment_path VARCHAR(500) NULL AFTER expiry_date;

ALTER TABLE program_requirements
    ADD COLUMN applicant_type VARCHAR(40) NULL AFTER requirement_id,
    ADD COLUMN template_path VARCHAR(500) NULL AFTER applicant_type;

CREATE TABLE inbound_participants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    participant_type ENUM('student', 'faculty', 'non-teaching') NOT NULL,
    home_institution VARCHAR(255) NOT NULL,
    country_name VARCHAR(120) NOT NULL,
    program_id INT NULL,
    arrival_date DATE NULL,
    departure_date DATE NULL,
    narrative_report_path VARCHAR(500) NULL,
    evaluation_path VARCHAR(500) NULL,
    notes TEXT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inbound_program
        FOREIGN KEY (program_id) REFERENCES programs(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_inbound_creator
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
);

DELIMITER $$
CREATE TRIGGER applications_respect_program_window
BEFORE INSERT ON applications
FOR EACH ROW
BEGIN
    DECLARE application_open DATE;
    DECLARE application_close DATE;

    SELECT application_start_date, application_end_date
      INTO application_open, application_close
      FROM programs
     WHERE id = NEW.program_id;

    IF application_open IS NOT NULL AND CURDATE() < application_open THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Applications have not opened for this program.';
    END IF;

    IF application_close IS NOT NULL AND CURDATE() > application_close THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The application period for this program has closed.';
    END IF;
END$$
DELIMITER ;
