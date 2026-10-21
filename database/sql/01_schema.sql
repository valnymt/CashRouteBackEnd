-- =====================================================================
-- CashRoute — database schema (MySQL 8 / MariaDB 10.4+, XAMPP)
--
-- Based on the team ERD, adjusted to:
--   * Laravel conventions (plural snake_case tables, `id` BIGINT PKs,
--     created_at / updated_at) so Eloquent models work without config;
--   * what the Admin Portal already sends and shows (see
--     CashRoute_Admin_Portal/src/types/index.ts and services/httpApi.ts);
--   * the Requirement Specification (AP-01..AP-10, DA-01..DA-08).
--
-- Run:   mysql -u root < 01_schema.sql      (then 02_seed.sql)
-- or paste into phpMyAdmin → SQL tab.
--
-- The team builds the database with these SQL files, NOT with
-- `php artisan migrate`. Section 0 therefore creates Laravel's own
-- tables (sessions, tokens, cache, jobs) — Laravel needs them to run.
-- Table changes go in new numbered files (03_..., 04_...) with ALTER TABLE.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS cashroute CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cashroute;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. Roles & permissions (AP-03)
--    Default roles: Super Admin, Maker, Checker, Driver, Custodian.
--    Super Admin can add custom roles from the same permission set.
-- ---------------------------------------------------------------------
CREATE TABLE roles (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(50)  NOT NULL UNIQUE,              -- 'Super Admin', 'Maker', ...
  channel       ENUM('portal','mobile') NOT NULL DEFAULT 'portal', -- where this role signs in
  is_system     BOOLEAN      NOT NULL DEFAULT FALSE,       -- default roles can't be deleted
  created_at    TIMESTAMP NULL,
  updated_at    TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(80)  NOT NULL UNIQUE,              -- 'requests.approve'
  name          VARCHAR(120) NOT NULL,                     -- 'Approve or reject requests'
  created_at    TIMESTAMP NULL,
  updated_at    TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE role_permission (
  role_id       BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role       FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
  CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. Branches (AP-02)
-- ---------------------------------------------------------------------
CREATE TABLE branches (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(20)  NOT NULL UNIQUE,            -- 'BR-001', 'HQ-001'
  name            VARCHAR(120) NOT NULL,
  address         TEXT         NOT NULL,
  lat             DECIMAL(10,7) NOT NULL,
  lng             DECIMAL(10,7) NOT NULL,                  -- ERD `long` (reserved word)
  handover_radius INT UNSIGNED NOT NULL DEFAULT 150,       -- metres, geo-fence
  contact_name    VARCHAR(120) NOT NULL,                   -- OTP recipient (all required in the form)
  contact_phone   VARCHAR(30)  NOT NULL,
  contact_email   VARCHAR(160) NOT NULL,
  is_hq           BOOLEAN NOT NULL DEFAULT FALSE,          -- HQ / cash centre: the other end of every trip
  is_active       BOOLEAN NOT NULL DEFAULT TRUE,           -- deactivated branches can't take new requests
  created_at      TIMESTAMP NULL,
  updated_at      TIMESTAMP NULL,
  CONSTRAINT chk_branch_radius CHECK (handover_radius BETWEEN 10 AND 2000)
) ENGINE=InnoDB;

-- Bank's master list of branches (GET /api/branch-directory). Add Branch
-- only offers entries whose code is not in `branches` yet.
-- Replace with the core-banking feed later (out of scope this phase).
CREATE TABLE branch_directory (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(20)  NOT NULL UNIQUE,
  name        VARCHAR(120) NOT NULL,
  address     TEXT         NOT NULL,
  lat         DECIMAL(10,7) NOT NULL,
  lng         DECIMAL(10,7) NOT NULL,
  created_at  TIMESTAMP NULL,
  updated_at  TIMESTAMP NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. Users (AP-01, AP-03, DA-01) — Laravel's users table, extended.
--    One table for portal staff and delivery team members; `role_id`
--    decides which app they can sign in to (roles.channel).
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id          VARCHAR(20)  NOT NULL UNIQUE,       -- 'U-017', shown in User Accounts
  name                 VARCHAR(120) NOT NULL,
  username             VARCHAR(60)  NOT NULL UNIQUE,
  email                VARCHAR(160) NOT NULL UNIQUE,
  phone                VARCHAR(30)  NULL,
  password             VARCHAR(255) NOT NULL,              -- bcrypt hash (Hash::make), never plain text
  must_change_password BOOLEAN NOT NULL DEFAULT TRUE,      -- default password set in Add User
  role_id              BIGINT UNSIGNED NOT NULL,
  branch_id            BIGINT UNSIGNED NULL,               -- office staff's branch / HQ
  is_delivery_member   BOOLEAN NOT NULL DEFAULT FALSE,     -- ERD `member` (Driver / Custodian)
  is_active            BOOLEAN NOT NULL DEFAULT TRUE,      -- "deactivate user"
  is_locked            BOOLEAN NOT NULL DEFAULT FALSE,     -- after repeated failed logins
  failed_attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,-- ERD `failed-attempt`; lock at settings.max_failed_logins
  locked_at            TIMESTAMP NULL,
  last_login_at        TIMESTAMP NULL,
  email_verified_at    TIMESTAMP NULL,
  remember_token       VARCHAR(100) NULL,
  created_at           TIMESTAMP NULL,
  updated_at           TIMESTAMP NULL,
  CONSTRAINT fk_users_role   FOREIGN KEY (role_id)   REFERENCES roles(id),
  CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. Delivery teams (AP-03, AP-07)
--    A team must have at least 1 driver + 2 custodians to be confirmed;
--    only confirmed, available teams can be assigned. (Checked by the
--    app when confirming — SQL can't count roles across rows cheaply.)
-- ---------------------------------------------------------------------
CREATE TABLE teams (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(60) NOT NULL UNIQUE,               -- 'Team A'
  status        ENUM('available','on_trip','offline') NOT NULL DEFAULT 'available', -- ERD is_available
  is_confirmed  BOOLEAN NOT NULL DEFAULT FALSE,            -- "Confirm team"; any member change resets it
  confirmed_at  TIMESTAMP NULL,
  confirmed_by  BIGINT UNSIGNED NULL,
  created_at    TIMESTAMP NULL,
  updated_at    TIMESTAMP NULL,
  CONSTRAINT fk_teams_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE team_members (
  team_id     BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NULL,
  updated_at  TIMESTAMP NULL,
  PRIMARY KEY (team_id, user_id),
  UNIQUE KEY uq_team_members_user (user_id),               -- one team per person
  CONSTRAINT fk_tm_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_tm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. Cash requests (AP-04..AP-07) — ERD `REQUEST`
--    Named cash_requests: `Request` clashes with Laravel's Request class.
-- ---------------------------------------------------------------------
CREATE TABLE cash_requests (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference         VARCHAR(20) NOT NULL UNIQUE,           -- 'REQ-1053'
  type              ENUM('deposit','withdrawal') NOT NULL, -- deposit: branch → HQ, withdrawal: HQ → branch
  branch_id         BIGINT UNSIGNED NOT NULL,
  amount            DECIMAL(15,2) NOT NULL,
  currency          CHAR(3) NOT NULL DEFAULT 'MMK',
  denominations     JSON NULL,                             -- optional, e.g. {"10000": 15, "5000": 20}
  requested_date    DATE NOT NULL,
  complete_within   ENUM('1h','3h','5h','10h','12h','1d','2d') NOT NULL, -- "Complete within" dropdown
  due_at            DATETIME NULL,                         -- requested_date + window; for on-time vs late
  remarks           TEXT NULL,
  status            ENUM('pending','approved','assigned','in_transit','completed','rejected','cancelled')
                    NOT NULL DEFAULT 'pending',
  created_by        BIGINT UNSIGNED NOT NULL,              -- Maker
  approved_by       BIGINT UNSIGNED NULL,                  -- Checker
  approved_at       TIMESTAMP NULL,
  rejected_by       BIGINT UNSIGNED NULL,
  rejected_at       TIMESTAMP NULL,
  reject_reason     TEXT NULL,                             -- required when rejected
  cancelled_by      BIGINT UNSIGNED NULL,
  cancelled_by_role ENUM('maker','checker') NULL,          -- maker = withdrew own Pending request;
                                                           -- checker = cancelled Approved/Assigned
                                                           -- (only checker cancels show under Approvals → Cancelled)
  cancelled_at      TIMESTAMP NULL,
  cancel_reason     TEXT NULL,
  completed_at      TIMESTAMP NULL,
  created_at        TIMESTAMP NULL,
  updated_at        TIMESTAMP NULL,
  KEY idx_cr_status (status),
  KEY idx_cr_branch_date (branch_id, requested_date),
  CONSTRAINT fk_cr_branch       FOREIGN KEY (branch_id)    REFERENCES branches(id),
  CONSTRAINT fk_cr_created_by   FOREIGN KEY (created_by)   REFERENCES users(id),
  CONSTRAINT fk_cr_approved_by  FOREIGN KEY (approved_by)  REFERENCES users(id),
  CONSTRAINT fk_cr_rejected_by  FOREIGN KEY (rejected_by)  REFERENCES users(id),
  CONSTRAINT fk_cr_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id),
  CONSTRAINT chk_cr_amount      CHECK (amount > 0),
  -- Maker–checker: the creator can never approve or reject their own request.
  CONSTRAINT chk_cr_maker_checker CHECK (
    (approved_by IS NULL OR approved_by <> created_by) AND
    (rejected_by IS NULL OR rejected_by <> created_by)
  )
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. Trips & live tracking (AP-07, AP-08, DA-03..DA-05)
--    One trip per request. Reassigning before the trip starts updates
--    team_id (the change is logged in audit_logs).
-- ---------------------------------------------------------------------
CREATE TABLE trips (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cash_request_id  BIGINT UNSIGNED NOT NULL UNIQUE,
  team_id          BIGINT UNSIGNED NOT NULL,
  assigned_by      BIGINT UNSIGNED NOT NULL,               -- Checker
  status           ENUM('assigned','in_transit','completed','cancelled') NOT NULL DEFAULT 'assigned',
  current_lat      DECIMAL(10,7) NULL,                     -- latest position
  current_lng      DECIMAL(10,7) NULL,
  last_ping_at     TIMESTAMP NULL,                         -- "Stale GPS" when older than settings.stale_gps_seconds
  assigned_at      TIMESTAMP NULL,
  accepted_at      TIMESTAMP NULL,                         -- team accepts → request In Transit
  arrived_pickup_at  TIMESTAMP NULL,
  arrived_dropoff_at TIMESTAMP NULL,
  started_at       TIMESTAMP NULL,
  completed_at     TIMESTAMP NULL,                         -- duration = completed_at - started_at
  is_on_time       BOOLEAN NULL,                           -- completed_at <= cash_requests.due_at (set on completion)
  created_at       TIMESTAMP NULL,
  updated_at       TIMESTAMP NULL,
  KEY idx_trips_status (status),
  CONSTRAINT fk_trips_request     FOREIGN KEY (cash_request_id) REFERENCES cash_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_trips_team        FOREIGN KEY (team_id)         REFERENCES teams(id),
  CONSTRAINT fk_trips_assigned_by FOREIGN KEY (assigned_by)     REFERENCES users(id)
) ENGINE=InnoDB;

-- GPS pings sent at a fixed interval while In Transit (route line on the live map).
CREATE TABLE trip_locations (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id      BIGINT UNSIGNED NOT NULL,
  lat          DECIMAL(10,7) NOT NULL,
  lng          DECIMAL(10,7) NOT NULL,
  accuracy_m   DECIMAL(6,1) NULL,
  recorded_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tl_trip_time (trip_id, recorded_at),
  CONSTRAINT fk_tl_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. OTP handover verification (DA-06)
-- ---------------------------------------------------------------------
CREATE TABLE otps (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cash_request_id  BIGINT UNSIGNED NOT NULL,
  stage            ENUM('pickup','dropoff') NOT NULL,      -- ERD type(pick-up/drop)
  code_hash        VARCHAR(255) NOT NULL,                  -- hash of the code, never the code itself
  sent_to          VARCHAR(160) NOT NULL,                  -- contact phone / email
  channel          ENUM('sms','email') NOT NULL DEFAULT 'email',
  expires_at       TIMESTAMP NOT NULL,                     -- ERD otp_expire_at
  attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,    -- limit = settings.otp_max_attempts
  status           ENUM('pending','verified','expired','failed') NOT NULL DEFAULT 'pending',
  requested_by     BIGINT UNSIGNED NOT NULL,               -- team member who tapped Request OTP
  verified_at      TIMESTAMP NULL,
  created_at       TIMESTAMP NULL,
  updated_at       TIMESTAMP NULL,
  KEY idx_otps_request_stage (cash_request_id, stage),
  CONSTRAINT fk_otps_request      FOREIGN KEY (cash_request_id) REFERENCES cash_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_otps_requested_by FOREIGN KEY (requested_by)    REFERENCES users(id)
) ENGINE=InnoDB;

-- "Each attempt is logged" (DA-06).
CREATE TABLE otp_attempts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  otp_id      BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  is_success  BOOLEAN NOT NULL,
  lat         DECIMAL(10,7) NULL,
  lng         DECIMAL(10,7) NULL,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_oa_otp  FOREIGN KEY (otp_id)  REFERENCES otps(id) ON DELETE CASCADE,
  CONSTRAINT fk_oa_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. Proof of delivery (DA-07)
-- ---------------------------------------------------------------------
CREATE TABLE handover_proofs (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cash_request_id  BIGINT UNSIGNED NOT NULL,
  stage            ENUM('pickup','dropoff') NOT NULL DEFAULT 'dropoff',
  confirmed_amount DECIMAL(15,2) NOT NULL,
  denominations    JSON NULL,
  signature_path   VARCHAR(255) NULL,                      -- optional on-screen signature (ERD signature_url)
  receiver_name    VARCHAR(120) NULL,
  lat              DECIMAL(10,7) NULL,
  lng              DECIMAL(10,7) NULL,
  submitted_by     BIGINT UNSIGNED NOT NULL,
  submitted_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at       TIMESTAMP NULL,
  updated_at       TIMESTAMP NULL,
  CONSTRAINT fk_hp_request      FOREIGN KEY (cash_request_id) REFERENCES cash_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_hp_submitted_by FOREIGN KEY (submitted_by)    REFERENCES users(id)
) ENGINE=InnoDB;

-- "At least one photo" → one row per photo (ERD photo_url).
CREATE TABLE handover_photos (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  handover_proof_id BIGINT UNSIGNED NOT NULL,
  path              VARCHAR(255) NOT NULL,                 -- storage path, not a full URL
  created_at        TIMESTAMP NULL,
  updated_at        TIMESTAMP NULL,
  CONSTRAINT fk_hph_proof FOREIGN KEY (handover_proof_id) REFERENCES handover_proofs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. Audit trail (AP-09) — read-only, see triggers at the bottom.
--    Also feeds the request timeline (no separate timeline table).
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          BIGINT UNSIGNED NOT NULL,               -- who did it
  cash_request_id  BIGINT UNSIGNED NULL,                   -- NULL for non-request actions (login, user.update…)
  action           VARCHAR(40) NOT NULL,                   -- create | edit | approve | reject | assign | reassign |
                                                           -- cancel | accept | arrived | otp.sent | otp.verified |
                                                           -- in_transit | completed | login | user.create | branch.update …
  details          JSON NULL,                              -- reason, old/new values …
  source           ENUM('web','mobile','system') NOT NULL DEFAULT 'web',
  lat              DECIMAL(10,7) NULL,                     -- GPS stamp for app events
  lng              DECIMAL(10,7) NULL,
  ip_address       VARCHAR(45) NULL,
  created_at       TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),  -- ms, as shown in the portal
  KEY idx_al_request (cash_request_id),
  KEY idx_al_user_time (user_id, created_at),
  KEY idx_al_action_time (action, created_at),
  CONSTRAINT fk_al_user    FOREIGN KEY (user_id)         REFERENCES users(id),
  CONSTRAINT fk_al_request FOREIGN KEY (cash_request_id) REFERENCES cash_requests(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 10. Notifications (DA-02, portal notification list)
--     If you later run `php artisan make:notifications-table`, Laravel
--     wants this name — rename this one to user_notifications then.
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          BIGINT UNSIGNED NOT NULL,               -- recipient
  cash_request_id  BIGINT UNSIGNED NULL,
  type             VARCHAR(40) NOT NULL,                   -- request.submitted | team.assigned | team.reassigned |
                                                           -- request.cancelled | gps.stale | sos …
  title            VARCHAR(160) NOT NULL,
  body             TEXT NOT NULL,
  link             VARCHAR(255) NULL,                      -- e.g. '/approvals/REQ-1048'
  read_at          TIMESTAMP NULL,                         -- ERD is_read (NULL = unread)
  created_at       TIMESTAMP NULL,
  updated_at       TIMESTAMP NULL,
  KEY idx_n_user_unread (user_id, read_at),
  CONSTRAINT fk_n_user    FOREIGN KEY (user_id)         REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_n_request FOREIGN KEY (cash_request_id) REFERENCES cash_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Push tokens for the delivery app (FCM / APNs).
CREATE TABLE device_tokens (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  token        VARCHAR(255) NOT NULL UNIQUE,
  platform     ENUM('android','ios','web') NOT NULL,
  last_seen_at TIMESTAMP NULL,
  created_at   TIMESTAMP NULL,
  updated_at   TIMESTAMP NULL,
  CONSTRAINT fk_dt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 11. Settings — values "agreed in the Week 2 design workshop"
--     (OTP expiry, GPS interval, stale threshold, idle timeout …).
-- ---------------------------------------------------------------------
CREATE TABLE settings (
  `key`        VARCHAR(60) PRIMARY KEY,
  value        VARCHAR(255) NOT NULL,
  description  VARCHAR(255) NULL,
  updated_at   TIMESTAMP NULL
) ENGINE=InnoDB;

-- =====================================================================
-- 0. Laravel framework tables (sessions, tokens, cache, queue)
--    ERD `SESSION` is replaced by Laravel's `sessions` (web portal) and
--    Sanctum's `personal_access_tokens` (mobile app logins).
--    Skip this section if you use `php artisan migrate`.
-- =====================================================================
CREATE TABLE password_reset_tokens (
  email       VARCHAR(255) PRIMARY KEY,
  token       VARCHAR(255) NOT NULL,
  created_at  TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE sessions (
  id            VARCHAR(255) PRIMARY KEY,
  user_id       BIGINT UNSIGNED NULL,
  ip_address    VARCHAR(45) NULL,
  user_agent    TEXT NULL,
  payload       LONGTEXT NOT NULL,
  last_activity INT NOT NULL,
  KEY sessions_user_id_index (user_id),
  KEY sessions_last_activity_index (last_activity)
) ENGINE=InnoDB;

CREATE TABLE personal_access_tokens (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tokenable_type VARCHAR(255) NOT NULL,
  tokenable_id   BIGINT UNSIGNED NOT NULL,
  name           TEXT NOT NULL,
  token          VARCHAR(64) NOT NULL UNIQUE,
  abilities      TEXT NULL,
  last_used_at   TIMESTAMP NULL,
  expires_at     TIMESTAMP NULL,
  created_at     TIMESTAMP NULL,
  updated_at     TIMESTAMP NULL,
  KEY pat_tokenable_index (tokenable_type, tokenable_id),
  KEY pat_expires_at_index (expires_at)
) ENGINE=InnoDB;

CREATE TABLE cache (
  `key`       VARCHAR(255) PRIMARY KEY,
  value       MEDIUMTEXT NOT NULL,
  expiration  INT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE cache_locks (
  `key`       VARCHAR(255) PRIMARY KEY,
  owner       VARCHAR(255) NOT NULL,
  expiration  INT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE jobs (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  queue         VARCHAR(255) NOT NULL,
  payload       LONGTEXT NOT NULL,
  attempts      TINYINT UNSIGNED NOT NULL,
  reserved_at   INT UNSIGNED NULL,
  available_at  INT UNSIGNED NOT NULL,
  created_at    INT UNSIGNED NOT NULL,
  KEY jobs_queue_index (queue)
) ENGINE=InnoDB;

CREATE TABLE job_batches (
  id             VARCHAR(255) PRIMARY KEY,
  name           VARCHAR(255) NOT NULL,
  total_jobs     INT NOT NULL,
  pending_jobs   INT NOT NULL,
  failed_jobs    INT NOT NULL,
  failed_job_ids LONGTEXT NOT NULL,
  options        MEDIUMTEXT NULL,
  cancelled_at   INT NULL,
  created_at     INT NOT NULL,
  finished_at    INT NULL
) ENGINE=InnoDB;

CREATE TABLE failed_jobs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid        VARCHAR(255) NOT NULL UNIQUE,
  connection  TEXT NOT NULL,
  queue       TEXT NOT NULL,
  payload     LONGTEXT NOT NULL,
  exception   LONGTEXT NOT NULL,
  failed_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- Audit records are read-only (AP-09): block UPDATE and DELETE.
-- =====================================================================
DELIMITER $$
CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is read-only: records cannot be edited';
END$$
CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is read-only: records cannot be deleted';
END$$
DELIMITER ;

-- =====================================================================
-- Handy views for the portal screens
-- =====================================================================

-- Trip History & Reports (AP-10): one row per finished trip.
CREATE OR REPLACE VIEW v_trip_history AS
SELECT
  cr.reference                                        AS request_ref,
  DATE(t.completed_at)                                AS trip_date,
  b.code                                              AS branch_code,
  b.name                                              AS branch_name,
  cr.type,
  tm.name                                             AS team_name,
  cr.currency,
  cr.amount,
  TIMESTAMPDIFF(MINUTE, t.started_at, t.completed_at) AS duration_min,
  t.is_on_time,
  t.status
FROM trips t
JOIN cash_requests cr ON cr.id = t.cash_request_id
JOIN branches b       ON b.id  = cr.branch_id
JOIN teams tm         ON tm.id = t.team_id
WHERE t.status IN ('completed','cancelled');

-- Live Monitoring (AP-08): teams on the road with stale-GPS flag.
CREATE OR REPLACE VIEW v_live_trips AS
SELECT
  cr.reference AS request_ref,
  tm.name      AS team_name,
  b.name       AS branch_name,
  t.status,
  t.current_lat,
  t.current_lng,
  t.last_ping_at,
  (t.last_ping_at IS NULL OR t.last_ping_at < NOW() - INTERVAL
     (SELECT CAST(value AS UNSIGNED) FROM settings WHERE `key` = 'stale_gps_seconds') SECOND) AS is_stale
FROM trips t
JOIN cash_requests cr ON cr.id = t.cash_request_id
JOIN branches b       ON b.id  = cr.branch_id
JOIN teams tm         ON tm.id = t.team_id
WHERE t.status IN ('assigned','in_transit');
