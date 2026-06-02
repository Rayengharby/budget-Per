-- ============================================================
-- BudgetCollab — MySQL Schema
-- ============================================================

CREATE DATABASE IF NOT EXISTS budgetcollab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE budgetcollab;

-- ── Users ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(50)  NOT NULL,
    email      VARCHAR(255) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    role       ENUM('user','admin') NOT NULL DEFAULT 'user',
    is_active  TINYINT(1)   NOT NULL DEFAULT 0,
    avatar     VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Categories ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS categories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(50)  NOT NULL,
    icon       VARCHAR(20)  NOT NULL DEFAULT '📦',
    color      VARCHAR(20)  NOT NULL DEFAULT '#6b7280',
    is_default TINYINT(1)   NOT NULL DEFAULT 0,
    created_by INT          NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Budgets ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS budgets (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100)                       NOT NULL,
    is_shared       TINYINT(1)                         NOT NULL DEFAULT 0,
    owner_id        INT                                NOT NULL,
    period          ENUM('weekly','monthly','custom')  NOT NULL DEFAULT 'monthly',
    start_date      DATE                               NOT NULL,
    end_date        DATE                               NULL,
    global_limit    DECIMAL(12,2)                      NULL,
    alert_threshold INT                                NOT NULL DEFAULT 80,
    created_at      TIMESTAMP                          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP                          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Budget members (shared budgets) ───────────────────────
CREATE TABLE IF NOT EXISTS budget_members (
    budget_id INT NOT NULL,
    user_id   INT NOT NULL,
    PRIMARY KEY (budget_id, user_id),
    FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Per-category spending limits inside a budget ──────────
CREATE TABLE IF NOT EXISTS budget_category_limits (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    budget_id    INT           NOT NULL,
    category_id  INT           NOT NULL,
    limit_amount DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (budget_id)   REFERENCES budgets(id)    ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    UNIQUE KEY uq_budget_cat (budget_id, category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Transactions ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS transactions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('income','expense') NOT NULL,
    amount      DECIMAL(12,2)            NOT NULL CHECK (amount > 0),
    description VARCHAR(200)             NOT NULL,
    date        DATE                     NOT NULL,
    category_id INT                      NULL,
    user_id     INT                      NOT NULL,
    budget_id   INT                      NULL,
    comment     VARCHAR(500)             NULL,
    created_at  TIMESTAMP                NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP                NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id)     REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (budget_id)   REFERENCES budgets(id)  ON DELETE SET NULL,
    INDEX idx_user_date   (user_id,   date DESC),
    INDEX idx_budget_date (budget_id, date DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Default categories (seed)
-- No default categories — users create their own from the Categories page.

-- ============================================================
-- Default admin account  (password: admin123)
-- Run:  php -r "echo password_hash('admin123', PASSWORD_BCRYPT, ['cost'=>12]);"
-- and replace the hash below before importing, OR use the seed script.
-- ============================================================
INSERT INTO users (name, email, password, role, is_active) VALUES
    ('Administrateur', 'admin@budgetcollab.com',
     '$2y$12$DMk92zvCHoWhsfgr4ik55.4eTb4B852ypY6TVkwEfihpC/FTvVBfG', -- password: password
     'admin', 1);
