CREATE DATABASE IF NOT EXISTS product_inventory
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE product_inventory;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  sku VARCHAR(40) NOT NULL,
  price DECIMAL(10, 2) UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  image VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (sku),
  KEY idx_products_name (name)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
