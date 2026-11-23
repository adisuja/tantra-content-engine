<?php

declare(strict_types=1);

namespace App\Tantra\Crm;

/**
 * tantra: One Content app PRD 4.1. Where a person goes in the Tantra app instead of an engine screen. Every engine email link and the
 * account picker hand off point here:
 *
 *   reconnect a channel  https://crm.usetantra.com/content/channels?reconnect=<account>
 *   approvals            https://crm.usetantra.com/content/review
 *   pick accounts        https://crm.usetantra.com/content/channels?finish=<token>
 *   anything else        https://crm.usetantra.com/content
 */
final class CrmPaths
{
    public static function base(): string
    {
        return rtrim((string) (config('tantra.crm.app_url') ?: 'https://crm.usetantra.com'), '/');
    }

    public static function content(): string
    {
        return self::base().'/content';
    }

    public static function review(): string
    {
        return self::base().'/content/review';
    }

    public static function channels(): string
    {
        return self::base().'/content/channels';
    }

    public static function reconnect(?string $accountId = null): string
    {
        return $accountId === null || $accountId === '' ? self::channels() : self::channels().'?reconnect='.rawurlencode($accountId);
    }

    public static function finish(string $token): string
    {
        return self::channels().'?finish='.rawurlencode($token);
    }
}
