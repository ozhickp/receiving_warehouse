-- ============================================================
--  Migration: Stock In berbasis PO (header + multi item)
--  Jalankan SEKALI setelah schema.sql awal sudah ada di db_receiving
-- ============================================================
USE db_receiving;

-- ------------------------------------------------------------
-- Counter untuk No. Input (7 digit, 0000001 s/d 9999999)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS input_sequence (
    id          TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO input_sequence (id, last_number)
SELECT 1, 0 FROM (SELECT 1) AS dummy
WHERE NOT EXISTS (SELECT 1 FROM input_sequence WHERE id = 1);

-- ------------------------------------------------------------
-- Header Stock In: 1 baris = 1 kali submit form (1 PO/Surat Jalan)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_in_headers (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    no_input     CHAR(7)      NOT NULL UNIQUE COMMENT 'Auto, urut, 0000001-9999999',
    po_number    VARCHAR(50)  NOT NULL,
    supplier_id  INT UNSIGNED NOT NULL,
    surat_jalan  VARCHAR(50)  NOT NULL,
    created_by   INT UNSIGNED NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (created_by)  REFERENCES users(id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Tautkan setiap batch & transaksi item ke header PO-nya
-- (nullable agar data lama yang belum punya header tetap valid)
-- ------------------------------------------------------------
ALTER TABLE batches
    ADD COLUMN header_id INT UNSIGNED NULL AFTER created_by,
    ADD CONSTRAINT fk_batches_header FOREIGN KEY (header_id) REFERENCES stock_in_headers(id) ON DELETE SET NULL;

ALTER TABLE stock_transactions
    ADD COLUMN header_id INT UNSIGNED NULL AFTER created_by,
    ADD CONSTRAINT fk_trx_header FOREIGN KEY (header_id) REFERENCES stock_in_headers(id) ON DELETE SET NULL;
