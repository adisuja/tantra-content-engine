<?php

declare(strict_types=1);

namespace App\Tantra\Review\Enums;

enum EventKind: string
{
    case Imported = 'imported';
    case Revised = 'revised';
    case Approve = 'approve';
    case Unapprove = 'unapprove';
    case RequestChanges = 'request_changes';
    case Reject = 'reject';
    case Comment = 'comment';
    case SignoffNumber = 'signoff_number';
    case Retract = 'retract';
}
