CREATE TABLE customers (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB;

CREATE TABLE products (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku              VARCHAR(64) NOT NULL,
  name             VARCHAR(255) NOT NULL,
  unit_price_cents INT NOT NULL,
  currency         CHAR(3) NOT NULL DEFAULT 'EUR',
  active           TINYINT NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (sku)
) ENGINE=InnoDB;

CREATE TABLE subscriptions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id  BIGINT UNSIGNED NOT NULL,
  product_id   BIGINT UNSIGNED NOT NULL,

  start_date   DATE NOT NULL,
  end_date     DATE NULL,

  status       ENUM('ACTIVE','CANCELED') NOT NULL DEFAULT 'ACTIVE',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_sub_customer (customer_id),
  KEY idx_sub_product (product_id),
  KEY idx_sub_period (start_date, end_date)

) ENGINE=InnoDB;

CREATE TABLE seat_change_events (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subscription_id   BIGINT UNSIGNED NOT NULL,

  effective_date    DATE NOT NULL,
  new_license_count INT NOT NULL,

  external_id       VARCHAR(64) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_sce_sub_date (subscription_id, effective_date),
  UNIQUE KEY uq_sce_external (subscription_id, external_id)

) ENGINE=InnoDB;

-- Optional persistence for results + sanity-check queries
CREATE TABLE invoices (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id    BIGINT UNSIGNED NOT NULL,
  period_yyyymm  CHAR(7) NOT NULL, -- 'YYYY-MM'
  currency       CHAR(3) NOT NULL DEFAULT 'EUR',
  subtotal_cents INT NOT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)

) ENGINE=InnoDB;

CREATE TABLE invoice_lines (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id    BIGINT UNSIGNED NOT NULL,
  line_type     ENUM('BASE','UPGRADE_PRORATION') NOT NULL,
  description   VARCHAR(255) NOT NULL,
  amount_cents  INT NOT NULL,
  metadata_json JSON NOT NULL,
  start_date   DATE NOT NULL,
  end_date     DATE NULL,

  PRIMARY KEY (id),

  KEY idx_lines_invoice (invoice_id)
) ENGINE=InnoDB;