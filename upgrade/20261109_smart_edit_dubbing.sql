-- 智能剪辑：TTS 口播。
-- 一个镜头组下，每个配音音色生成一套完整口播（逐句合成后拼接），
-- 成片按 combo 序号轮流使用不同音色的口播。
-- lines_json 保存逐句时间轴，字幕的 TimelineIn/Out 直接取自这里。

CREATE TABLE IF NOT EXISTS `la_smart_edit_dubbing` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `batch_no` varchar(40) NOT NULL DEFAULT '' COMMENT '所属组号',
  `voice_index` int unsigned NOT NULL DEFAULT 0 COMMENT '第几个音色，从 0 起',
  `voice_key` varchar(80) NOT NULL DEFAULT '' COMMENT '音色 key（见 SmartEditVoiceService）',
  `voice_name` varchar(80) NOT NULL DEFAULT '' COMMENT '音色显示名',
  `provider_asset_id` varchar(120) NOT NULL DEFAULT '' COMMENT '火山 speaker ID',
  `audio_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '拼接后整段口播的 OSS object key',
  `duration` decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT '口播总时长秒',
  `lines_json` text NULL COMMENT '逐句时间轴 [{text,duration,timeline_in,timeline_out}]',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/processing/success/failed',
  `error_message` varchar(500) NOT NULL DEFAULT '',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_batch_voice` (`batch_no`,`voice_index`),
  KEY `idx_tenant_batch` (`tenant_id`,`batch_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='智能剪辑 TTS 口播';

-- 组状态补一个「配音中」，让用户知道成片还在排队等语音合成。
-- 用 information_schema 判断后再 ALTER，脚本可重复执行。
SET @smart_edit_dubbing_sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'la_smart_edit_group') > 0,
  'ALTER TABLE `la_smart_edit_group` MODIFY COLUMN `status` varchar(20) NOT NULL DEFAULT ''expanding'' COMMENT ''dubbing/expanding/submitted/failed''',
  'SELECT 1'
);
PREPARE smart_edit_dubbing_stmt FROM @smart_edit_dubbing_sql;
EXECUTE smart_edit_dubbing_stmt;
DEALLOCATE PREPARE smart_edit_dubbing_stmt;
