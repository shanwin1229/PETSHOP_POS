CREATE DATABASE IF NOT EXISTS `petshop_pos` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `petshop_pos`;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS stock_history;
DROP TABLE IF EXISTS supplier_products;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS vaccinations;
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS transaction_items;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS sales_items;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS pets;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  first_name VARCHAR(100) NOT NULL DEFAULT '',
  last_name VARCHAR(100) NOT NULL DEFAULT '',
  email VARCHAR(150) NULL UNIQUE,
  role ENUM('Admin','Cashier','Groomer') NOT NULL DEFAULT 'Cashier',
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','inactive','suspended','deleted') NOT NULL DEFAULT 'active',
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  sku VARCHAR(100) NULL UNIQUE,
  category VARCHAR(100) NULL,
  selling_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  cost_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  stock_qty INT NOT NULL DEFAULT 0,
  reorder_level INT NOT NULL DEFAULT 5,
  expiry_date DATE NULL,
  status ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
  description TEXT NULL,
  image_path VARCHAR(255) DEFAULT 'uploads/default.png',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_products_name (name),
  INDEX idx_products_category (category),
  INDEX idx_products_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  contact VARCHAR(50) NOT NULL,
  email VARCHAR(150) NULL,
  address TEXT NULL,
  birthday DATE NULL,
  joined DATE NOT NULL DEFAULT (CURRENT_DATE),  total_spent DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_customers_name (last_name, first_name),
  INDEX idx_customers_contact (contact),
  INDEX idx_customers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  species VARCHAR(50) NOT NULL,
  breed VARCHAR(100) NULL,
  age VARCHAR(50) NULL,
  gender ENUM('Male','Female') NOT NULL,
  weight DECIMAL(8,2) NULL,
  color VARCHAR(80) NULL,
  birthdate DATE NULL,
  notes TEXT NULL,
  status ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pets_owner (owner_id),
  INDEX idx_pets_species (species),
  CONSTRAINT fk_pets_customer FOREIGN KEY (owner_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vaccinations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pet_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  date DATE NULL,
  due_date DATE NULL,
  status ENUM('done','due') NOT NULL DEFAULT 'due',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_vacc_pet (pet_id),
  CONSTRAINT fk_vacc_pet FOREIGN KEY (pet_id) REFERENCES pets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pet_id INT UNSIGNED NOT NULL,
  service VARCHAR(100) NOT NULL,
  groomer VARCHAR(120) NOT NULL,
  date DATE NOT NULL,
  time TIME NOT NULL,
  duration VARCHAR(50) NOT NULL DEFAULT '1 hour',
  status ENUM('pending','confirmed','completed','cancelled','deleted') NOT NULL DEFAULT 'pending',
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_appt_date (date),
  INDEX idx_appt_status (status),
  INDEX idx_appt_pet (pet_id),
  CONSTRAINT fk_appt_pet FOREIGN KEY (pet_id) REFERENCES pets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  txn_no VARCHAR(40) NOT NULL UNIQUE,
  customer_id INT UNSIGNED NULL,
  cashier_id INT UNSIGNED NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount_type VARCHAR(30) NULL,
  discount_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  change_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_method VARCHAR(50) NOT NULL DEFAULT 'cash',  status ENUM('completed','void','refunded','pending') NOT NULL DEFAULT 'completed',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_txn_date (created_at),
  INDEX idx_txn_customer (customer_id),
  CONSTRAINT fk_txn_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_txn_cashier FOREIGN KEY (cashier_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transaction_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  INDEX idx_ti_txn (transaction_id),
  INDEX idx_ti_product (product_id),
  CONSTRAINT fk_ti_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  CONSTRAINT fk_ti_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  contact_person VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  address TEXT NULL,
  website VARCHAR(255) NULL,
  payment_terms VARCHAR(80) NULL,
  notes TEXT NULL,
  status ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_suppliers_status (status),
  INDEX idx_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_products (
  supplier_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id, product_id),
  CONSTRAINT fk_sp_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
  CONSTRAINT fk_sp_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  type ENUM('in','out','adjust') NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  stock_before INT NOT NULL DEFAULT 0,
  stock_after INT NOT NULL DEFAULT 0,
  reason VARCHAR(150) NULL,
  remarks TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_stock_product (product_id),
  INDEX idx_stock_date (created_at),
  CONSTRAINT fk_stock_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(120) NOT NULL DEFAULT 'System',
  role VARCHAR(30) NOT NULL DEFAULT 'System',
  category VARCHAR(30) NOT NULL DEFAULT 'general',
  action VARCHAR(150) NOT NULL,
  description TEXT NULL,
  details TEXT NULL,
  entity_type VARCHAR(60) NULL,
  entity_id INT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  meta JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_category (category),
  INDEX idx_audit_created_at (created_at),
  INDEX idx_audit_user_id (user_id),
  INDEX idx_audit_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log LIKE audit_logs;

CREATE TABLE sales (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  total_amount DECIMAL(10,2) NOT NULL,
  payment_method VARCHAR(50) NOT NULL DEFAULT 'cash',
  cash_received DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  sale_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  price_at_sale DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT fk_sales_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


INSERT INTO users (username, first_name, last_name, email, role, password_hash, status)
VALUES
('grace_admin','Grace','Admin','grace_admin@example.com','Admin','$2y$12$P4tExLpNoptJd3q4ODNBoucQtJWO9xfTrd2rmQcz3P7/P8g2Kb8hq','active'),
('miku_cashier','Miku','Cashier','miku_cashier@example.com','Cashier','$2y$12$qQRxh2UMNndWmA8uWEJE9OFM.0ftGj.ti0bgxOyGKb0Tmii6nQDA.','active'),
('havana_groomer','Havana','Groomer','havana_groomer@example.com','Groomer','$2y$12$MycIdAad3DLRBS6HYbNLrO2qdZ7RGZyzOwuyQ2ESHlpJA2xNktema','active');

INSERT INTO products (name, sku, category, selling_price, cost_price, stock_qty, reorder_level, expiry_date, status, description)
VALUES
('Whiskas Cat Food 1kg','WHISKAS-001','Pet Food',200.00,145.00,25,5,NULL,'active','Cat food for adult cats'),
('Pedigree Dog Food 1kg','PEDIGREE-001','Pet Food',220.00,160.00,22,5,NULL,'active','Dog food for adult dogs'),
('Royal Canin Puppy 1kg','RC-PUPPY-001','Pet Food',480.00,360.00,12,4,NULL,'active','Premium puppy food'),
('Flea and Tick Shampoo','SHAMPOO-001','Grooming',180.00,95.00,18,5,'2027-03-30','active','Grooming shampoo'),
('Dog Collar Medium','COLLAR-001','Accessories',150.00,70.00,30,6,NULL,'active','Adjustable collar'),
('Cat Litter 5L','LITTER-001','Supplies',260.00,180.00,16,5,NULL,'active','Odor control cat litter'),
('Pet Vitamins 60ml','VIT-001','Medicine',320.00,210.00,10,4,'2027-08-15','active','Daily pet vitamins'),
('Chew Toy Bone','TOY-001','Accessories',120.00,50.00,35,8,NULL,'active','Rubber chew toy');

INSERT INTO customers (first_name, last_name, contact, email, address, birthday, joined, total_spent, status)
VALUES
('Walk-in','Customer','N/A',NULL,'',NULL,CURDATE(),0.00,'active'),
('Juan','Dela Cruz','09171234567','juan@example.com','Quezon City','1995-04-12',CURDATE(),0.00,'active'),
('Maria','Santos','09181234567','maria@example.com','Manila','1998-09-21',CURDATE(),0.00,'active'),
('Peter','Reyes','09191234567','peter@example.com','Makati','1993-01-10',CURDATE(),0.00,'active'),
('Ana','Gonzales','09201234567','ana@example.com','Pasig','1997-06-18',CURDATE(),0.00,'active');

INSERT INTO pets (owner_id, name, species, breed, age, gender, weight, color, notes, status)
VALUES
(2,'Milo','Dog','Shih Tzu',3,'Male',5.20,'White','No known allergies','active'),
(3,'Luna','Cat','Persian',2,'Female',3.80,'Gray','Sensitive skin','active'),
(4,'Max','Dog','Golden Retriever',4,'Male',24.50,'Golden','Vaccinated','active'),
(5,'Coco','Dog','Poodle',1,'Female',4.10,'Brown','First grooming visit','active');

INSERT INTO vaccinations (pet_id, name, date, due_date, status)
VALUES
(1,'Anti-Rabies','2026-01-10','2027-01-10','done'),
(2,'FVRCP','2026-02-12','2027-02-12','done'),
(3,'5-in-1 Vaccine','2026-03-01','2027-03-01','done');

INSERT INTO appointments (pet_id, service, groomer, date, time, duration, status, notes)
VALUES
(1,'Full Grooming','Havana Groomer',CURDATE(),'09:00:00','1 hour','confirmed','Sample grooming appointment'),
(2,'Bath and Blow Dry','Havana Groomer',CURDATE(),'11:00:00','1 hour','pending','Handle gently'),
(3,'Nail Trimming','Havana Groomer',DATE_ADD(CURDATE(), INTERVAL 1 DAY),'14:00:00','30 minutes','confirmed','Large dog'),
(4,'Basic Grooming','Havana Groomer',DATE_ADD(CURDATE(), INTERVAL 2 DAY),'10:30:00','1 hour','pending','New customer');

INSERT INTO suppliers (name, contact_person, phone, email, address, website, payment_terms, notes, status)
VALUES
('PetCare Supplies PH','Ramon Cruz','09170001111','sales@petcare.ph','Quezon City','https://petcare.ph','Cash on delivery','Food and supplies supplier','active'),
('GroomPro Trading','Liza Tan','09180002222','orders@groompro.ph','Manila','','15 days','Grooming products supplier','active'),
('VetMed Distributor','Carlos Lim','09190003333','support@vetmed.ph','Makati','','30 days','Medicine supplier','active');

INSERT INTO supplier_products (supplier_id, product_id, is_primary)
VALUES
(1,1,1),(1,2,1),(1,3,1),(2,4,1),(2,5,1),(2,6,1),(3,7,1);

INSERT INTO stock_history (product_id, type, quantity, stock_before, stock_after, reason, remarks, created_by)
VALUES
(1,'in',25,0,25,'Initial stock','Sample opening stock',1),
(2,'in',22,0,22,'Initial stock','Sample opening stock',1),
(3,'in',12,0,12,'Initial stock','Sample opening stock',1),
(4,'in',18,0,18,'Initial stock','Sample opening stock',1),
(5,'in',30,0,30,'Initial stock','Sample opening stock',1),
(6,'in',16,0,16,'Initial stock','Sample opening stock',1),
(7,'in',10,0,10,'Initial stock','Sample opening stock',1),
(8,'in',35,0,35,'Initial stock','Sample opening stock',1);

INSERT INTO transactions (txn_no, customer_id, cashier_id, subtotal, discount_amount, discount_type, discount_value, tax_amount, total_amount, amount_paid, change_amount, payment_method, status, created_at)
VALUES
('TXN-SAMPLE-001',2,2,400.00,0.00,'peso',0.00,0.00,400.00,500.00,100.00,'cash','completed',NOW()),
('TXN-SAMPLE-002',3,2,180.00,0.00,'peso',0.00,0.00,180.00,200.00,20.00,'cash','completed',NOW());

INSERT INTO transaction_items (transaction_id, product_id, quantity, unit_price, line_total)
VALUES
(1,1,2,200.00,400.00),
(2,4,1,180.00,180.00);

UPDATE customers SET total_spent = 400.00 WHERE id = 2;
UPDATE customers SET total_spent = 180.00 WHERE id = 3;

SET FOREIGN_KEY_CHECKS = 1;
