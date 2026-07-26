CREATE DATABASE If NOT EXISTS internalization_management;
USE internalization_management;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) NOT NULL,
    suffix VARCHAR(20) DEFAULT NULL,
    student_id_number VARCHAR(30) DEFAULT NULL,
    birthdate DATE DEFAULT NULL,
    sex ENUM('Male', 'Female', 'Prefer not to say') DEFAULT NULL,
    program VARCHAR(150) DEFAULT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    contact VARCHAR(20) DEFAULT NULL,
    user_role ENUM('super admin', 'admin', 'applicant') DEFAULT 'applicant',
    status ENUM('active', 'inactive') DEFAULT 'active',
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO users (first_name, email, user_role, status, password_hash) 
VALUES ('Wendy Alyssa', 'wendyl@gmail.com', 'applicant', 'active', '$2y$10$00M/Hgv7PV63Qu59apVbCer0qf8tYRIRnpizHSWZUW0fZ5AuVa4z6');

INSERT INTO users (first_name, last_name, email, user_role, status, password_hash) 
VALUES ('Sherwin John', 'Vizconde', 'ADsvizconde@psuxizn.com', 'admin', 'active', '$2y$10$RYL06MYWBZgawvOI/9mM.eoITFjwHakj/ugOQIVE.1RNh35YkS8VK');

CREATE TABLE countries (

    id INT AUTO_INCREMENT PRIMARY KEY,
    country_name VARCHAR(100) NOT NULL,
    country_code VARCHAR(10),
    continent VARCHAR(50),
    status ENUM('Active','Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE agreement_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agreement_name VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    status ENUM('Active','Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE partners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    institution_name VARCHAR(255) NOT NULL,
    country_id INT DEFAULT NULL,
    contact_first_name VARCHAR(100),
    contact_middle_name VARCHAR(100),
    contact_last_name VARCHAR(100),
    contact_email VARCHAR(150),
    agreement_type_id INT DEFAULT NULL,
    expiry_date DATE,
    status ENUM('Active','Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_country (country_id),
    INDEX idx_agreement (agreement_type_id),

    CONSTRAINT fk_partner_country
        FOREIGN KEY (country_id)
        REFERENCES countries(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,

    CONSTRAINT fk_partner_agreement
        FOREIGN KEY (agreement_type_id)
        REFERENCES agreement_types(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE programs(

id INT AUTO_INCREMENT PRIMARY KEY,
program_name VARCHAR(150) NOT NULL,
program_type VARCHAR(100) NOT NULL,
country_id INT,
partner_id INT,
agreement_type_id INT,

status ENUM(
    'Active',
    'Upcoming',
    'Completed',
    'Suspended'
    ) DEFAULT 'Active',

start_date DATE NOT NULL,
end_date DATE NOT NULL,
description TEXT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

FOREIGN KEY(country_id)
REFERENCES countries(id)
ON DELETE SET NULL,

FOREIGN KEY(partner_id)
REFERENCES partners(id)
ON DELETE SET NULL,

FOREIGN KEY(agreement_type_id)
REFERENCES agreement_types(id)
ON DELETE SET NULL
);

ALTER TABLE programs
ADD partner_institution VARCHAR(255) NOT NULL;

CREATE TABLE forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_name VARCHAR(255),
    form_type ENUM('online','downloadable'),
    file_path VARCHAR(255) NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE requirements(
id INT AUTO_INCREMENT PRIMARY KEY,
requirement_name VARCHAR(150) NOT NULL,
description TEXT,
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE program_requirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_id INT NOT NULL,
    requirement_name VARCHAR(150) NOT NULL,
    is_required BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_program_requirements_program
        FOREIGN KEY (program_id)
        REFERENCES programs(id)
        ON DELETE CASCADE
);
ALTER TABLE program_requirements
ADD COLUMN form_id INT NULL,
ADD CONSTRAINT fk_program_requirements_form
    FOREIGN KEY (form_id)
    REFERENCES forms(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

ALTER TABLE program_requirements
ADD COLUMN requirement_file VARCHAR(255) NULL;

CREATE TABLE applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    applicant_id INT NOT NULL,
    program_id INT NULL,
    department VARCHAR(100),
    mobility_type ENUM('Inbound','Outbound') NOT NULL,
    institution VARCHAR(150),
    country VARCHAR(100),
    status ENUM(
        'submitted',
        'under_review',
        'approved',
        'rejected',
        'completed'
    ) DEFAULT 'submitted',
    documents_status ENUM(
        'pending',
        'complete',
        'incomplete'
    ) DEFAULT 'pending',
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_application_user
        FOREIGN KEY (applicant_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_application_program
        FOREIGN KEY (program_id)
        REFERENCES programs(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255),
    file_type VARCHAR(50),
    file_path VARCHAR(255),
    status VARCHAR(50) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_document_user
    FOREIGN KEY(user_id)
    REFERENCES users(id)
    ON DELETE CASCADE
);

CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id)
    REFERENCES users(id)
);

CREATE TABLE activity_monitoring (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT,
    week_number INT,
    report TEXT,
    supervisor_feedback TEXT,
    progress INT DEFAULT 0,
    status VARCHAR(50) DEFAULT 'ongoing',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(student_id)
    REFERENCES students(id)
    ON DELETE CASCADE
);

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'default',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NULL,
    receiver_id INT NOT NULL,
    subject VARCHAR(255),
    message TEXT,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);  

CREATE TABLE audit_trail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);  

CREATE TABLE travel_information (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    applicant_name VARCHAR(150),
    destination VARCHAR(150),
    departure_date DATE,
    return_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE countries_addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    country_name VARCHAR(100) NOT NULL,
    region VARCHAR(100) DEFAULT NULL,
    province VARCHAR(100) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    postal_code VARCHAR(20) DEFAULT NULL,
    street_address TEXT DEFAULT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);