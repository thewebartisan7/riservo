<?php

namespace App\Enums;

enum BusinessUserRole: string
{
    case Admin = 'admin';
    case Collaborator = 'collaborator';
}
