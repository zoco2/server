<?php

namespace app\common\cache;

class GeoManuscriptTokenCache extends BaseCache
{
    private const PREFIX = 'geo_manuscript_open_token_';

    public function getAccessToken(int $tenantId): array
    {
        $value = $this->get(self::PREFIX . max(0, $tenantId), []);
        return is_array($value) ? $value : [];
    }

    public function setAccessToken(int $tenantId, string $token, int $ttl): bool
    {
        return $this->set(self::PREFIX . max(0, $tenantId), [
            'token' => trim($token),
            'issued_at' => time(),
        ], $ttl);
    }

    public function deleteAccessToken(int $tenantId): bool
    {
        return $this->delete(self::PREFIX . max(0, $tenantId));
    }
}