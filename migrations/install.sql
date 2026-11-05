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
    ticket_letter CHAR(1) NULL,
    printer_config JSON,
    scanner_config JSON,
    hotkeys JSON,
    dataphone_mode ENUM('integrated','independent') NOT NULL DEFAULT 'integrated',
    printer_type ENUM('none','pdf','escpos','system','agent') NOT NULL DEFAULT 'pdf',
    store_name VARCHAR(120) NULL,
    store_address VARCHAR(255) NOT NULL DEFAULT '',
    legal_name VARCHAR(120) NULL,
    tax_id VARCHAR(16) NULL,
    ticket_header TEXT NULL,
    ticket_footer TEXT NULL,
    ticket_logo MEDIUMTEXT NULL,
    ticket_width_px INT NULL,
    -- Version00000045: ticket margins (0-10 mm, NULL = 2 mm) and logo width (20-100 %, NULL = 100).
    ticket_margin_left_mm DECIMAL(3,1) NULL,
    ticket_margin_right_mm DECIMAL(3,1) NULL,
    ticket_logo_width_pct TINYINT UNSIGNED NULL,
    ticket_code VARCHAR(10) NOT NULL DEFAULT 'barcode',
    stock_sync_interval_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    stock_push_interval_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    refund_window_days INT UNSIGNED NOT NULL DEFAULT 30,
    -- Version00000038: label height (25-60 mm) and one cut per label (L5, L6).
    label_height_mm TINYINT UNSIGNED NOT NULL DEFAULT 30,
    label_cut_each TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_terminal_ticket_letter (ticket_letter)
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
    -- Version00000031: the local sale (order_meta.id) of a sale movement
    order_id BIGINT UNSIGNED NULL,
    ps_order_slip_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES session(id),
    INDEX idx_ps_order (ps_order_id),
    INDEX idx_movement_order (order_id),
    INDEX idx_session_type (session_id, type)
) ENGINE=InnoDB;

-- 6. order_meta
-- Version00000031: local key `id`; `reference` is the ticket code (A26000123) and
-- the PrestaShop order reference; ps_order_id is set once the sale is uploaded;
-- ps_reference only when PrestaShop answered a different reference; synced = 0
-- while the upload is pending.
CREATE TABLE IF NOT EXISTS order_meta (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(9) NULL,
    ps_order_id INT UNSIGNED NULL,
    ps_reference VARCHAR(9) NULL,
    -- Version00000032: the browser's id of one payment attempt (a repeated checkout returns this sale)
    request_id VARCHAR(64) NULL,
    terminal_id INT UNSIGNED NOT NULL,
    session_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    payment_breakdown JSON NOT NULL,
    printed TINYINT(1) NOT NULL DEFAULT 0,
    print_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    printed_at DATETIME NULL,
    synced TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_order_meta_reference (reference),
    UNIQUE KEY uk_order_meta_ps_order (ps_order_id),
    UNIQUE KEY uk_order_meta_request (request_id),
    -- Version00000046: sales reports filter by date
    KEY idx_order_meta_created (created_at),
    KEY idx_order_meta_terminal_created (terminal_id, created_at)
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
    -- Version00000032: the local sale (order_meta.id); NULL for online orders and refunds without a ticket
    order_id BIGINT UNSIGNED NULL,
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
    INDEX idx_refund_sale_status (order_id, status),
    INDEX idx_refund_session (session_id),
    -- Version00000046: sales reports filter by completion date
    INDEX idx_refund_status_completed (status, completed_at)
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
    -- Version00000032: ps_order_id NULL while the returned sale is not uploaded; order_id / new_order_id are local sales
    ps_order_id INT UNSIGNED NULL,
    order_id BIGINT UNSIGNED NULL,
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
    new_order_id BIGINT UNSIGNED NULL,
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
    INDEX idx_exchange_order_status (ps_order_id, status),
    INDEX idx_exchange_sale_status (order_id, status)
) ENGINE=InnoDB;

