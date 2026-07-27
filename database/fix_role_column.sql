-- Fix: change users.role from ENUM (old 7 roles) to VARCHAR(50)
-- Run this in phpMyAdmin before creating any new staff accounts
-- Safe to run multiple times

ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'cs_ops';
