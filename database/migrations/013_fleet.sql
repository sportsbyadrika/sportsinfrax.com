-- Migration 013: Fleet / Transport Management (school only)
-- Tables: transport_vehicles, transport_vehicle_services, transport_routes,
--         transport_route_stops, transport_route_fees,
--         transport_student_assignments, transport_fee_payments

CREATE TABLE IF NOT EXISTS transport_vehicles (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id    INT UNSIGNED NOT NULL,
  registration_no   VARCHAR(30)  NOT NULL,
  make              VARCHAR(60),
  model             VARCHAR(60),
  manufacture_year  YEAR,
  color             VARCHAR(30),
  capacity          TINYINT UNSIGNED NOT NULL DEFAULT 40,
  vehicle_type      ENUM('bus','minibus','van','car','other') NOT NULL DEFAULT 'bus',
  fuel_type         ENUM('diesel','petrol','cng','electric','other') NOT NULL DEFAULT 'diesel',
  chassis_no        VARCHAR(60),
  engine_no         VARCHAR(60),
  insurance_no      VARCHAR(80),
  insurance_expiry  DATE,
  fitness_expiry    DATE,
  permit_expiry     DATE,
  puc_expiry        DATE,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  notes             TEXT,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_veh_reg (institution_id, registration_no),
  KEY idx_veh_inst (institution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_vehicle_services (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vehicle_id       INT UNSIGNED NOT NULL,
  institution_id   INT UNSIGNED NOT NULL,
  service_date     DATE NOT NULL,
  service_type     ENUM('routine','repair','accident','insurance','fitness','permit','puc','other') NOT NULL DEFAULT 'routine',
  description      TEXT,
  odometer_km      INT UNSIGNED,
  cost             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  vendor           VARCHAR(120),
  next_service_date DATE,
  next_service_km  INT UNSIGNED,
  created_by       INT UNSIGNED,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vs_vehicle (vehicle_id),
  KEY idx_vs_inst (institution_id),
  CONSTRAINT fk_vs_vehicle FOREIGN KEY (vehicle_id) REFERENCES transport_vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_routes (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id     INT UNSIGNED NOT NULL,
  name               VARCHAR(120) NOT NULL,
  description        TEXT,
  vehicle_id         INT UNSIGNED,
  driver_name        VARCHAR(80),
  driver_phone       VARCHAR(20),
  helper_name        VARCHAR(80),
  morning_departure  TIME,
  evening_departure  TIME,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_rt_inst (institution_id),
  CONSTRAINT fk_rt_vehicle FOREIGN KEY (vehicle_id) REFERENCES transport_vehicles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_route_stops (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  route_id    INT UNSIGNED NOT NULL,
  institution_id INT UNSIGNED NOT NULL,
  stop_name   VARCHAR(120) NOT NULL,
  pickup_time TIME,
  drop_time   TIME,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_rs_route (route_id),
  CONSTRAINT fk_rs_route FOREIGN KEY (route_id) REFERENCES transport_routes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_route_fees (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  route_id         INT UNSIGNED NOT NULL,
  institution_id   INT UNSIGNED NOT NULL,
  academic_year_id INT UNSIGNED NOT NULL,
  amount           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  frequency        ENUM('monthly','quarterly','half_yearly','annual','one_time') NOT NULL DEFAULT 'monthly',
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_rtfee (route_id, academic_year_id),
  KEY idx_rf_inst (institution_id),
  CONSTRAINT fk_rf_route FOREIGN KEY (route_id) REFERENCES transport_routes(id) ON DELETE CASCADE,
  CONSTRAINT fk_rf_ay   FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_student_assignments (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id   INT UNSIGNED NOT NULL,
  student_id       INT UNSIGNED NOT NULL,
  route_id         INT UNSIGNED NOT NULL,
  stop_id          INT UNSIGNED,
  academic_year_id INT UNSIGNED NOT NULL,
  assigned_from    DATE,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  remarks          VARCHAR(255),
  created_by       INT UNSIGNED,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_sa (student_id, academic_year_id),
  KEY idx_sa_inst (institution_id),
  KEY idx_sa_route (route_id),
  CONSTRAINT fk_sa_student FOREIGN KEY (student_id)       REFERENCES students(id)                ON DELETE CASCADE,
  CONSTRAINT fk_sa_route   FOREIGN KEY (route_id)         REFERENCES transport_routes(id)         ON DELETE CASCADE,
  CONSTRAINT fk_sa_stop    FOREIGN KEY (stop_id)           REFERENCES transport_route_stops(id)   ON DELETE SET NULL,
  CONSTRAINT fk_sa_ay      FOREIGN KEY (academic_year_id) REFERENCES academic_years(id)           ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transport_fee_payments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  institution_id INT UNSIGNED NOT NULL,
  student_id     INT UNSIGNED NOT NULL,
  assignment_id  INT UNSIGNED NOT NULL,
  period_label   VARCHAR(30)  NOT NULL,
  amount         DECIMAL(10,2) NOT NULL,
  payment_date   DATE NOT NULL,
  payment_mode   ENUM('cash','card','upi','cheque','bank_transfer','other') NOT NULL DEFAULT 'cash',
  reference_no   VARCHAR(80),
  receipt_no     VARCHAR(40),
  remarks        VARCHAR(255),
  collected_by   INT UNSIGNED,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tfp_student (student_id),
  KEY idx_tfp_inst    (institution_id),
  KEY idx_tfp_assign  (assignment_id),
  CONSTRAINT fk_tfp_student FOREIGN KEY (student_id)    REFERENCES students(id)                       ON DELETE CASCADE,
  CONSTRAINT fk_tfp_assign  FOREIGN KEY (assignment_id) REFERENCES transport_student_assignments(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Menu items ────────────────────────────────────────────────────────────────
INSERT INTO `menu_items`
  (`item_key`, `parent_menu`, `label`, `route`, `icon`, `gradient`,
   `description`, `applies_to_category`, `required_role`, `sort_order`)
VALUES
  ('settings.transport_vehicles', 'settings', 'Vehicles',
   '/app/settings/transport-vehicles', 'bi-truck',
   'linear-gradient(135deg,#0b5ed7,#1e78ff)',
   'Manage your fleet: add vehicles, track compliance dates.',
   'school', 'institution_admin', 73),

  ('settings.transport_routes', 'settings', 'Transport Routes',
   '/app/settings/transport-routes', 'bi-signpost-2-fill',
   'linear-gradient(135deg,#059669,#10b981)',
   'Define bus routes, stops, and per-year fee rates.',
   'school', 'institution_admin', 74),

  ('services.vehicle_service', 'services', 'Vehicle Service Log',
   '/app/services/vehicle-service', 'bi-tools',
   'linear-gradient(135deg,#dc3545,#e85d6f)',
   'Log and track vehicle maintenance, repairs, and renewals.',
   'school', 'institution_admin', 53),

  ('services.transport_assignments', 'services', 'Transport Assignments',
   '/app/services/transport-assignments', 'bi-bus-front-fill',
   'linear-gradient(135deg,#d97706,#f59e0b)',
   'Assign students to bus routes for the academic year.',
   'school', 'any', 54),

  ('reports.transport_fees', 'reports', 'Transport Fee Report',
   '/app/reports/transport-fees', 'bi-bus-front',
   'linear-gradient(135deg,#6f42c1,#9c68f0)',
   'Route-wise and student-wise transport fee collection summary.',
   'school', 'institution_admin', 35)

ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `route`       = VALUES(`route`),
  `description` = VALUES(`description`),
  `sort_order`  = VALUES(`sort_order`);
