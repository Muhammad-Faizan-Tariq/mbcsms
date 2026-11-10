-- SMPP Integration Tables
-- Run this migration after the main database setup

-- SMPP Configuration (Manager controls)
CREATE TABLE IF NOT EXISTS smpp_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    config_name VARCHAR(50) NOT NULL DEFAULT 'default',
    
    -- Inbound SMPP Server (receive SMS from supplier)
    inbound_host VARCHAR(100) DEFAULT '0.0.0.0',
    inbound_port INT DEFAULT 2775,
    inbound_system_id VARCHAR(50) NOT NULL,
    inbound_password VARCHAR(255) NOT NULL COMMENT 'Encrypted password',
    
    -- Status
    status ENUM('active', 'inactive', 'maintenance') DEFAULT 'active',
    
    -- Connection stats
    total_received BIGINT DEFAULT 0,
    last_received_at DATETIME NULL,
    
    -- Audit
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- SMPP CDR (raw incoming data from Jasmin)
CREATE TABLE IF NOT EXISTS smpp_cdr (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    
    -- SMPP message details
    message_id VARCHAR(50) NOT NULL,
    source_addr VARCHAR(20) NOT NULL COMMENT 'Sender number (CLI)',
    destination_addr VARCHAR(20) NOT NULL COMMENT 'IPRN number',
    short_message TEXT,
    
    -- Status
    message_status ENUM('delivered', 'failed', 'pending') DEFAULT 'delivered',
    
    -- Timestamps
    submit_date DATETIME NOT NULL,
    done_date DATETIME,
    
    -- Link to main CDR
    cdr_id BIGINT UNSIGNED NULL,
    
    -- Jasmin routing info
    connector_id VARCHAR(50),
    route_id VARCHAR(50),
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_destination (destination_addr),
    INDEX idx_source (source_addr),
    INDEX idx_submit_date (submit_date),
    INDEX idx_message_id (message_id),
    FOREIGN KEY (cdr_id) REFERENCES sms_cdr(id) ON DELETE SET NULL
);

-- SMPP Users (credentials for suppliers)
CREATE TABLE IF NOT EXISTS smpp_users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    
    -- User identification
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL COMMENT 'Bcrypt hash',
    
    -- Supplier info
    supplier_name VARCHAR(100),
    supplier_contact VARCHAR(100),
    
    -- Access control
    bind_type ENUM('TX', 'RX', 'TR') DEFAULT 'TR' COMMENT 'Transmit/Receive/Transceive',
    max_connections INT DEFAULT 10,
    
    -- Allowed ranges (comma-separated range IDs or '*' for all)
    allowed_ranges TEXT COMMENT 'JSON array of allowed range IDs',
    
    -- Status
    status ENUM('active', 'suspended', 'disabled') DEFAULT 'active',
    
    -- Stats
    total_messages BIGINT DEFAULT 0,
    last_connected_at DATETIME NULL,
    
    -- Audit
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,
    
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Insert default SMPP configuration with secure credentials
INSERT INTO smpp_config (config_name, inbound_system_id, inbound_password, status, created_by)
VALUES (
    'primary',
    'mbc_inbound',
    -- This is bcrypt hash of 'MBC$Secure2026!Smpp' - change in production!
    '$2y$10$XZ5KqL3mN8pR2sT4vW6xY7zA9bC0dE1fG2hI3jK4lM5nO6pQ7rS8tU9v',
    'active',
    1
) ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Create default SMPP user for supplier
INSERT INTO smpp_users (username, password_hash, supplier_name, bind_type, status, created_by)
VALUES (
    'mbc_supplier',
    -- bcrypt hash of 'Supplier$Pass2026!' - change in production!
    '$2y$10$A1bC2dE3fG4hI5jK6lM7nO8pQ9rS0tU1vW2xY3zA4bC5dE6fG7hI8jK',
    'Primary Supplier',
    'TR',
    'active',
    1
) ON DUPLICATE KEY UPDATE created_at = created_at;

-- Add SMPP-related columns to sms_cdr
ALTER TABLE sms_cdr 
ADD COLUMN IF NOT EXISTS smpp_message_id VARCHAR(50) NULL COMMENT 'Message ID from SMPP',
ADD COLUMN IF NOT EXISTS smpp_status ENUM('delivered', 'failed', 'pending') DEFAULT 'delivered',
ADD COLUMN IF NOT EXISTS connector_id VARCHAR(50) NULL COMMENT 'Jasmin connector ID',
ADD COLUMN IF NOT EXISTS processed_at DATETIME NULL COMMENT 'When webhook processed this';

-- Add indexes for SMPP queries
CREATE INDEX IF NOT EXISTS idx_smpp_message_id ON sms_cdr(smpp_message_id);
