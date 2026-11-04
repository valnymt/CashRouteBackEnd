-- =====================================================================
-- CashRoute — seed data (run after 01_schema.sql)
-- Mirrors the Admin Portal demo data (src/lib/seed.ts) so the portal
-- looks the same against the real API.
--
-- Every demo account's password is:  password
-- (stored as a bcrypt hash; Laravel's Hash::check() accepts it).
--
-- Users:
--   U-001..U-016  original demo users (Myanmar names; admin login = admin.maker)
--   U-101..U-120  HQ drivers (10) and custodians (10)
--   U-201..U-252  extra Maker / Checker / Driver / Custodian per branch
-- =====================================================================
USE cashroute;
SET NAMES utf8mb4;

SET @now = NOW();
SET @pw  = '$2y$12$qlXT/gNuanx0xOuEFGUguez3RfQZytSVg7nifjlm8h7p0xM7whrIu';

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

-- ---------------------------------------------------------------------
-- Branches (Yangon) + HQ cash centre
-- ---------------------------------------------------------------------
INSERT INTO branches (code, name, address, lat, lng, handover_radius, contact_name, contact_phone, contact_email, is_hq, is_active, created_at, updated_at) VALUES
  ('BR-001', 'Sule Branch',      'Sule Pagoda Rd, Kyauktada, Yangon', 16.7745000, 96.1589000, 150, 'Amy Tan',    '+95 9 450 112 233', 'amy.tan@bank.com',    FALSE, TRUE,  @now, @now),
  ('BR-002', 'Bahan Branch',     'Shwegondaing Rd, Bahan, Yangon',    16.8075000, 96.1562000, 120, 'Daniel Lim', '+95 9 450 112 233', 'daniel.lim@bank.com', FALSE, TRUE,  @now, @now),
  ('BR-003', 'Hlaing Branch',    'Insein Rd, Hlaing, Yangon',         16.8475000, 96.1236000, 100, 'Sara Koh',   '+95 9 450 112 233', 'sara.koh@bank.com',   FALSE, TRUE,  @now, @now),
  ('BR-004', 'Insein Branch',    'Insein Rd, Insein, Yangon',         16.8932000, 96.1031000, 150, 'Wei Ming',   '+95 9 450 112 233', 'wei.ming@bank.com',   FALSE, FALSE, @now, @now),
  ('BR-005', 'Sanchaung Branch', 'Baho Rd, Sanchaung, Yangon',        16.8018000, 96.1334000, 120, 'Nora Lee',   '+95 9 450 112 233', 'nora.lee@bank.com',   FALSE, TRUE,  @now, @now),
  ('HQ-001', 'HQ Cash Centre',   'Pansodan St, Kyauktada, Yangon',    16.7752000, 96.1652000, 250, 'Ops Desk',   '+95 9 450 112 233', 'ops.desk@bank.com',   TRUE,  TRUE,  @now, @now);

-- Bank branch directory (registered + not yet in the portal).
INSERT INTO branch_directory (code, name, address, lat, lng, created_at, updated_at) VALUES
  ('BR-001', 'Sule Branch',           'Sule Pagoda Rd, Kyauktada, Yangon',      16.7745000, 96.1589000, @now, @now),
  ('BR-002', 'Bahan Branch',          'Shwegondaing Rd, Bahan, Yangon',         16.8075000, 96.1562000, @now, @now),
  ('BR-003', 'Hlaing Branch',         'Insein Rd, Hlaing, Yangon',              16.8475000, 96.1236000, @now, @now),
  ('BR-004', 'Insein Branch',         'Insein Rd, Insein, Yangon',              16.8932000, 96.1031000, @now, @now),
  ('BR-005', 'Sanchaung Branch',      'Baho Rd, Sanchaung, Yangon',             16.8018000, 96.1334000, @now, @now),
  ('BR-006', 'Kamayut Branch',        'Pyay Rd, Kamayut, Yangon',               16.8236000, 96.1305000, @now, @now),
  ('BR-007', 'Thingangyun Branch',    'Thanthumar Rd, Thingangyun, Yangon',     16.8268000, 96.1948000, @now, @now),
  ('BR-008', 'North Dagon Branch',    'Pinlon Rd, North Dagon, Yangon',         16.8752000, 96.1934000, @now, @now),
  ('BR-009', 'Mayangone Branch',      'Kabar Aye Pagoda Rd, Mayangone, Yangon', 16.8651000, 96.1428000, @now, @now),
  ('BR-010', 'South Okkalapa Branch', 'Thudhamma Rd, South Okkalapa, Yangon',   16.8483000, 96.1826000, @now, @now),
  ('HQ-001', 'HQ Cash Centre',        'Pansodan St, Kyauktada, Yangon',         16.7752000, 96.1652000, @now, @now);

-- ---------------------------------------------------------------------
-- Users, part 1: original demo users (same IDs / roles as the portal demo,
-- Myanmar names). U-003 signs in as admin.maker.
-- ---------------------------------------------------------------------
INSERT INTO users (employee_id, name, username, email, phone, password, must_change_password, role_id, branch_id, is_delivery_member, is_locked, created_at, updated_at)
SELECT u.employee_id, u.name, u.username, u.email, u.phone, @pw, FALSE,
       (SELECT id FROM roles WHERE name = u.role),
       (SELECT id FROM branches WHERE code = u.branch_code),
       u.role IN ('Driver','Custodian'), u.is_locked, @now, @now
FROM (
  SELECT 'U-001' employee_id, 'Khin Thandar Oo' name,  'khin.thandaroo' username, 'khin.thandaroo@cashroute.com' email, '+95 9 4137531' phone, 'Maker' role,       'BR-001' branch_code, FALSE is_locked UNION ALL
  SELECT 'U-002', 'Moe Moe Aye',     'moe.moeaye',     'moe.moeaye@cashroute.com',     '+95 9 4275062', 'Maker',       'HQ-001', FALSE UNION ALL
  SELECT 'U-003', 'Thein Zaw Win',   'admin.maker',    'admin.maker@cashroute.com',    '+95 9 4412593', 'Super Admin', 'HQ-001', FALSE UNION ALL
  SELECT 'U-004', 'Kyaw Soe Win',    'kyaw.soewin',    'kyaw.soewin@cashroute.com',    '+95 9 4550124', 'Driver',      'BR-001', FALSE UNION ALL
  SELECT 'U-005', 'Hla Hla Win',     'hla.hlawin',     'hla.hlawin@cashroute.com',     '+95 9 4687655', 'Custodian',   'BR-001', FALSE UNION ALL
  SELECT 'U-006', 'Zin Min Latt',    'zin.minlatt',    'zin.minlatt@cashroute.com',    '+95 9 4825186', 'Custodian',   'BR-001', TRUE  UNION ALL
  SELECT 'U-007', 'Pyae Phyo Maung', 'pyae.phyomaung', 'pyae.phyomaung@cashroute.com', '+95 9 4962717', 'Custodian',   'BR-001', FALSE UNION ALL
  SELECT 'U-008', 'Tun Tun Naing',   'tun.tunnaing',   'tun.tunnaing@cashroute.com',   '+95 9 5100248', 'Driver',      'BR-002', FALSE UNION ALL
  SELECT 'U-009', 'May Thu Zar',     'may.thuzar',     'may.thuzar@cashroute.com',     '+95 9 5237779', 'Custodian',   'BR-002', FALSE UNION ALL
  SELECT 'U-010', 'Arkar Min',       'arkar.min',      'arkar.min@cashroute.com',      '+95 9 5375310', 'Custodian',   'BR-002', FALSE UNION ALL
  SELECT 'U-011', 'Than Htike Oo',   'than.htikeoo',   'than.htikeoo@cashroute.com',   '+95 9 5512841', 'Driver',      'BR-003', FALSE UNION ALL
  SELECT 'U-012', 'Phyu Phyu Thin',  'phyu.phyuthin',  'phyu.phyuthin@cashroute.com',  '+95 9 5650372', 'Custodian',   'BR-003', FALSE UNION ALL
  SELECT 'U-013', 'Lin Htet Aung',   'lin.htetaung',   'lin.htetaung@cashroute.com',   '+95 9 5787903', 'Custodian',   'BR-003', FALSE UNION ALL
  SELECT 'U-014', 'Yadanar Soe',     'yadanar.soe',    'yadanar.soe@cashroute.com',    '+95 9 5925434', 'Driver',      'BR-005', FALSE UNION ALL
  SELECT 'U-015', 'Thet Paing Soe',  'thet.paingsoe',  'thet.paingsoe@cashroute.com',  '+95 9 6062965', 'Custodian',   'BR-005', FALSE UNION ALL
  SELECT 'U-016', 'Kyaw Thura Han',  'kyaw.thurahan',  'kyaw.thurahan@cashroute.com',  '+95 9 6200496', 'Checker',     'BR-002', FALSE
) u;

UPDATE users SET locked_at = @now, failed_attempts = 5 WHERE is_locked = TRUE;

-- ---------------------------------------------------------------------
-- Users, part 2: HQ delivery staff (10 drivers + 10 custodians)
-- ---------------------------------------------------------------------
INSERT INTO users (employee_id, name, username, email, phone, password, must_change_password, role_id, branch_id, is_delivery_member, is_active, is_locked, created_at, updated_at)
SELECT u.employee_id, u.name, u.username, u.email, u.phone, @pw, FALSE,
       (SELECT id FROM roles WHERE name = u.role),
       (SELECT id FROM branches WHERE code = 'HQ-001'),
       TRUE, TRUE, FALSE, @now, @now
FROM (
  -- Drivers
  SELECT 'U-101' employee_id, 'Aung Kyaw Moe' name, 'aung.kyawmoe' username, 'aung.kyawmoe@cashroute.com' email, '+95 9 7810 10101' phone, 'Driver' role UNION ALL
  SELECT 'U-102', 'Zaw Min Htun',     'zaw.minhtun',     'zaw.minhtun@cashroute.com',     '+95 9 7810 10102', 'Driver' UNION ALL
  SELECT 'U-103', 'Htet Naing Oo',    'htet.naingoo',    'htet.naingoo@cashroute.com',    '+95 9 7810 10103', 'Driver' UNION ALL
  SELECT 'U-104', 'Kyaw Zin Lin',     'kyaw.zinlin',     'kyaw.zinlin@cashroute.com',     '+95 9 7810 10104', 'Driver' UNION ALL
  SELECT 'U-105', 'Min Thu Ya',       'min.thuya',       'min.thuya@cashroute.com',       '+95 9 7810 10105', 'Driver' UNION ALL
  SELECT 'U-106', 'Thura Soe',        'thura.soe',       'thura.soe@cashroute.com',       '+95 9 7810 10106', 'Driver' UNION ALL
  SELECT 'U-107', 'Ye Yint Aung',     'ye.yintaung',     'ye.yintaung@cashroute.com',     '+95 9 7810 10107', 'Driver' UNION ALL
  SELECT 'U-108', 'Nay Lin Tun',      'nay.lintun',      'nay.lintun@cashroute.com',      '+95 9 7810 10108', 'Driver' UNION ALL
  SELECT 'U-109', 'Sithu Win',        'sithu.win',       'sithu.win@cashroute.com',       '+95 9 7810 10109', 'Driver' UNION ALL
  SELECT 'U-110', 'Phyo Wai Lin',     'phyo.wailin',     'phyo.wailin@cashroute.com',     '+95 9 7810 10110', 'Driver' UNION ALL
  -- Custodians
  SELECT 'U-111', 'Thandar Hlaing',   'thandar.hlaing',  'thandar.hlaing@cashroute.com',  '+95 9 7810 10111', 'Custodian' UNION ALL
  SELECT 'U-112', 'Su Su Hlaing',     'susu.hlaing',     'susu.hlaing@cashroute.com',     '+95 9 7810 10112', 'Custodian' UNION ALL
  SELECT 'U-113', 'Khin Myat Noe',    'khin.myatnoe',    'khin.myatnoe@cashroute.com',    '+95 9 7810 10113', 'Custodian' UNION ALL
  SELECT 'U-114', 'Mya Thida',        'mya.thida',       'mya.thida@cashroute.com',       '+95 9 7810 10114', 'Custodian' UNION ALL
  SELECT 'U-115', 'Hnin Ei Phyu',     'hnin.eiphyu',     'hnin.eiphyu@cashroute.com',     '+95 9 7810 10115', 'Custodian' UNION ALL
  SELECT 'U-116', 'Aye Chan Myae',    'aye.chanmyae',    'aye.chanmyae@cashroute.com',    '+95 9 7810 10116', 'Custodian' UNION ALL
  SELECT 'U-117', 'Wai Yan Phyo',     'wai.yanphyo',     'wai.yanphyo@cashroute.com',     '+95 9 7810 10117', 'Custodian' UNION ALL
  SELECT 'U-118', 'Kaung Myat Thu',   'kaung.myatthu',   'kaung.myatthu@cashroute.com',   '+95 9 7810 10118', 'Custodian' UNION ALL
  SELECT 'U-119', 'Ei Mon Thu',       'ei.monthu',       'ei.monthu@cashroute.com',       '+95 9 7810 10119', 'Custodian' UNION ALL
  SELECT 'U-120', 'Nang Mon Kham',    'nang.monkham',    'nang.monkham@cashroute.com',    '+95 9 7810 10120', 'Custodian'
) u;

-- ---------------------------------------------------------------------
-- Users, part 3: extra staff per branch (1 Maker, 1 Checker, 3 Drivers,
-- 5 Custodians each) + 2 Checkers at HQ
-- ---------------------------------------------------------------------
INSERT INTO users
  (employee_id, name, username, email, phone, password, must_change_password,
   role_id, branch_id, is_delivery_member, is_active, is_locked, created_at, updated_at)
SELECT
  u.employee_id, u.name, u.username,
  CONCAT(u.username, '@cashroute.com'),
  CONCAT('+95 9 7820 ', LPAD(SUBSTRING(u.employee_id, 3), 5, '0')),
  @pw,
  FALSE,
  (SELECT id FROM roles WHERE name = u.role),
  (SELECT id FROM branches WHERE code = u.branch_code),
  u.role IN ('Driver','Custodian'),
  TRUE, FALSE, @now, @now
FROM (
  -- ===== BR-001 Sule =====
  SELECT 'U-201' AS employee_id, 'Ei Ei Khaing' AS name, 'ei.eikhaing' AS username, 'Maker' AS role, 'BR-001' AS branch_code UNION ALL
  SELECT 'U-202', 'Zaw Lin Htut',      'zaw.linhtut',     'Checker',   'BR-001' UNION ALL
  SELECT 'U-203', 'Aung Thu Ra',       'aung.thura',      'Driver',    'BR-001' UNION ALL
  SELECT 'U-204', 'Kyaw Swar Win',     'kyaw.swarwin',    'Driver',    'BR-001' UNION ALL
  SELECT 'U-205', 'Myo Min Khant',     'myo.minkhant',    'Driver',    'BR-001' UNION ALL
  SELECT 'U-206', 'Nandar Aye',        'nandar.aye',      'Custodian', 'BR-001' UNION ALL
  SELECT 'U-207', 'Hsu Myat Noe',      'hsu.myatnoe',     'Custodian', 'BR-001' UNION ALL
  SELECT 'U-208', 'Thiri Hlaing',      'thiri.hlaing',    'Custodian', 'BR-001' UNION ALL
  SELECT 'U-209', 'Pyone Pyone Kyaw',  'pyone.pyonekyaw', 'Custodian', 'BR-001' UNION ALL
  SELECT 'U-210', 'Than Zin Oo',       'than.zinoo',      'Custodian', 'BR-001' UNION ALL
  -- ===== BR-002 Bahan =====
  SELECT 'U-211', 'Su Mon Htwe',       'su.monhtwe',      'Maker',     'BR-002' UNION ALL
  SELECT 'U-212', 'Htay Aung Kyaw',    'htay.aungkyaw',   'Checker',   'BR-002' UNION ALL
  SELECT 'U-213', 'Zin Ko Ko',         'zin.koko',        'Driver',    'BR-002' UNION ALL
  SELECT 'U-214', 'Moe Kyaw Thu',      'moe.kyawthu',     'Driver',    'BR-002' UNION ALL
  SELECT 'U-215', 'Naing Lin Aung',    'naing.linaung',   'Driver',    'BR-002' UNION ALL
  SELECT 'U-216', 'Khin Zar Chi',      'khin.zarchi',     'Custodian', 'BR-002' UNION ALL
  SELECT 'U-217', 'Cho Cho Win',       'cho.chowin',      'Custodian', 'BR-002' UNION ALL
  SELECT 'U-218', 'May Phyu Sin',      'may.phyusin',     'Custodian', 'BR-002' UNION ALL
  SELECT 'U-219', 'Yamin Oo',          'yamin.oo',        'Custodian', 'BR-002' UNION ALL
  SELECT 'U-220', 'Aye Thet Mon',      'aye.thetmon',     'Custodian', 'BR-002' UNION ALL
  -- ===== BR-003 Hlaing =====
  SELECT 'U-221', 'Thet Thet Naing',   'thet.thetnaing',  'Maker',     'BR-003' UNION ALL
  SELECT 'U-222', 'Ko Ko Lwin',        'ko.kolwin',       'Checker',   'BR-003' UNION ALL
  SELECT 'U-223', 'Hein Htet Zaw',     'hein.htetzaw',    'Driver',    'BR-003' UNION ALL
  SELECT 'U-224', 'Saw Myat Min',      'saw.myatmin',     'Driver',    'BR-003' UNION ALL
  SELECT 'U-225', 'Lwin Moe Htet',     'lwin.moehtet',    'Driver',    'BR-003' UNION ALL
  SELECT 'U-226', 'Poe Ei Ei',         'poe.eiei',        'Custodian', 'BR-003' UNION ALL
  SELECT 'U-227', 'Wint Yee Mon',      'wint.yeemon',     'Custodian', 'BR-003' UNION ALL
  SELECT 'U-228', 'Shwe Sin Win',      'shwe.sinwin',     'Custodian', 'BR-003' UNION ALL
  SELECT 'U-229', 'Nilar Tun',         'nilar.tun',       'Custodian', 'BR-003' UNION ALL
  SELECT 'U-230', 'Hay Mar Soe',       'hay.marsoe',      'Custodian', 'BR-003' UNION ALL
  -- ===== BR-004 Insein =====
  SELECT 'U-231', 'Mi Mi Khaing',      'mi.mikhaing',     'Maker',     'BR-004' UNION ALL
  SELECT 'U-232', 'Soe Moe Tun',       'soe.moetun',      'Checker',   'BR-004' UNION ALL
  SELECT 'U-233', 'Tin Aung Myint',    'tin.aungmyint',   'Driver',    'BR-004' UNION ALL
  SELECT 'U-234', 'Kyaw Hein Thu',     'kyaw.heinthu',    'Driver',    'BR-004' UNION ALL
  SELECT 'U-235', 'Yan Naing Soe',     'yan.naingsoe',    'Driver',    'BR-004' UNION ALL
  SELECT 'U-236', 'Thazin Nwe',        'thazin.nwe',      'Custodian', 'BR-004' UNION ALL
  SELECT 'U-237', 'Ei Thinzar Htay',   'ei.thinzarhtay',  'Custodian', 'BR-004' UNION ALL
  SELECT 'U-238', 'Sandar Min',        'sandar.min',      'Custodian', 'BR-004' UNION ALL
  SELECT 'U-239', 'Zun Pwint Phyu',    'zun.pwintphyu',   'Custodian', 'BR-004' UNION ALL
  SELECT 'U-240', 'Htar Htar Oo',      'htar.htaroo',     'Custodian', 'BR-004' UNION ALL
  -- ===== BR-005 Sanchaung =====
  SELECT 'U-241', 'Pann Ei Mon',       'pann.eimon',      'Maker',     'BR-005' UNION ALL
  SELECT 'U-242', 'Win Htut Oo',       'win.htutoo',      'Checker',   'BR-005' UNION ALL
  SELECT 'U-243', 'Si Thu Aung',       'si.thuaung',      'Driver',    'BR-005' UNION ALL
  SELECT 'U-244', 'Arkar Zaw',         'arkar.zaw',       'Driver',    'BR-005' UNION ALL
  SELECT 'U-245', 'Min Khant Kyaw',    'min.khantkyaw',   'Driver',    'BR-005' UNION ALL
  SELECT 'U-246', 'Yoon Shwe Yi',      'yoon.shweyi',     'Custodian', 'BR-005' UNION ALL
  SELECT 'U-247', 'Thet Su Hlaing',    'thet.suhlaing',   'Custodian', 'BR-005' UNION ALL
  SELECT 'U-248', 'Phway Phway',       'phway.phway',     'Custodian', 'BR-005' UNION ALL
  SELECT 'U-249', 'Nan Kham Aye',      'nan.khamaye',     'Custodian', 'BR-005' UNION ALL
  SELECT 'U-250', 'Moh Moh Thet',      'moh.mohthet',     'Custodian', 'BR-005' UNION ALL
  -- ===== HQ-001: extra Checkers =====
  SELECT 'U-251', 'Kyaw Myo Thant',    'kyaw.myothant',   'Checker',   'HQ-001' UNION ALL
  SELECT 'U-252', 'Thin Zar Wint',     'thin.zarwint',    'Checker',   'HQ-001'
) u;

-- ---------------------------------------------------------------------
-- Teams (1 driver + 2 custodians each, confirmed)
-- ---------------------------------------------------------------------
SET @admin   = (SELECT id FROM users WHERE employee_id = 'U-003');
SET @checker = (SELECT id FROM users WHERE employee_id = 'U-016');

INSERT INTO teams (name, status, is_confirmed, confirmed_at, confirmed_by, created_at, updated_at) VALUES
  ('Team A', 'available', TRUE, @now, @admin, @now, @now),
  ('Team B', 'on_trip',   TRUE, @now, @admin, @now, @now),
  ('Team C', 'available', TRUE, @now, @admin, @now, @now);

INSERT INTO team_members (team_id, user_id, created_at, updated_at)
SELECT t.id, u.id, @now, @now
FROM (
  SELECT 'Team A' team, 'U-004' emp UNION ALL SELECT 'Team A', 'U-005' UNION ALL SELECT 'Team A', 'U-007' UNION ALL
  SELECT 'Team B', 'U-008'          UNION ALL SELECT 'Team B', 'U-009' UNION ALL SELECT 'Team B', 'U-010' UNION ALL
  SELECT 'Team C', 'U-011'          UNION ALL SELECT 'Team C', 'U-012' UNION ALL SELECT 'Team C', 'U-013'
) m
JOIN teams t ON t.name = m.team
JOIN users u ON u.employee_id = m.emp;

-- ---------------------------------------------------------------------
-- Sample requests — one per status, so every screen has something.
-- ---------------------------------------------------------------------
SET @amy   = (SELECT id FROM users WHERE employee_id = 'U-001');
SET @priya = (SELECT id FROM users WHERE employee_id = 'U-002');
SET @sule  = (SELECT id FROM branches WHERE code = 'BR-001');
SET @bahan = (SELECT id FROM branches WHERE code = 'BR-002');
SET @hlaing= (SELECT id FROM branches WHERE code = 'BR-003');
SET @sanch = (SELECT id FROM branches WHERE code = 'BR-005');

INSERT INTO cash_requests
  (reference, type, branch_id, amount, currency, requested_date, complete_within, due_at, remarks, status,
   created_by, approved_by, approved_at, rejected_by, rejected_at, reject_reason,
   cancelled_by, cancelled_by_role, cancelled_at, cancel_reason, completed_at, created_at, updated_at)
VALUES
  ('REQ-1048', 'deposit',    @sule,   250000.00, 'MMK', CURDATE(), '3h',  NOW() + INTERVAL 3 HOUR, 'Weekly surplus',  'pending',
   @amy,   NULL,     NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, @now - INTERVAL 20 MINUTE, @now),
  ('REQ-1047', 'withdrawal', @bahan,  180000.00, 'MMK', CURDATE(), '5h',  NOW() + INTERVAL 5 HOUR, 'ATM refill',      'approved',
   @priya, @checker, @now - INTERVAL 10 MINUTE, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, @now - INTERVAL 1 HOUR, @now),
  ('REQ-1043', 'deposit',    @hlaing, 320000.00, 'MMK', CURDATE(), '3h',  NOW() + INTERVAL 2 HOUR, NULL,              'in_transit',
   @amy,   @checker, @now - INTERVAL 90 MINUTE, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, @now - INTERVAL 2 HOUR, @now),
  ('REQ-1042', 'deposit',    @sule,   250000.00, 'MMK', CURDATE(), '1h',  @now - INTERVAL 1 HOUR,  NULL,              'completed',
   @amy,   @checker, @now - INTERVAL 3 HOUR,  NULL, NULL, NULL, NULL, NULL, NULL, NULL, @now - INTERVAL 70 MINUTE, @now - INTERVAL 4 HOUR, @now),
  ('REQ-1040', 'withdrawal', @sanch,   90000.00, 'MMK', CURDATE(), '12h', NOW() + INTERVAL 12 HOUR,'Amount too high',  'rejected',
   @priya, NULL, NULL, @checker, @now - INTERVAL 5 HOUR, 'Exceeds branch daily limit', NULL, NULL, NULL, NULL, NULL, @now - INTERVAL 6 HOUR, @now),
  ('REQ-1036', 'deposit',    @bahan,  120000.00, 'MMK', CURDATE(), '1d',  NOW() + INTERVAL 1 DAY,  NULL,              'cancelled',
   @amy,   NULL, NULL, NULL, NULL, NULL, @amy, 'maker', @now - INTERVAL 7 HOUR, 'Raised by mistake', NULL, @now - INTERVAL 8 HOUR, @now);

SET @r1043 = (SELECT id FROM cash_requests WHERE reference = 'REQ-1043');
SET @r1042 = (SELECT id FROM cash_requests WHERE reference = 'REQ-1042');
SET @teamA = (SELECT id FROM teams WHERE name = 'Team A');
SET @teamB = (SELECT id FROM teams WHERE name = 'Team B');
SET @raj   = (SELECT id FROM users WHERE employee_id = 'U-004');
SET @ali   = (SELECT id FROM users WHERE employee_id = 'U-008');

INSERT INTO trips (cash_request_id, team_id, assigned_by, status, current_lat, current_lng, last_ping_at,
                   assigned_at, accepted_at, arrived_pickup_at, arrived_dropoff_at, started_at, completed_at, is_on_time, created_at, updated_at) VALUES
  (@r1043, @teamB, @checker, 'in_transit', 16.8110000, 96.1450000, @now - INTERVAL 30 SECOND,
   @now - INTERVAL 85 MINUTE, @now - INTERVAL 80 MINUTE, @now - INTERVAL 50 MINUTE, NULL, @now - INTERVAL 80 MINUTE, NULL, NULL, @now, @now),
  (@r1042, @teamA, @checker, 'completed', 16.7752000, 96.1652000, @now - INTERVAL 70 MINUTE,
   @now - INTERVAL 170 MINUTE, @now - INTERVAL 160 MINUTE, @now - INTERVAL 130 MINUTE, @now - INTERVAL 80 MINUTE,
   @now - INTERVAL 160 MINUTE, @now - INTERVAL 70 MINUTE, FALSE, @now, @now);

INSERT INTO handover_proofs (cash_request_id, stage, confirmed_amount, denominations, signature_path, receiver_name, lat, lng, submitted_by, submitted_at, created_at, updated_at)
VALUES (@r1042, 'dropoff', 250000.00, '{"10000": 20, "5000": 10}', 'proofs/REQ-1042/signature.png', 'Ops Desk',
        16.7752000, 96.1652000, @raj, @now - INTERVAL 70 MINUTE, @now, @now);

INSERT INTO handover_photos (handover_proof_id, path, created_at, updated_at)
VALUES (LAST_INSERT_ID(), 'proofs/REQ-1042/photo-1.jpg', @now, @now);

-- Audit trail for the samples (the request timelines in the portal).
INSERT INTO audit_logs (user_id, cash_request_id, action, details, source, lat, lng, created_at) VALUES
  (@amy,     @r1042, 'create',    NULL, 'web',    NULL, NULL, @now - INTERVAL 4 HOUR),
  (@checker, @r1042, 'approve',   NULL, 'web',    NULL, NULL, @now - INTERVAL 3 HOUR),
  (@checker, @r1042, 'assign',    '{"team": "Team A"}', 'web', NULL, NULL, @now - INTERVAL 170 MINUTE),
  (@raj,     @r1042, 'accept',    NULL, 'mobile', 16.7745000, 96.1589000, @now - INTERVAL 160 MINUTE),
  (@raj,     @r1042, 'otp.verified', '{"stage": "pickup"}',  'mobile', 16.7745000, 96.1589000, @now - INTERVAL 125 MINUTE),
  (@raj,     @r1042, 'otp.verified', '{"stage": "dropoff"}', 'mobile', 16.7752000, 96.1652000, @now - INTERVAL 75 MINUTE),
  (@raj,     @r1042, 'completed', NULL, 'mobile', 16.7752000, 96.1652000, @now - INTERVAL 70 MINUTE),
  (@amy,     @r1043, 'create',    NULL, 'web',    NULL, NULL, @now - INTERVAL 2 HOUR),
  (@checker, @r1043, 'approve',   NULL, 'web',    NULL, NULL, @now - INTERVAL 90 MINUTE),
  (@checker, @r1043, 'assign',    '{"team": "Team B"}', 'web', NULL, NULL, @now - INTERVAL 85 MINUTE),
  (@ali,     @r1043, 'accept',    NULL, 'mobile', 16.8475000, 96.1236000, @now - INTERVAL 80 MINUTE),
  (@priya,   (SELECT id FROM cash_requests WHERE reference = 'REQ-1047'), 'create',  NULL, 'web', NULL, NULL, @now - INTERVAL 1 HOUR),
  (@checker, (SELECT id FROM cash_requests WHERE reference = 'REQ-1047'), 'approve', NULL, 'web', NULL, NULL, @now - INTERVAL 10 MINUTE),
  (@priya,   (SELECT id FROM cash_requests WHERE reference = 'REQ-1040'), 'create',  NULL, 'web', NULL, NULL, @now - INTERVAL 6 HOUR),
  (@checker, (SELECT id FROM cash_requests WHERE reference = 'REQ-1040'), 'reject',  '{"reason": "Exceeds branch daily limit"}', 'web', NULL, NULL, @now - INTERVAL 5 HOUR),
  (@amy,     (SELECT id FROM cash_requests WHERE reference = 'REQ-1036'), 'create',  NULL, 'web', NULL, NULL, @now - INTERVAL 8 HOUR),
  (@amy,     (SELECT id FROM cash_requests WHERE reference = 'REQ-1036'), 'cancel',  '{"reason": "Raised by mistake", "by": "maker"}', 'web', NULL, NULL, @now - INTERVAL 7 HOUR),
  (@amy,     (SELECT id FROM cash_requests WHERE reference = 'REQ-1048'), 'create',  NULL, 'web', NULL, NULL, @now - INTERVAL 20 MINUTE);

-- Notifications
INSERT INTO notifications (user_id, cash_request_id, type, title, body, link, read_at, created_at, updated_at) VALUES
  (@checker, (SELECT id FROM cash_requests WHERE reference = 'REQ-1048'), 'request.submitted', 'New request to review', 'REQ-1048 · Sule · MMK 250,000', '/approvals/REQ-1048', NULL, @now - INTERVAL 20 MINUTE, @now),
  (@ali,     @r1043, 'team.assigned', 'New assignment', 'REQ-1043 · Hlaing → HQ · MMK 320,000', NULL, @now - INTERVAL 82 MINUTE, @now - INTERVAL 85 MINUTE, @now);

-- ---------------------------------------------------------------------
-- Quick check: users per branch and role (safe to delete this query)
-- ---------------------------------------------------------------------
SELECT b.code AS branch,
       COUNT(*) AS total_users,
       SUM(r.name = 'Super Admin') AS super_admins,
       SUM(r.name = 'Maker')       AS makers,
       SUM(r.name = 'Checker')     AS checkers,
       SUM(r.name = 'Driver')      AS drivers,
       SUM(r.name = 'Custodian')   AS custodians
FROM users u
JOIN branches b ON b.id = u.branch_id
JOIN roles r    ON r.id = u.role_id
GROUP BY b.code
ORDER BY b.code;
