<?php

namespace App\Enums;

/**
 * live:    secret workspace key for servers and n8n (dc_live_...).
 * public:  browser key for the website widget, ask only, origin-locked (dc_pub_...).
 * service: platform key for shared bots, not tied to one workspace (dc_svc_...).
 */
enum ApiKeyType: string
{
    case Live = 'live';
    case Public = 'public';
    case Service = 'service';

    public function prefix(): string
    {
        return match ($this) {
            self::Live => 'dc_live_',
            self::Public => 'dc_pub_',
            self::Service => 'dc_svc_',
        };
    }
}
