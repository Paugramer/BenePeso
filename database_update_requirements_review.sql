CREATE TABLE IF NOT EXISTS application_requirement_reviews (
    beneficiary_id INT NOT NULL,
    requirement_key CHAR(64) NOT NULL,
    requirement_name VARCHAR(500) NOT NULL,
    review_status VARCHAR(30) NOT NULL,
    reviewer_note VARCHAR(500) NOT NULL DEFAULT '',
    reviewed_by_role VARCHAR(20) NOT NULL,
    reviewed_by_id INT NOT NULL,
    reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (beneficiary_id, requirement_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
