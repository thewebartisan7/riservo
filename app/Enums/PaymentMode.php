<?php

namespace App\Enums;

enum PaymentMode: string
{
    case Offline = 'offline';
    case Online = 'online';
    case CustomerChoice = 'customer_choice';
}
