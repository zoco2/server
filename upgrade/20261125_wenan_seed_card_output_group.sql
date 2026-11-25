ALTER TABLE la_wenan_seed_card_job
  ADD COLUMN output_group_id int unsigned NULL DEFAULT NULL,
  ALTER COLUMN group_id SET DEFAULT 0;
