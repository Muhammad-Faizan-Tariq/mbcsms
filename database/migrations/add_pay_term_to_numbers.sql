-- Migration: Add pay_term and payout_rate to sms_numbers table
-- This allows tracking the payout term and rate for each assigned number

ALTER TABLE sms_numbers 
ADD COLUMN IF NOT EXISTS pay_term VARCHAR(10) DEFAULT '1/1' COMMENT 'Payout term: 1/1, 7/1, 7/7, 30/45',
ADD COLUMN IF NOT EXISTS payout_rate DECIMAL(10,6) DEFAULT 0 COMMENT 'Payout rate based on pay_term';

-- Update existing assigned numbers to have default pay_term
UPDATE sms_numbers 
SET pay_term = '1/1', 
    payout_rate = (SELECT payout_1_1 FROM sms_ranges WHERE sms_ranges.id = sms_numbers.range_id)
WHERE status = 'assigned' AND pay_term IS NULL;
