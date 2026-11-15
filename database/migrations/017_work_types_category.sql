-- Категория вида работы нужна для показателей «ремонты / упаковки / дополнительные работы» (ТЗ 7.1).
ALTER TABLE work_types ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'other' AFTER unit;

UPDATE work_types SET category = 'repair' WHERE name LIKE 'Ремонт%';

UPDATE work_types SET category = 'packing' WHERE name LIKE 'Упаков%';
