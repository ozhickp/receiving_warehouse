-- ============================================================
--  Receiving Warehouse System
--  Database : db_receiving
--  Versi    : 2.0
-- ============================================================

CREATE DATABASE IF NOT EXISTS db_receiving CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_receiving;

-- ------------------------------------------------------------
-- Users
-- role: admin | user | manager
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(60)  NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    full_name   VARCHAR(100) NOT NULL,
    role        ENUM('admin','user','manager') NOT NULL DEFAULT 'user',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default admin (password: admin123)
INSERT INTO users (username, password, full_name, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin')
ON DUPLICATE KEY UPDATE id = id;

-- ------------------------------------------------------------
-- Suppliers
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS suppliers (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(20)  NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    contact     VARCHAR(100),
    phone       VARCHAR(30),
    address     TEXT,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Material Categories
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description TEXT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Raw Materials (master)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS materials (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(30)  NOT NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    category_id     INT UNSIGNED,
    unit            VARCHAR(20)  NOT NULL DEFAULT 'pcs',
    weight_per_unit DECIMAL(12,4) COMMENT 'Berat per satuan unit (kg), dipakai oleh weighing system',
    stock_current   DECIMAL(12,3) NOT NULL DEFAULT 0,
    stock_minimum   DECIMAL(12,3) NOT NULL DEFAULT 0,
    description     TEXT,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Stock Transactions (IN / OUT / ADJUST)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_transactions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_no   VARCHAR(30)  NOT NULL UNIQUE,
    type             ENUM('IN','OUT','ADJUST') NOT NULL,
    material_id      INT UNSIGNED NOT NULL,
    supplier_id      INT UNSIGNED,
    qty              DECIMAL(12,3) NOT NULL,
    qty_before       DECIMAL(12,3) NOT NULL,
    qty_after        DECIMAL(12,3) NOT NULL,
    po_number        VARCHAR(50),
    do_number        VARCHAR(50)   COMMENT 'Nomor DO / Surat Jalan dari supplier',
    notes            TEXT,
    transaction_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by       INT UNSIGNED NOT NULL,
    FOREIGN KEY (material_id) REFERENCES materials(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by)  REFERENCES users(id)
) ENGINE=InnoDB;

-- Trigger: auto-update stock_current setelah INSERT transaksi
DELIMITER $$
CREATE TRIGGER trg_stock_after_insert
AFTER INSERT ON stock_transactions
FOR EACH ROW
BEGIN
    IF NEW.type = 'IN' THEN
        UPDATE materials SET stock_current = stock_current + NEW.qty WHERE id = NEW.material_id;
    ELSEIF NEW.type = 'OUT' THEN
        UPDATE materials SET stock_current = stock_current - NEW.qty WHERE id = NEW.material_id;
    ELSE
        UPDATE materials SET stock_current = NEW.qty_after WHERE id = NEW.material_id;
    END IF;
END$$
DELIMITER ;

-- ------------------------------------------------------------
-- Supplier Schedules (diinput PPIC, dikonfirmasi Receiving)
-- status: planned | confirmed | cancelled
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS supplier_schedules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schedule_date   DATE         NOT NULL,
    supplier_id     INT UNSIGNED NOT NULL,
    material_id     INT UNSIGNED NOT NULL,
    qty_expected    DECIMAL(12,3) NOT NULL,
    po_number       VARCHAR(50)  NOT NULL,
    notes           TEXT,
    status          ENUM('planned','confirmed','cancelled') NOT NULL DEFAULT 'planned',
    -- Diisi oleh Receiving saat konfirmasi
    do_number       VARCHAR(50)   COMMENT 'Nomor DO / Surat Jalan (diisi receiving)',
    qty_actual      DECIMAL(12,3) COMMENT 'Qty aktual yang diterima',
    item_condition  VARCHAR(255)  COMMENT 'Kondisi barang saat diterima',
    confirmed_by    INT UNSIGNED,
    confirmed_at    DATETIME,
    -- Audit
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id)  REFERENCES suppliers(id),
    FOREIGN KEY (material_id)  REFERENCES materials(id),
    FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by)   REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Weighing Records (Stock In berbasis timbangan)
-- Sebagai data pembanding, bukan acuan utama
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS weighing_records (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    record_no           VARCHAR(30)  NOT NULL UNIQUE,
    material_id         INT UNSIGNED NOT NULL,
    supplier_id         INT UNSIGNED NOT NULL,
    po_number           VARCHAR(50),
    do_number           VARCHAR(50),
    -- Input timbangan
    gross_weight        DECIMAL(12,3) NOT NULL COMMENT 'Berat total termasuk packaging (kg)',
    tare_weight         DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'Berat pallet / packaging (kg)',
    net_weight          DECIMAL(12,3) GENERATED ALWAYS AS (gross_weight - tare_weight) STORED COMMENT 'Berat bersih = gross - tare',
    -- Kalkulasi
    qty_per_box         INT UNSIGNED  NOT NULL COMMENT 'Jumlah pcs per box / unit',
    box_count           INT UNSIGNED  NOT NULL COMMENT 'Jumlah box / unit yang diterima',
    weight_per_unit_ref DECIMAL(12,4) COMMENT 'Berat per unit menurut spec (kg)',
    weight_per_unit_actual DECIMAL(12,4) GENERATED ALWAYS AS (
        CASE WHEN (qty_per_box * box_count) > 0
             THEN (gross_weight - tare_weight) / (qty_per_box * box_count)
             ELSE NULL END
    ) STORED COMMENT 'Berat per unit hasil timbang = net_weight / (qty_per_box * box_count)',
    qty_calculated      DECIMAL(12,3) GENERATED ALWAYS AS (qty_per_box * box_count) STORED COMMENT 'Qty estimasi = qty_per_box * box_count',
    -- Catatan & audit
    notes               TEXT,
    created_by          INT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (material_id) REFERENCES materials(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (created_by)  REFERENCES users(id)
) ENGINE=InnoDB;
