ALTER TABLE program_requirements
    ADD COLUMN IF NOT EXISTS requirement_file VARCHAR(255) NULL;
