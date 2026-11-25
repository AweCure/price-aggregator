-- 1. Постачальники
CREATE TABLE IF NOT EXISTS suppliers (
                                         id SERIAL PRIMARY KEY,
                                         name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

-- 2. Довідник товарів
CREATE TABLE IF NOT EXISTS products (
                                        id SERIAL PRIMARY KEY,
                                        sku VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL
    );

-- 3. Закупівельні ціни
CREATE TABLE IF NOT EXISTS prices (
                                      id SERIAL PRIMARY KEY,
                                      product_id INT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    supplier_id INT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    price NUMERIC(10, 2) NOT NULL CHECK (price > 0),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (product_id, supplier_id)
    );

-- 4. Журнал імпортів (для твого модуля)
CREATE TABLE IF NOT EXISTS import_log (
                                          id SERIAL PRIMARY KEY,
                                          supplier_id INT NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    filename VARCHAR(255),
    rows_total INT DEFAULT 0,
    rows_ok INT DEFAULT 0,
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

-- Два тестові постачальники
INSERT INTO suppliers (name) VALUES ('Постачальник 1'), ('Постачальник 2')
    ON CONFLICT (name) DO NOTHING;