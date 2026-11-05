-- Run once on an existing clinic database.
UPDATE users SET role='reception' WHERE role='cashier';
ALTER TABLE users MODIFY role ENUM('admin','reception','nurse','doctor','pharmacist') NOT NULL;

CREATE TABLE IF NOT EXISTS medical_aid_types (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL UNIQUE,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO medical_aid_types (name) VALUES ('Other');

CREATE TABLE IF NOT EXISTS payment_methods (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  method_key VARCHAR(40) NOT NULL UNIQUE,
  label      VARCHAR(80) NOT NULL UNIQUE,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO payment_methods (method_key,label,sort_order) VALUES
  ('cash','Cash',10),
  ('card','Card',20),
  ('mobile_money','Mobile money',30),
  ('insurance','Medical aid',40);

ALTER TABLE invoices MODIFY payment_method VARCHAR(40) NULL;
