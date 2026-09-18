CREATE DATABASE IF NOT EXISTS ecommerce_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ecommerce_db;

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS payments, order_items, orders, cart, product_images, products, coupons, categories, contact_messages, users;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE users(
 id INT AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password VARCHAR(255) NOT NULL,
 mobile VARCHAR(20),
 address TEXT,
 city VARCHAR(80),
 state VARCHAR(80),
 pincode VARCHAR(15),
 profile_image VARCHAR(255),
 role ENUM('admin','user') NOT NULL DEFAULT 'user',
 status ENUM('active','blocked') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_users_role(role), INDEX idx_users_status(status)
) ENGINE=InnoDB;

CREATE TABLE categories(
 id INT AUTO_INCREMENT PRIMARY KEY,
 category_name VARCHAR(120) NOT NULL UNIQUE,
 description TEXT,
 status ENUM('active','discontinued') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE products(
 id INT AUTO_INCREMENT PRIMARY KEY,
 category_id INT NOT NULL,
 product_name VARCHAR(180) NOT NULL,
 description TEXT,
 price DECIMAL(10,2) NOT NULL,
 discount DECIMAL(10,2) NOT NULL DEFAULT 0,
 stock INT NOT NULL DEFAULT 0,
 sku VARCHAR(80) NOT NULL UNIQUE,
 image VARCHAR(500),
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_products_category(category_id),
 INDEX idx_products_status(status),
 CONSTRAINT fk_products_category FOREIGN KEY(category_id) REFERENCES categories(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE product_images(
 id INT AUTO_INCREMENT PRIMARY KEY,
 product_id INT NOT NULL,
 image VARCHAR(500) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_product_images(product_id),
 CONSTRAINT fk_product_images_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE coupons(
 id INT AUTO_INCREMENT PRIMARY KEY,
 coupon_code VARCHAR(50) NOT NULL UNIQUE,
 discount_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
 discount_value DECIMAL(10,2) NOT NULL,
 minimum_order DECIMAL(10,2) NOT NULL DEFAULT 0,
 maximum_discount DECIMAL(10,2) NOT NULL DEFAULT 0,
 start_date DATE NOT NULL,
 expiry_date DATE NOT NULL,
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_coupon_dates(start_date,expiry_date)
) ENGINE=InnoDB;

CREATE TABLE cart(
 id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 product_id INT NOT NULL,
 quantity INT NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_cart(user_id,product_id),
 CONSTRAINT fk_cart_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_cart_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE orders(
 id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 total_amount DECIMAL(10,2) NOT NULL,
 coupon_code VARCHAR(50),
 discount DECIMAL(10,2) NOT NULL DEFAULT 0,
 shipping_charge DECIMAL(10,2) NOT NULL DEFAULT 0,
 final_amount DECIMAL(10,2) NOT NULL,
 payment_method VARCHAR(80) NOT NULL,
 payment_status ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
 order_status ENUM('Pending','Confirmed','Processing','Shipped','Out for Delivery','Delivered','Cancelled') NOT NULL DEFAULT 'Pending',
 delivered_status ENUM('Delivered','Not Delivered') NOT NULL DEFAULT 'Not Delivered',
 shipping_name VARCHAR(120) NOT NULL,
 shipping_email VARCHAR(190) NOT NULL,
 shipping_mobile VARCHAR(20) NOT NULL,
 shipping_address TEXT NOT NULL,
 shipping_city VARCHAR(80) NOT NULL,
 shipping_state VARCHAR(80) NOT NULL,
 shipping_pincode VARCHAR(15) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_orders_user(user_id), INDEX idx_orders_status(order_status),
 CONSTRAINT fk_orders_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE order_items(
 id INT AUTO_INCREMENT PRIMARY KEY,
 order_id INT NOT NULL,
 product_id INT NULL,
 product_name VARCHAR(180) NOT NULL,
 price DECIMAL(10,2) NOT NULL,
 quantity INT NOT NULL,
 subtotal DECIMAL(10,2) NOT NULL,
 CONSTRAINT fk_order_items_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 CONSTRAINT fk_order_items_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL,
 INDEX idx_order_items_order(order_id)
) ENGINE=InnoDB;

CREATE TABLE payments(
 id INT AUTO_INCREMENT PRIMARY KEY,
 order_id INT NOT NULL,
 user_id INT NOT NULL,
 payment_method VARCHAR(80) NOT NULL,
 transaction_id VARCHAR(120) NOT NULL UNIQUE,
 amount DECIMAL(10,2) NOT NULL,
 payment_status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
 payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_payments_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,
 CONSTRAINT fk_payments_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE contact_messages(
 id INT AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL,
 mobile VARCHAR(20),
 subject VARCHAR(180) NOT NULL,
 message TEXT NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_contact_email(email)
) ENGINE=InnoDB;

-- Default admin password is: admin123
INSERT INTO users(name,email,password,mobile,role,status) VALUES
('ShopKart Admin','admin@shopkart.local','$2y$10$2b2JQ2lqQ2VxV2F3dEJrUuJ7Q7V7z0s5o4H9bV5qgW1w4QfQ4H8i','9876543210','admin','active');

INSERT INTO categories(category_name,description) VALUES
('Electronics','Smart devices and useful electronics'),
('Fashion','Trendy clothing and accessories'),
('Home & Kitchen','Products for a better home'),
('Beauty','Personal care and beauty products'),
('Sports','Fitness and sports essentials'),
('Books','Books for learning and entertainment');

INSERT INTO products(category_id,product_name,description,price,discount,stock,sku,image) VALUES
(1,'Wireless Headphones','Comfortable wireless headphones with clear sound and long battery life.',2499,500,25,'ELEC-001','https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=900&q=80'),
(1,'Smart Watch','Modern smart watch for everyday activity tracking and notifications.',3999,800,18,'ELEC-002','https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=900&q=80'),
(2,'Classic Sneakers','Comfortable everyday sneakers with a clean modern design.',2999,600,30,'FASH-001','https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=900&q=80'),
(2,'Casual Backpack','Durable backpack suitable for college, office and travel.',1799,300,20,'FASH-002','https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=900&q=80'),
(3,'Coffee Maker','Compact coffee maker for fresh coffee at home.',3499,700,12,'HOME-001','https://images.unsplash.com/photo-1517668808822-9ebb02f2a0e6?auto=format&fit=crop&w=900&q=80'),
(3,'Modern Lamp','Minimal table lamp that adds a warm look to your room.',1599,250,22,'HOME-002','https://images.unsplash.com/photo-1507473885765-e6ed057f782c?auto=format&fit=crop&w=900&q=80'),
(4,'Skincare Set','Daily skincare essentials for a fresh routine.',1299,200,16,'BEAU-001','https://images.unsplash.com/photo-1556228578-8c89e6adf883?auto=format&fit=crop&w=900&q=80'),
(5,'Yoga Mat','Non-slip exercise mat for yoga and workouts.',999,100,40,'SPRT-001','https://images.unsplash.com/photo-1592432678016-e910b452f9a2?auto=format&fit=crop&w=900&q=80'),
(6,'Programming Book','Beginner-friendly programming book for students.',799,100,35,'BOOK-001','https://images.unsplash.com/photo-1543002588-bfa74002ed7e?auto=format&fit=crop&w=900&q=80'),
(1,'Bluetooth Speaker','Portable speaker with rich sound for home and travel.',1999,400,24,'ELEC-003','https://images.unsplash.com/photo-1608043152269-423dbba4e7e1?auto=format&fit=crop&w=900&q=80');

INSERT INTO coupons(coupon_code,discount_type,discount_value,minimum_order,maximum_discount,start_date,expiry_date,status) VALUES
('WELCOME10','percent',10,500,500,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 60 DAY),'active'),
('SAVE200','fixed',200,1500,200,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY),'active');
