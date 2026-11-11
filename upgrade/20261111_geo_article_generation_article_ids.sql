ALTER TABLE `la_geo_article_generation`
  ADD COLUMN `article_ids` JSON NULL COMMENT '本次任务创建的 GEO 稿件 ID，按生成顺序' AFTER `message`;
