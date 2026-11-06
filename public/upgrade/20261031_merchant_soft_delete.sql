-- 已按旧脚本建表时，把 delete_time 改成 ThinkPHP SoftDelete：未删除为 NULL。

ALTER TABLE `la_merchant_brand`
  MODIFY `delete_time` int unsigned NULL DEFAULT NULL COMMENT '删除时间';
UPDATE `la_merchant_brand` SET `delete_time` = NULL WHERE `delete_time` = 0;

ALTER TABLE `la_merchant_store`
  MODIFY `delete_time` int unsigned NULL DEFAULT NULL COMMENT '删除时间';
UPDATE `la_merchant_store` SET `delete_time` = NULL WHERE `delete_time` = 0;

ALTER TABLE `la_merchant_staff`
  MODIFY `delete_time` int unsigned NULL DEFAULT NULL COMMENT '删除时间';
UPDATE `la_merchant_staff` SET `delete_time` = NULL WHERE `delete_time` = 0;
