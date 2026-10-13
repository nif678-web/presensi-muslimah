-- ============================================
-- Smart Attendance System - Database Setup
-- Program Studi: Teknik Informatika
-- ============================================

CREATE DATABASE IF NOT EXISTS absensi_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE absensi_db;

-- ============================================
-- Tabel Users (Mahasiswa)
-- ============================================
CREATE TABLE IF NOT EXISTS users (
  id         INT          AUTO_INCREMENT PRIMARY KEY,
  nama       VARCHAR(100) NOT NULL,
  nim        VARCHAR(20)  NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Tabel Absensi
-- ============================================
CREATE TABLE IF NOT EXISTS absensi (
  id        INT      AUTO_INCREMENT PRIMARY KEY,
  user_id   INT      NOT NULL,
  latitude  DOUBLE   NOT NULL,
  longitude DOUBLE   NOT NULL,
  foto      TEXT,
  waktu     DATETIME DEFAULT CURRENT_TIMESTAMP,
  status    VARCHAR(20) DEFAULT 'hadir',
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Tabel Settings (Konfigurasi Sistem)
-- ============================================
CREATE TABLE IF NOT EXISTS settings (
  id          INT          AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(50)  NOT NULL UNIQUE,
  setting_val VARCHAR(255) NOT NULL,
  label       VARCHAR(100) NOT NULL,
  updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nilai default koordinat kampus
INSERT INTO settings (setting_key, setting_val, label) VALUES
('kampus_lat',  '-6.2088',  'Latitude Kampus'),
('kampus_lng',  '106.8456', 'Longitude Kampus'),
('max_radius',  '100',      'Radius Maksimal (meter)'),
('batas_jam',   '8',        'Batas Jam Hadir (jam, format 24h)'),
('batas_menit', '0',        'Batas Menit Hadir')
ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val);

-- ============================================
-- Data Sample Mahasiswa (password: 123456)
-- ============================================
INSERT INTO users (nama, nim, password) VALUES
('Ahmad Fauzi',   '2021001', MD5('123456')),
('Siti Rahayu',   '2021002', MD5('123456')),
('Budi Santoso',  '2021003', MD5('123456')),
('Dewi Lestari',  '2021004', MD5('123456')),
('Rizky Pratama', '2021005', MD5('123456'));


CREATE TABLE IF NOT EXISTS settings (
  id          INT          AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(50)  NOT NULL UNIQUE,
  setting_val VARCHAR(255) NOT NULL,
  label       VARCHAR(100) NOT NULL,
  updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert nilai default (tidak akan overwrite jika sudah ada)
INSERT INTO settings (setting_key, setting_val, label) VALUES
('kampus_lat',  '-6.2088',  'Latitude Kampus'),
('kampus_lng',  '106.8456', 'Longitude Kampus'),
('max_radius',  '100',      'Radius Maksimal (meter)'),
('batas_jam',   '8',        'Batas Jam Hadir (jam, format 24h)'),
('batas_menit', '0',        'Batas Menit Hadir')
ON DUPLICATE KEY UPDATE setting_key = setting_key; -- no-op jika sudah ada
