-- likeadmin:tenant-base-config-menus-v1
UPDATE `la_tenant_system_menu`
SET is_disable=1,is_show=0,update_time=UNIX_TIMESTAMP()
WHERE is_disable=0 AND (
    source_menu_key='core_tenant_base_config'
    OR component IN ('setting/base-config','setting/business-price','smart_edit/config','geo/config','channel/share_setting')
);
