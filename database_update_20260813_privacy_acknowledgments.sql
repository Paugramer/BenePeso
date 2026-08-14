CREATE TABLE IF NOT EXISTS privacy_acknowledgments (
    acknowledgment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    beneficiary_id INT NULL,
    acknowledgment_context VARCHAR(40) NOT NULL,
    notice_version VARCHAR(20) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    acknowledged_at DATETIME NOT NULL,
    PRIMARY KEY (acknowledgment_id),
    INDEX idx_privacy_ack_user (user_id),
    INDEX idx_privacy_ack_beneficiary (beneficiary_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

