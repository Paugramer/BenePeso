-- Additive report fields; application identity and decision values are unchanged.
CREATE TABLE IF NOT EXISTS beneficiary_reporting_details (
    beneficiary_id INT NOT NULL PRIMARY KEY,
    work_assignment VARCHAR(250) NOT NULL DEFAULT '',
    employment_start DATE NULL,
    employment_end DATE NULL,
    report_parent_name VARCHAR(200) NOT NULL DEFAULT '',
    report_parent_occupation VARCHAR(200) NOT NULL DEFAULT '',
    report_remarks TEXT NULL,
    report_remarks_secondary TEXT NULL,
    nm_two_day_earnings VARCHAR(100) NOT NULL DEFAULT '',
    nm_total_earnings VARCHAR(100) NOT NULL DEFAULT '',
    updated_by_role VARCHAR(20) NOT NULL,
    updated_by_id INT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
