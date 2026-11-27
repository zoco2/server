SET @user_account_index_sql = IF(
    EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'la_user'
          AND INDEX_NAME = 'account' AND NON_UNIQUE = 0
    ),
    'ALTER TABLE `la_user` DROP INDEX `account`, ADD UNIQUE INDEX `uk_tenant_account` (`tenant_id`, `account`)',
    'SELECT 1'
);
PREPARE user_account_index_stmt FROM @user_account_index_sql;
EXECUTE user_account_index_stmt;
DEALLOCATE PREPARE user_account_index_stmt;
