ALTER TABLE `la_geo_article_generation`
  ADD KEY `idx_geo_generation_owner` (`tenant_id`, `user_id`, `id`);
