<?php

namespace App\Enums;

enum EngagementEventType: string
{
    case Sent = 'sent';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Bounced = 'bounced';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
