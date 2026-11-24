<?php

namespace app\common\cache;

class GeoManuscriptTokenCache extends BaseCache
{
    private const KEY = 'geo_manuscript_open_token_platform';

    public function getPlatformAccessToken(): array
    {
        $value = $this->get(self::KEY, []);
        return is_array($value) ? $value : [];
    }

    public function setPlatformAccessToken(string $token, int $ttl): bool
    {
        return $this->set(self::KEY, [
            'token' => trim($token),
            'issued_at' => time(),
        ], $ttl);
    }

    public function deletePlatformAccessToken(): bool
    {
        return $this->delete(self::KEY);
    }
}