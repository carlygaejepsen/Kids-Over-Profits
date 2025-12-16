-- Create wiki_submissions table
CREATE TABLE IF NOT EXISTS wiki_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    program_name VARCHAR(255) NOT NULL,
    city_state VARCHAR(255) DEFAULT NULL,
    organization VARCHAR(255) DEFAULT NULL,
    program_type VARCHAR(255) DEFAULT NULL,
    years_active VARCHAR(100) DEFAULT NULL,
    json_data LONGTEXT DEFAULT NULL,
    generated_markdown LONGTEXT DEFAULT NULL,
    original_markdown LONGTEXT DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'submitted',
    submitted_by VARCHAR(255) DEFAULT NULL,
    submission_notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_program_name (program_name),
    INDEX idx_city_state (city_state),
    INDEX idx_organization (organization),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
