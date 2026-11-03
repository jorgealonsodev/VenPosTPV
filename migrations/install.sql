-- =============================================================================
-- VenPosTPV — Database Installation Script
-- =============================================================================
-- Run: mysql -u root venpostpv < migrations/install.sql
-- Migrations: 00001 (core) + 00002 (sso) + 00003 (catalog)
-- =============================================================================

-- 1. role
CREATE TABLE IF NOT EXISTS role (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(32) NOT NULL UNIQUE,
    permissions JSON NOT NULL
) ENGINE=InnoDB;

-- 2. terminal
CREATE TABLE IF NOT EXISTS terminal (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ps_shop_id INT UNSIGNED NOT NULL,
    name VARCHAR(64) NOT NULL,
    printer_config JSON,
    scanner_config JSON,
    hotkeys JSON,
    dataphone_mode ENUM('integrated','independent') NOT NULL DEFAULT 'integrated',
    printer_type ENUM('none','pdf','escpos','system') NOT NULL DEFAULT 'pdf',
    store_address VARCHAR(255) NOT NULL DEFAULT '',
    legal_name VARCHAR(120) NULL,
    tax_id VARCHAR(16) NULL,
    ticket_header TEXT NULL,
    ticket_footer TEXT NULL,
    ticket_logo MEDIUMTEXT NULL,
    ticket_width_px INT NULL,
    ticket_code VARCHAR(10) NOT NULL DEFAULT 'barcode',
    stock_sync_interval_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    stock_push_interval_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    refund_window_days INT UNSIGNED NOT NULL DEFAULT 30,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. user
CREATE TABLE IF NOT EXISTS user (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ps_employee_id INT UNSIGNED NULL,
    role_id INT UNSIGNED NOT NULL,
    name VARCHAR(128) NOT NULL,
    code VARCHAR(32) NULL,
    pin_hash VARCHAR(255) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    login_failures TINYINT UNSIGNED NOT NULL DEFAULT 0,
    locked_at DATETIME NULL,
    UNIQUE KEY uq_user_code (code),
    INDEX idx_user_code (code),
    FOREIGN KEY (role_id) REFERENCES role(id)
) ENGINE=InnoDB;

-- 4. session
CREATE TABLE IF NOT EXISTS session (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    terminal_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    opening_cash DECIMAL(10,2) NOT NULL,
    counted_cash DECIMAL(10,2) NULL,
    expected_cash DECIMAL(10,2) NULL,
    difference DECIMAL(10,2) NULL,
    FOREIGN KEY (terminal_id) REFERENCES terminal(id),
    FOREIGN KEY (user_id) REFERENCES user(id)
) ENGINE=InnoDB;

-- 5. movement
CREATE TABLE IF NOT EXISTS movement (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    type ENUM('sale','refund','cash_in','cash_out') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    concept VARCHAR(255),
    ps_order_id INT UNSIGNED NULL,
    ps_order_slip_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES session(id),
    INDEX idx_ps_order (ps_order_id),
    INDEX idx_session_type (session_id, type)
) ENGINE=InnoDB;

-- 6. order_meta
CREATE TABLE IF NOT EXISTS order_meta (
    ps_order_id INT UNSIGNED PRIMARY KEY,
    terminal_id INT UNSIGNED NOT NULL,
    session_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    payment_breakdown JSON NOT NULL,
    printed TINYINT(1) NOT NULL DEFAULT 0,
    synced TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 7. offline_queue
CREATE TABLE IF NOT EXISTS offline_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    terminal_id INT UNSIGNED NOT NULL,
    payload JSON NOT NULL,
    status ENUM('pending','processing','done','error') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    INDEX idx_status_created (status, created_at)
) ENGINE=InnoDB;

-- 8. audit_log
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    terminal_id INT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    target VARCHAR(128) NULL,
    payload JSON NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_action (action)
) ENGINE=InnoDB;

-- 9. z_report
CREATE TABLE IF NOT EXISTS z_report (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL UNIQUE,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    totals JSON NOT NULL,
    signature_hash CHAR(64) NOT NULL,
    pdf_path VARCHAR(255),
    FOREIGN KEY (session_id) REFERENCES session(id)
) ENGINE=InnoDB;

-- 10. messenger_messages
CREATE TABLE IF NOT EXISTS messenger_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    body LONGTEXT NOT NULL,
    headers LONGTEXT NOT NULL,
    queue_name VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    available_at DATETIME NOT NULL,
    delivered_at DATETIME DEFAULT NULL,
    INDEX idx_queue (queue_name),
    INDEX idx_available (available_at)
) ENGINE=InnoDB;

-- =============================================================================
-- Migration 00002: SSO
-- =============================================================================

