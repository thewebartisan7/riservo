<?php

namespace App\Enums;

enum AssignmentStrategy: string
{
    case FirstAvailable = 'first_available';
    case RoundRobin = 'round_robin';
}
