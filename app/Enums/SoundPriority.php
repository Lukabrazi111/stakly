<?php

namespace App\Enums;

/**
 * Per-event sound priority on player notifications. The frontend uses this to
 * pick which audio file to play (or stay silent). `Urgent` = your money is
 * actively moving (taken, settled, dispute, manual review). `Soft` = informational
 * confirmation (cancellation accepted, listing expired refund). `None` = silent.
 */
enum SoundPriority: string
{
    case Urgent = 'urgent';
    case Soft = 'soft';
    case None = 'none';
}
