-- Good Samaritan Medical Center Management System
-- MySQL 8 / MariaDB 10.4+  |  Import with:  mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS balm_of_hope
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE balm_of_hope;

-- ---------------------------------------------------------------
-- Staff accounts (role based access control)
-- ---------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(120) NOT NULL,
  email         VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','reception','nurse','doctor','pharmacist') NOT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  failed_logins TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until  DATETIME NULL,
  last_login    DATETIME NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Patients (demographics + medical history)  -> "Register New Patient"
-- ---------------------------------------------------------------
CREATE TABLE patients (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_no       VARCHAR(20) NOT NULL UNIQUE,
  first_name       VARCHAR(80) NOT NULL,
  last_name        VARCHAR(80) NOT NULL,
  national_id      VARCHAR(30) NULL,
  date_of_birth    DATE NOT NULL,
  gender           ENUM('male','female','other') NOT NULL,
  phone            VARCHAR(30) NOT NULL,
  email            VARCHAR(160) NULL,
  address          VARCHAR(255) NULL,
  next_of_kin      VARCHAR(120) NULL,
  next_of_kin_phone VARCHAR(30) NULL,
  insurance_provider VARCHAR(120) NULL,
  insurance_no     VARCHAR(60) NULL,
  blood_group      ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-','unknown') DEFAULT 'unknown',
  allergies        TEXT NULL,
  chronic_conditions TEXT NULL,
  medical_history  TEXT NULL,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_name (last_name, first_name),
  INDEX idx_phone (phone),
  INDEX idx_nid (national_id),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE medical_aid_types (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL UNIQUE,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO medical_aid_types (name) VALUES ('Other');

CREATE TABLE payment_methods (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  method_key VARCHAR(40) NOT NULL UNIQUE,
  label      VARCHAR(80) NOT NULL UNIQUE,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO payment_methods (method_key,label,sort_order) VALUES
  ('cash','Cash',10),
  ('card','Card',20),
  ('mobile_money','Mobile money',30),
  ('insurance','Medical aid',40);

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

-- ---------------------------------------------------------------
-- Visits: one row per trip through the activity flow
-- status mirrors the flowchart:
--  waiting_nurse -> triage -> waiting_doctor -> consultation
--  -> disposition -> billing -> departed
-- ---------------------------------------------------------------
CREATE TABLE visits (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id    INT UNSIGNED NOT NULL,
  status        ENUM('waiting_nurse','triage','waiting_doctor','consultation',
                     'disposition','billing','departed') NOT NULL DEFAULT 'waiting_nurse',
  priority      ENUM('routine','urgent','emergency') NOT NULL DEFAULT 'routine',
  arrived_at    DATETIME NOT NULL,
  nurse_id      INT UNSIGNED NULL,
  doctor_id     INT UNSIGNED NULL,
  departed_at   DATETIME NULL,
  registered_by INT UNSIGNED NULL,
  INDEX idx_status (status, priority, arrived_at),
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE RESTRICT,
  FOREIGN KEY (nurse_id)   REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (doctor_id)  REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (registered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Initial Triage & Vitals
CREATE TABLE triage (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id        INT UNSIGNED NOT NULL UNIQUE,
  temperature_c   DECIMAL(4,1) NULL,
  bp_systolic     SMALLINT UNSIGNED NULL,
  bp_diastolic    SMALLINT UNSIGNED NULL,
  pulse_bpm       SMALLINT UNSIGNED NULL,
  resp_rate       SMALLINT UNSIGNED NULL,
  spo2            TINYINT UNSIGNED NULL,
  weight_kg       DECIMAL(5,1) NULL,
  height_cm       DECIMAL(5,1) NULL,
  chief_complaint TEXT NOT NULL,
  health_condition TEXT NULL,
  recorded_by     INT UNSIGNED NULL,
  recorded_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Medical Consultation & Exam
CREATE TABLE consultations (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id     INT UNSIGNED NOT NULL UNIQUE,
  examination  TEXT NULL,
  diagnosis    TEXT NOT NULL,
  treatment_plan TEXT NULL,
  lab_requested TINYINT(1) NOT NULL DEFAULT 0,
  imaging_requested TINYINT(1) NOT NULL DEFAULT 0,
  doctor_id    INT UNSIGNED NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
  FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Decision point: Medication -> Prescription / Pharmacy Dispensing
CREATE TABLE prescriptions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id    INT UNSIGNED NOT NULL,
  inventory_item_id INT UNSIGNED NULL,
  drug_name   VARCHAR(160) NOT NULL,
  dosage      VARCHAR(80) NOT NULL,
  frequency   VARCHAR(80) NOT NULL,
  duration    VARCHAR(80) NOT NULL,
  quantity    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  unit_price  DECIMAL(10,2) NOT NULL DEFAULT 0,
  dispensed   TINYINT(1) NOT NULL DEFAULT 0,
  dispensed_by INT UNSIGNED NULL,
  dispensed_at DATETIME NULL,
  FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
  FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL,
  FOREIGN KEY (dispensed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Decision point: Specialist / Lab work -> Refer to Lab / Diagnostics / Specialty
CREATE TABLE referrals (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id   INT UNSIGNED NOT NULL,
  type       ENUM('lab','diagnostics','specialist') NOT NULL,
  destination VARCHAR(160) NOT NULL,
  reason     TEXT NOT NULL,
  fee        DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Decision point: Follow-up -> Arrange Follow-up Appointment
CREATE TABLE appointments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_id  INT UNSIGNED NOT NULL,
  visit_id    INT UNSIGNED NULL,
  doctor_id   INT UNSIGNED NULL,
  scheduled_for DATETIME NOT NULL,
  reason      VARCHAR(255) NULL,
  status      ENUM('scheduled','completed','cancelled','missed') NOT NULL DEFAULT 'scheduled',
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sched (scheduled_for, status),
  FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  FOREIGN KEY (visit_id)   REFERENCES visits(id) ON DELETE SET NULL,
  FOREIGN KEY (doctor_id)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Billing & Checkout (reception handles cashier duties; insurance is legacy key for medical aid)
CREATE TABLE invoices (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id     INT UNSIGNED NOT NULL UNIQUE,
  consultation_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  medication_total DECIMAL(10,2) NOT NULL DEFAULT 0,
  referral_total   DECIMAL(10,2) NOT NULL DEFAULT 0,
  total        DECIMAL(10,2) NOT NULL DEFAULT 0,
  insurance_covered DECIMAL(10,2) NOT NULL DEFAULT 0,
  amount_paid  DECIMAL(10,2) NOT NULL DEFAULT 0,
  payment_method VARCHAR(40) NULL,
  status       ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  cashier_id   INT UNSIGNED NULL,
  paid_at      DATETIME NULL,
  FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
  FOREIGN KEY (cashier_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- Security tables
-- ---------------------------------------------------------------
CREATE TABLE audit_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(80) NOT NULL,
  detail     VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- Public contact / appointment requests from the website
CREATE TABLE enquiries (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL,
  phone      VARCHAR(30) NOT NULL,
  email      VARCHAR(160) NULL,
  message    TEXT NOT NULL,
  ip_address VARCHAR(45) NULL,
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default admin is created by running  php database/install_admin.php
-- (no default password is shipped in this file on purpose).
