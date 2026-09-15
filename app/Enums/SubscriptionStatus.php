<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Ended = 'ended';
    case Changed = 'changed';
}
