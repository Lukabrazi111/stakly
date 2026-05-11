<?php

namespace App\Enums;

enum ListingStatus: string
{
    case Open = 'open';
    case Taken = 'taken';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
