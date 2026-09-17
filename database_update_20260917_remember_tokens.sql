CREATE TABLE IF NOT EXISTS auth_remember_tokens (
    token_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    selector CHAR(24) NOT NULL,
    validator_hash CHAR(64) NOT NULL,
    account_role VARCHAR(20) NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL,
    PRIMARY KEY (token_id),
    UNIQUE KEY uq_auth_remember_selector (selector),
    KEY idx_auth_remember_account (account_role, account_id),
    KEY idx_auth_remember_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
