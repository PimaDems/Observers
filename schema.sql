-- Observers schema (MariaDB / MySQL). The installer also runs this on SQLite for tests.

CREATE TABLE IF NOT EXISTS sites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_key CHAR(40) NOT NULL UNIQUE,
    type VARCHAR(30) NOT NULL,
    name VARCHAR(255) NOT NULL,
    address VARCHAR(255) NOT NULL DEFAULT '',
    city VARCHAR(100) NULL,
    zip VARCHAR(10) NULL,
    precinct VARCHAR(100) NULL,
    hours_text VARCHAR(255) NULL,
    lat DECIMAL(9,6) NULL,
    lng DECIMAL(9,6) NULL,
    role_codes VARCHAR(100) NULL,
    default_start_time CHAR(5) NULL,
    default_end_time CHAR(5) NULL,
    notes TEXT NULL,
    is_active TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS site_hours (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    day DATE NOT NULL,
    start_time CHAR(5) NOT NULL,
    end_time CHAR(5) NOT NULL,
    source VARCHAR(10) NOT NULL DEFAULT 'manual',
    UNIQUE (site_id, day, source),
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    affiliation VARCHAR(20) NOT NULL,
    external_only TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shifts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    role_id INT NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    max_volunteers INT NULL,
    target_coverage INT NOT NULL DEFAULT 2,
    is_active TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    UNIQUE (site_id, role_id, starts_at),
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_shifts_starts ON shifts (starts_at);

CREATE TABLE IF NOT EXISTS volunteers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(40) NULL,
    phone_e164 VARCHAR(20) NULL,
    phone_status VARCHAR(40) NOT NULL DEFAULT 'none',
    email_verified_at DATETIME NULL,
    phone_verified_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS signups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shift_id INT NOT NULL,
    volunteer_id INT NOT NULL,
    status VARCHAR(20) NOT NULL,
    hold_expires_at DATETIME NULL,
    cancel_token CHAR(32) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL,
    confirmed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    coordinator_notified_at DATETIME NULL,
    UNIQUE (shift_id, volunteer_id),
    FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
    FOREIGN KEY (volunteer_id) REFERENCES volunteers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_signups_volunteer ON signups (volunteer_id);

CREATE TABLE IF NOT EXISTS verification_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    volunteer_id INT NOT NULL,
    type VARCHAR(10) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    code_hash CHAR(64) NULL,
    attempts INT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (volunteer_id) REFERENCES volunteers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coordinators (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    is_active TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coordinator_sites (
    site_id INT NOT NULL,
    role_id INT NOT NULL,
    coordinator_id INT NOT NULL,
    PRIMARY KEY (site_id, role_id),
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (coordinator_id) REFERENCES coordinators(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS coordinator_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coordinator_id INT NOT NULL,
    site_id INT NOT NULL,
    role_id INT NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    volunteer_count INT NOT NULL DEFAULT 1,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE (coordinator_id, site_id, role_id, starts_at, ends_at),
    FOREIGN KEY (coordinator_id) REFERENCES coordinators(id) ON DELETE CASCADE,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NULL,
    action VARCHAR(60) NOT NULL,
    detail TEXT NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(30) NOT NULL,
    to_email VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    status VARCHAR(10) NOT NULL,
    error TEXT NULL,
    volunteer_id INT NULL,
    coordinator_id INT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
    rl_key VARCHAR(100) NOT NULL PRIMARY KEY,
    window_start INT NOT NULL,
    hits INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
