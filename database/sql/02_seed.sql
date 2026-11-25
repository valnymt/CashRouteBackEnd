-- CashRoute reference data for a fresh database (run after 01_schema.sql).
-- Adds system roles, permissions, and application settings.
-- Provision real branches, HQ, accounts, and teams separately.

USE cashroute;
SET NAMES utf8mb4;

SET @now = NOW();

-- ---------------------------------------------------------------------
-- Roles
-- ---------------------------------------------------------------------
INSERT INTO roles (name, channel, is_system, created_at, updated_at) VALUES
  ('Super Admin', 'portal', TRUE, @now, @now),
  ('Maker',       'portal', TRUE, @now, @now),
  ('Checker',     'portal', TRUE, @now, @now),
  ('Driver',      'mobile', TRUE, @now, @now),
  ('Custodian',   'mobile', TRUE, @now, @now);

-- ---------------------------------------------------------------------
-- Permissions
-- ---------------------------------------------------------------------
INSERT INTO permissions (code, name, created_at, updated_at) VALUES
  ('users.manage',             'Manage users and reset passwords',           @now, @now),
  ('roles.manage',             'Manage roles and permissions',               @now, @now),
  ('branches.manage',          'Register, edit and deactivate branches',     @now, @now),
  ('teams.manage',             'Group and confirm delivery teams',           @now, @now),
  ('requests.view',            'View cash requests',                         @now, @now),
  ('requests.create',          'Create deposit / withdrawal requests',       @now, @now),
  ('requests.edit_own',        'Edit own Pending requests',                  @now, @now),
  ('requests.cancel_own',      'Cancel own requests before approval',        @now, @now),
  ('requests.approve',         'Approve or reject requests',                 @now, @now),
  ('requests.assign',          'Assign / reassign delivery teams',           @now, @now),
  ('requests.cancel_approved', 'Cancel Approved / Assigned requests',        @now, @now),
  ('monitoring.view',          'View live monitoring',                       @now, @now),
  ('reports.view',             'View trip history and reports',              @now, @now),
  ('audit.view',               'View the audit trail',                       @now, @now),
  ('trips.view_own',           'View own assignments and trip history',      @now, @now),
  ('trips.update',             'Accept assignments and update trip progress',@now, @now),
  ('handover.perform',         'Request / verify OTP and submit proof of delivery', @now, @now);

-- Super Admin: everything on the portal (incl. maker + checker rights;
-- the maker–checker rule still blocks approving their own requests).
INSERT INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.name = 'Super Admin'
  AND p.code NOT IN ('trips.view_own','trips.update','handover.perform');

INSERT INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.name = 'Maker'
  AND p.code IN ('requests.view','requests.create','requests.edit_own','requests.cancel_own','monitoring.view');

INSERT INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.name = 'Checker'
  AND p.code IN ('requests.view','requests.approve','requests.assign','requests.cancel_approved',
                 'monitoring.view','reports.view','teams.manage');

INSERT INTO role_permission (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.name IN ('Driver','Custodian')
  AND p.code IN ('trips.view_own','trips.update','handover.perform');

-- ---------------------------------------------------------------------
-- Settings (adjust after the design workshop)
-- ---------------------------------------------------------------------
INSERT INTO settings (`key`, value, description, updated_at) VALUES
  ('idle_timeout_minutes',  '15',  'Portal session idle timeout',                  @now),
  ('idle_warning_seconds',  '30',  'Countdown shown before the idle sign-out',     @now),
  ('max_failed_logins',     '5',   'Failed logins before the account locks',       @now),
  ('otp_expiry_seconds',    '300', 'How long a handover OTP stays valid',          @now),
  ('otp_max_attempts',      '3',   'Wrong OTP entries allowed per code',           @now),
  ('gps_interval_seconds',  '10',  'How often the app sends its location',         @now),
  ('stale_gps_seconds',     '120', 'No location update for this long = stale',     @now),
  ('default_currency',      'MMK', 'Currency for new requests',                    @now);

-- Existing databases: run only the UPDATE below in phpMyAdmin.
-- Repair teams left occupied by historical proof submissions.
-- Safe to rerun. A team with another assigned or in-transit trip stays occupied.
UPDATE teams AS team
SET team.status = 'available', team.updated_at = CURRENT_TIMESTAMP
WHERE team.status = 'on_trip'
  AND EXISTS (
      SELECT 1 FROM trips AS finished
      WHERE finished.team_id = team.id AND finished.status = 'completed'
  )
  AND NOT EXISTS (
      SELECT 1 FROM trips AS active
      WHERE active.team_id = team.id AND active.status IN ('assigned', 'in_transit')
  );
