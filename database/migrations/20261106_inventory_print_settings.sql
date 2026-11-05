-- Run once on installations created before 2026-11-06.
CREATE TABLE clinic_settings (
  id              TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  clinic_name     VARCHAR(160) NOT NULL,
  address         VARCHAR(255) NULL,
  phone           VARCHAR(80) NULL,
  email           VARCHAR(160) NULL,
  registration_no VARCHAR(80) NULL,
  footer_note     VARCHAR(255) NULL,
  updated_by      INT UNSIGNED NULL,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_clinic_settings_singleton CHECK (id = 1),
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO clinic_settings (id, clinic_name, phone)
VALUES (1, 'Good Samaritan Medical Center', '+263 775 606 149');

CREATE TABLE inventory_items (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku             VARCHAR(40) NULL UNIQUE,
  name            VARCHAR(160) NOT NULL,
  generic_name    VARCHAR(160) NULL,
  category        ENUM('medicine','consumable','equipment','other') NOT NULL DEFAULT 'medicine',
  unit            VARCHAR(40) NOT NULL DEFAULT 'unit',
  quantity_on_hand DECIMAL(12,2) NOT NULL DEFAULT 0,
  reorder_level   DECIMAL(12,2) NOT NULL DEFAULT 0,
  unit_cost       DECIMAL(10,2) NOT NULL DEFAULT 0,
  selling_price   DECIMAL(10,2) NOT NULL DEFAULT 0,
  expiry_date     DATE NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_inventory_stock (is_active, quantity_on_hand, reorder_level),
  INDEX idx_inventory_expiry (expiry_date),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE inventory_movements (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id         INT UNSIGNED NOT NULL,
  movement_type   ENUM('receipt','issue','adjustment') NOT NULL,
  quantity_delta  DECIMAL(12,2) NOT NULL,
  quantity_before DECIMAL(12,2) NOT NULL,
  quantity_after  DECIMAL(12,2) NOT NULL,
  reference_type  VARCHAR(40) NULL,
  reference_id    BIGINT UNSIGNED NULL,
  notes           VARCHAR(255) NULL,
  recorded_by     INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_inventory_movement_item (item_id, created_at),
  FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE prescriptions
  ADD COLUMN inventory_item_id INT UNSIGNED NULL AFTER visit_id,
  ADD CONSTRAINT fk_prescriptions_inventory_item
    FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL;