-- credit_note_sequence (Version00000020): consecutive credit-note numbers per year
CREATE TABLE IF NOT EXISTS credit_note_sequence (
    year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

-- ticket_sequence (Version00000030): gapless ticket numbers per terminal letter and year (A26000123)
CREATE TABLE IF NOT EXISTS ticket_sequence (
    letter CHAR(1) NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    last_number INT UNSIGNED NOT NULL,
    PRIMARY KEY (letter, year)
) ENGINE=InnoDB;

-- outbox (Version00000031): uploads to PrestaShop still to do, in order per sale
-- (sequence 1 = the sale, then its refunds/exchanges); status pending, processing,
-- done or incident (definitive rejection, for the administrator). Types: sale,
-- refund (behind its sale), restock (refund without ticket, no sale: order_id NULL, V32).
-- discarded (V36): an incident the administrator gave up uploading, with who, when and why.
CREATE TABLE IF NOT EXISTS outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(32) NOT NULL,
    order_id BIGINT UNSIGNED NULL,
    sequence INT UNSIGNED NOT NULL,
    depends_on BIGINT UNSIGNED NULL,
    request_id VARCHAR(64) NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    record_failures INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claim_token VARCHAR(32) NULL,
    claimed_at DATETIME NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    done_at DATETIME NULL,
    discarded_at DATETIME NULL,
    discarded_by_user_id INT UNSIGNED NULL,
    discard_reason VARCHAR(255) NULL,
    UNIQUE KEY uk_outbox_request (request_id),
    UNIQUE KEY uk_outbox_order_sequence (order_id, sequence),
    KEY idx_outbox_due (status, next_attempt_at),
    KEY idx_outbox_depends_on (depends_on)
) ENGINE=InnoDB;