-- 11. sso_nonce
CREATE TABLE IF NOT EXISTS sso_nonce (
    nonce VARCHAR(64) PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =============================================================================
-- Migration 00003: Catalog
-- =============================================================================

-- 12. catalog_product
CREATE TABLE IF NOT EXISTS catalog_product (
    id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    reference VARCHAR(64),
    ean13 VARCHAR(13),
    price DECIMAL(20,6) NOT NULL,
    price_ttc DECIMAL(20,6) NULL,
    price_net DECIMAL(20,6) NULL,
    id_category_default INT UNSIGNED,
    active TINYINT(1) NOT NULL DEFAULT 1,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 13. catalog_combination
CREATE TABLE IF NOT EXISTS catalog_combination (
    id INT UNSIGNED PRIMARY KEY,
    id_product INT UNSIGNED NOT NULL,
    reference VARCHAR(64),
    ean13 VARCHAR(13),
    price_impact DECIMAL(20,6) NOT NULL DEFAULT 0,
    price_ttc DECIMAL(20,6) NULL,
    price_net DECIMAL(20,6) NULL,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product (id_product),
    INDEX idx_ean13 (ean13)
) ENGINE=InnoDB;

-- 14. catalog_stock
CREATE TABLE IF NOT EXISTS catalog_stock (
    id_product INT UNSIGNED NOT NULL,
    id_product_attribute INT UNSIGNED NOT NULL DEFAULT 0,
    quantity INT NOT NULL DEFAULT 0,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ps_stock_id INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (id_product, id_product_attribute)
) ENGINE=InnoDB;

-- 15. catalog_category
CREATE TABLE IF NOT EXISTS catalog_category (
    id INT UNSIGNED PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    id_parent INT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 16. parked_ticket
CREATE TABLE IF NOT EXISTS parked_ticket (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_ref VARCHAR(64) NOT NULL,
    terminal_id INT UNSIGNED NOT NULL DEFAULT 1,
    user_id INT UNSIGNED NULL,
    cashier_name VARCHAR(128) NOT NULL DEFAULT 'Cajero',
    note VARCHAR(120) NULL,
    customer JSON NULL,
    lines_json JSON NOT NULL,
    discount JSON NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_parked_ticket_client_ref (client_ref),
    INDEX idx_parked_ticket_created (created_at),
    INDEX idx_parked_ticket_terminal (terminal_id)
) ENGINE=InnoDB;

-- refund (Version00000019): one row per refund attempt, written before PrestaShop is called
CREATE TABLE IF NOT EXISTS refund (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(64) NOT NULL,
    status ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
    ps_order_id INT UNSIGNED NULL,
    ps_order_slip_id INT UNSIGNED NULL,
    credit_note_number VARCHAR(20) NULL,
    terminal_id INT UNSIGNED NOT NULL,
    session_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    reason_code VARCHAR(32) NOT NULL,
    reason_text VARCHAR(255) NULL,
    total DECIMAL(10,2) NOT NULL,
    cash_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    card_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    card_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    voucher_code VARCHAR(32) NULL,
    voucher_amount DECIMAL(10,2) NULL,
    voucher_cart_rule_id INT UNSIGNED NULL,
    voucher_requested_at DATETIME NULL,
    exchange_credit DECIMAL(10,2) NULL,
    online TINYINT(1) NOT NULL DEFAULT 0,
    web_refund_amount DECIMAL(10,2) NULL,
    source_reference VARCHAR(32) NULL,
    source_date DATETIME NULL,
    ps_payload JSON NULL,
    error_message VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uk_refund_request (request_id),
    UNIQUE KEY uk_refund_credit_note (credit_note_number),
    INDEX idx_refund_order_status (ps_order_id, status),
    INDEX idx_refund_session (session_id)
) ENGINE=InnoDB;

-- refund_line (Version00000019): quantities already returned per sale line
CREATE TABLE IF NOT EXISTS refund_line (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    refund_id BIGINT UNSIGNED NOT NULL,
    line_index INT UNSIGNED NULL,
    product_id INT UNSIGNED NOT NULL,
    id_product_attribute INT UNSIGNED NOT NULL DEFAULT 0,
    name VARCHAR(255) NULL,
    quantity INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    restock TINYINT(1) NOT NULL DEFAULT 1,
    restocked TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_refund_line_refund FOREIGN KEY (refund_id) REFERENCES refund (id) ON DELETE CASCADE,
    INDEX idx_refund_line_refund (refund_id)
) ENGINE=InnoDB;

-- exchange (Version00000024): a refund whose credit pays a new sale, resumable when the sale fails
CREATE TABLE IF NOT EXISTS exchange (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    ps_order_id INT UNSIGNED NOT NULL,
    refund_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    credit DECIMAL(10,2) NOT NULL,
    credit_applied DECIMAL(10,2) NOT NULL,
    new_total DECIMAL(10,2) NOT NULL,
    balance DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(16) NOT NULL,
    amount_tendered DECIMAL(10,2) NOT NULL DEFAULT 0,
    cart_data JSON NOT NULL,
    new_ps_order_id INT UNSIGNED NULL,
    new_reference VARCHAR(32) NULL,
    -- Version00000026: voucher issued for an open credit that closes the exchange (closed_voucher)
    voucher_code VARCHAR(32) NULL,
    voucher_amount DECIMAL(10,2) NULL,
    voucher_cart_rule_id INT UNSIGNED NULL,
    voucher_requested_at DATETIME NULL,
    voucher_session_id INT UNSIGNED NULL,
    error_message VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uk_exchange_refund (refund_id),
    INDEX idx_exchange_order_status (ps_order_id, status)
) ENGINE=InnoDB;

-- credit_note_sequence (Version00000020): consecutive credit-note numbers per year
CREATE TABLE IF NOT EXISTS credit_note_sequence (
    year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL
) ENGINE=InnoDB;
