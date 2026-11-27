-- Apply once to enable admin review of applicant requirement uploads.
ALTER TABLE user_documents
    ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER file_path;