-- terminal_device (Version00000033): the computer linked to each till. Only the
-- SHA-256 of the browser's device token is stored; one device per till.
CREATE TABLE IF NOT EXISTS terminal_device (
    token_hash CHAR(64) NOT NULL,
    terminal_id INT UNSIGNED NOT NULL,
    linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NULL,
    linked_by_user_id INT UNSIGNED NULL,
    PRIMARY KEY (token_hash),
    UNIQUE KEY uk_terminal_device_terminal (terminal_id),
    CONSTRAINT fk_terminal_device_terminal FOREIGN KEY (terminal_id) REFERENCES terminal (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- customer (Version00000034): the till's copy of the PrestaShop customers
-- (tpv:customers:sync) plus the customers created at the till, which have no
-- ps_customer_id until their outbox row (outbox_id) is uploaded (PRD C1, C2).
CREATE TABLE IF NOT EXISTS customer (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ps_customer_id INT UNSIGNED NULL,
    firstname VARCHAR(255) NOT NULL,
    lastname VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(32) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    outbox_id BIGINT UNSIGNED NULL,
    merged_into INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    synced_at DATETIME NULL,
    UNIQUE KEY uk_customer_ps (ps_customer_id),
    KEY idx_customer_email (email),
    KEY idx_customer_name (lastname, firstname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- print_job (Version00000037): each till's print queue for its print agent
-- (docs/prd/agente-impresion.md T0-T9). Payload nulled 24 h after it finished,
-- rows deleted after 30 days (S4).
CREATE TABLE IF NOT EXISTS print_job (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    terminal_id INT UNSIGNED NOT NULL,
    type ENUM('ticket','drawer','label') NOT NULL,
    target ENUM('ticket','label') NOT NULL DEFAULT 'ticket',
    priority TINYINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('pending','claimed','done','failed','expired','cancelled') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    claim_token CHAR(32) NULL,
    lease_until DATETIME NULL,
    next_attempt_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    payload MEDIUMBLOB NULL,
    payload_size INT UNSIGNED NOT NULL DEFAULT 0,
    sha256 CHAR(64) NULL,
    source VARCHAR(32) NOT NULL,
    source_ref VARCHAR(64) NULL,
    created_by_user_id INT UNSIGNED NULL,
    error_code VARCHAR(64) NULL,
    error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    claimed_at DATETIME NULL,
    finished_at DATETIME NULL,
    KEY idx_print_job_queue (terminal_id, status, priority, id),
    KEY idx_print_job_created (created_at),
    KEY idx_print_job_finished (finished_at)
) ENGINE=InnoDB;

-- print_agent (Version00000037): the paired agent of each till; only the SHA-256
-- of its key is stored (E3).
CREATE TABLE IF NOT EXISTS print_agent (
    terminal_id INT UNSIGNED NOT NULL,
    key_hash CHAR(64) NOT NULL,
    paired_at DATETIME NOT NULL,
    paired_by_user_id INT UNSIGNED NULL,
    last_seen_at DATETIME NULL,
    last_ip VARCHAR(45) NULL,
    agent_version VARCHAR(32) NULL,
    hostname VARCHAR(255) NULL,
    os VARCHAR(64) NULL,
    printers JSON NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (terminal_id),
    UNIQUE KEY uk_print_agent_key (key_hash),
    CONSTRAINT fk_print_agent_terminal FOREIGN KEY (terminal_id) REFERENCES terminal (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- agent_pairing (Version00000037): 6-digit pairing codes, HMAC only, 10 minutes,
-- single use, void after 5 failures (E2, E7).
CREATE TABLE IF NOT EXISTS agent_pairing (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code_hash CHAR(64) NOT NULL,
    terminal_id INT UNSIGNED NOT NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    KEY idx_agent_pairing_code (code_hash),
    KEY idx_agent_pairing_terminal (terminal_id),
    CONSTRAINT fk_agent_pairing_terminal FOREIGN KEY (terminal_id) REFERENCES terminal (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- drawer_audit (Version00000037): every drawer opening (T11); never purged.
CREATE TABLE IF NOT EXISTS drawer_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    terminal_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    reason VARCHAR(32) NOT NULL,
    order_id BIGINT NULL,
    sale_reference VARCHAR(64) NULL,
    job_id BIGINT UNSIGNED NULL,
    printer_type VARCHAR(16) NOT NULL,
    result VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    KEY idx_drawer_audit_terminal (terminal_id, created_at),
    KEY idx_drawer_audit_job (job_id)
) ENGINE=InnoDB;

-- rate_limit (Version00000037): one-minute windows for pairing and invalid agent
-- keys (E7).
CREATE TABLE IF NOT EXISTS rate_limit (
    rate_key VARCHAR(128) NOT NULL,
    window_start DATETIME NOT NULL,
    count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (rate_key, window_start),
    KEY idx_rate_limit_window (window_start)
) ENGINE=InnoDB;

-- fiscal_record (Version00000042): one append-only record per issued ticket (F2)
-- and credit note (R5), chained by the AEAT fingerprint of the previous record.
-- The amounts and dates are kept as the exact strings that were hashed.
CREATE TABLE IF NOT EXISTS fiscal_record (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    record_type VARCHAR(10) NOT NULL DEFAULT 'alta',
    invoice_type CHAR(2) NOT NULL,
    issuer_nif VARCHAR(16) NOT NULL,
    number VARCHAR(60) NOT NULL,
    issue_date CHAR(10) NOT NULL,
    tax_total CHAR(16) NOT NULL,
    total CHAR(16) NOT NULL,
    previous_hash CHAR(64) NULL,
    hash CHAR(64) NOT NULL,
    generated_at CHAR(25) NOT NULL,
    rectified_number VARCHAR(60) NULL,
    order_id INT UNSIGNED NULL,
    refund_id INT UNSIGNED NULL,
    terminal_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uk_fiscal_record_order (order_id),
    UNIQUE KEY uk_fiscal_record_refund (refund_id),
    KEY idx_fiscal_record_number (number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fiscal_chain (Version00000042): the head of the chain, locked while a record is added.
CREATE TABLE IF NOT EXISTS fiscal_chain (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    last_record_id BIGINT UNSIGNED NULL,
    last_hash CHAR(64) NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO fiscal_chain (id, last_record_id, last_hash) VALUES (1, NULL, NULL);

-- numbering_change (Version00000044): audit of every change to the next ticket or
-- credit-note number (series A26 / R2026), with its reason; insert-only from the app.
CREATE TABLE IF NOT EXISTS numbering_change (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    series VARCHAR(16) NOT NULL,
    previous_last INT UNSIGNED NOT NULL,
    new_last INT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY idx_numbering_change_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sale_line (Version00000046): one row per ticket line with its VAT rate, base and
-- VAT amount (base + VAT = paid_amount), written in the sale transaction; the sales
-- reports and the full invoice read it. approximate = 1 when data came from the
-- current catalog (backfilled sales, unknown net price).
CREATE TABLE IF NOT EXISTS sale_line (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    line_index SMALLINT UNSIGNED NOT NULL,
    sold_at DATETIME NOT NULL,
    terminal_id INT UNSIGNED NOT NULL,
    session_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NULL,
    id_product_attribute INT UNSIGNED NOT NULL DEFAULT 0,
    reference VARCHAR(64) NULL,
    name VARCHAR(255) NOT NULL,
    category_id INT UNSIGNED NULL,
    category_name VARCHAR(255) NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    line_discount_pct DECIMAL(5,2) NULL DEFAULT 0,
    gross_amount DECIMAL(10,2) NOT NULL,
    paid_amount DECIMAL(10,2) NOT NULL,
    vat_rate DECIMAL(5,2) NULL,
    base_amount DECIMAL(10,2) NULL,
    vat_amount DECIMAL(10,2) NULL,
    approximate TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uk_sale_line_order_line (order_id, line_index),
    KEY idx_sale_line_sold (sold_at),
    KEY idx_sale_line_terminal_sold (terminal_id, sold_at),
    KEY idx_sale_line_product_sold (product_id, id_product_attribute, sold_at),
    KEY idx_sale_line_category_sold (category_id, sold_at),
    CONSTRAINT fk_sale_line_order FOREIGN KEY (order_id) REFERENCES order_meta (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
