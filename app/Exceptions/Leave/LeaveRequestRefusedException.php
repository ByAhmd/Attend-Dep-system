<?php

declare(strict_types=1);

namespace App\Exceptions\Leave;

use App\Enums\LeaveRefusalReason;
use RuntimeException;

/**
 * A leave request the rules would not accept, or a decision they would not
 * record.
 *
 * Distinct from an administrator's rejection, which is a decision about the
 * request and is stored with the decider's name and note. This is the
 * system declining to hold the request at all; nothing is written and
 * nobody is blamed.
 */
final class LeaveRequestRefusedException extends RuntimeException
{
    public function __construct(public readonly LeaveRefusalReason $reason)
    {
        parent::__construct($reason->message());
    }
}
