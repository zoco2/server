<?php

namespace app\common\model\merchant;

use app\common\enum\FileEnum;
use app\common\model\BaseModel;
use app\common\model\file\TenantFile;
use app\common\service\FileService;

class MerchantGraphic extends BaseModel
{
    public const MAX_IMAGES = 9;

    protected $name = 'merchant_graphic';
    protected $json = ['file_ids'];
    protected $jsonAssoc = true;
    protected $autoWriteTimestamp = 'int';
    protected $type = ['create_time' => 'integer', 'update_time' => 'integer'];

    /** Load image attachments in one query and resolve their current storage URLs. */
    public static function imageFiles(int $tenantId, int $userId, array $fileIds): array
    {
        if ($fileIds === []) {
            return [];
        }

        $rows = TenantFile::where([
            'tenant_id' => $tenantId,
            'source' => FileEnum::SOURCE_USER,
            'source_id' => $userId,
            'type' => FileEnum::IMAGE_TYPE,
        ])->whereIn('id', $fileIds)
            ->field('id,name,uri,storage_scope,storage_engine,storage_domain')
            ->select();

        $files = [];
        foreach ($rows as $file) {
            $id = (int)$file['id'];
            $files[$id] = [
                'id' => $id,
                'name' => $file['name'],
                'url' => FileService::getFileUrlByStorage(
                    (string)$file['uri'],
                    (string)$file['storage_scope'],
                    (string)$file['storage_engine'],
                    (string)$file['storage_domain']
                ),
            ];
        }
        return $files;
    }

    public static function present(array $row, array $files): array
    {
        $images = [];
        foreach ($row['file_ids'] as $id) {
            if (isset($files[$id])) {
                $images[] = $files[$id];
            }
        }
        unset($row['file_ids']);
        $row['files'] = $images;
        return $row;
    }
}
