-- Keeps MSME product names and their prices in separate reportable fields.
ALTER TABLE beneficiaries
    ADD COLUMN IF NOT EXISTS product_price TEXT NULL AFTER primary_products;
