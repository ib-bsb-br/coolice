-- File: /database/schema.sql
CREATE DATABASE IF NOT EXISTS pkm_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pkm_system;

-- Boards table
CREATE TABLE IF NOT EXISTS boards (
    slug VARCHAR(64) PRIMARY KEY,
    title VARCHAR(200) NOT NULL DEFAULT 'My Board',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_updated (updated_at)
) ENGINE=InnoDB;

-- Tasks table with enhanced features
CREATE TABLE IF NOT EXISTS tasks (
    id VARCHAR(16) PRIMARY KEY,
    board_slug VARCHAR(64) NOT NULL,
    text TEXT NOT NULL,
    is_done BOOLEAN DEFAULT FALSE,
    is_published BOOLEAN DEFAULT FALSE,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (board_slug) REFERENCES boards(slug) ON DELETE CASCADE,
    INDEX idx_board (board_slug),
    INDEX idx_sort (board_slug, sort_order),
    INDEX idx_published (is_published, updated_at)
) ENGINE=InnoDB;

-- Files table
CREATE TABLE IF NOT EXISTS files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255),
    filesize BIGINT NOT NULL,
    mime_type VARCHAR(100),
    file_path VARCHAR(500),
    is_published BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_filename (filename)
) ENGINE=InnoDB;

-- Links table for URL shortener
CREATE TABLE IF NOT EXISTS links (
    slug VARCHAR(10) PRIMARY KEY,
    url TEXT NOT NULL,
    views INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

