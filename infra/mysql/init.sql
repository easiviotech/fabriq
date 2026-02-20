-- Fabriq — MySQL Init Script
-- Creates both platform and app databases with initial schemas.

-- Platform database (shared across all tenants)
CREATE DATABASE IF NOT EXISTS sf_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS sf_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Grant permissions to the app user
GRANT ALL PRIVILEGES ON sf_platform.* TO 'fabriq'@'%';
GRANT ALL PRIVILEGES ON sf_app.* TO 'fabriq'@'%';
FLUSH PRIVILEGES;

-- ═══════════════════════════════════════════════════════════════
-- PLATFORM DB SCHEMA
-- ═══════════════════════════════════════════════════════════════

USE sf_platform;

CREATE TABLE IF NOT EXISTS tenants (
    id          CHAR(36) NOT NULL PRIMARY KEY,
    slug        VARCHAR(64) NOT NULL UNIQUE,
    name        VARCHAR(255) NOT NULL,
    domain      VARCHAR(255) DEFAULT NULL,
    plan        VARCHAR(32) NOT NULL DEFAULT 'free',
    status      ENUM('active', 'suspended', 'deleted') NOT NULL DEFAULT 'active',
    config_json JSON DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenants_domain (domain),
    INDEX idx_tenants_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS api_keys (
    id          CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id   CHAR(36) NOT NULL,
    key_prefix  VARCHAR(8) NOT NULL,
    key_hash    VARCHAR(128) NOT NULL,
    name        VARCHAR(255) NOT NULL DEFAULT 'default',
    scopes      JSON DEFAULT NULL,
    expires_at  TIMESTAMP NULL DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_apikeys_tenant (tenant_id),
    INDEX idx_apikeys_prefix (key_prefix),
    CONSTRAINT fk_apikeys_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS roles (
    id              CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id       CHAR(36) NOT NULL,
    name            VARCHAR(64) NOT NULL,
    permissions_json JSON NOT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_tenant_name (tenant_id, name),
    CONSTRAINT fk_roles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS idempotency_keys (
    `key`       VARCHAR(255) NOT NULL,
    tenant_id   CHAR(36) NOT NULL,
    result_hash VARCHAR(64) DEFAULT NULL,
    response    JSON DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  TIMESTAMP NOT NULL,
    PRIMARY KEY (`key`, tenant_id),
    INDEX idx_idemp_expires (expires_at)
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════
-- APP DB SCHEMA (shared, tenant_id on every table)
-- ═══════════════════════════════════════════════════════════════

USE sf_app;

CREATE TABLE IF NOT EXISTS users (
    id          CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id   CHAR(36) NOT NULL,
    name        VARCHAR(255) NOT NULL,
    email       VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) DEFAULT NULL,
    status      ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_tenant_email (tenant_id, email),
    INDEX idx_users_tenant (tenant_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rooms (
    id          CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id   CHAR(36) NOT NULL,
    name        VARCHAR(255) NOT NULL,
    created_by  CHAR(36) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rooms_tenant (tenant_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS room_members (
    room_id     CHAR(36) NOT NULL,
    user_id     CHAR(36) NOT NULL,
    tenant_id   CHAR(36) NOT NULL,
    joined_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (room_id, user_id),
    INDEX idx_rm_tenant (tenant_id),
    INDEX idx_rm_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
    id          CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id   CHAR(36) NOT NULL,
    room_id     CHAR(36) NOT NULL,
    user_id     CHAR(36) NOT NULL,
    body        TEXT NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_messages_tenant (tenant_id),
    INDEX idx_messages_room (room_id, created_at),
    INDEX idx_messages_tenant_room (tenant_id, room_id, created_at)
) ENGINE=InnoDB;
