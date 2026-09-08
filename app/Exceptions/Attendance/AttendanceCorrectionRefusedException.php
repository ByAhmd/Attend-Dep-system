<?php

declare(strict_types=1);

namespace App\Exceptions\Attendance;

use App\Enums\CorrectionRefusalReason;
use RuntimeException;

/**
 * A correction request the rules would not accept, or an approval they
 * would not carry out.
 *
 * The reason travels as an enum so a caller branches on the rule rather
 * than on the sentence, and a test names the rule it is proving. The
 * message is the employee's or the administrator's sentence, already
 * translated, so an interface that knows nothing about corrections can
 * still show something true.
 *
 * Every throw of this leaves the database exactly as it was. Nothing here
 * is a decision about the employee's claim: a request whose approval could
 * not be carried out stays pending, because "the system could not do it" is
 * not "the administrator said no".
 */
final class AttendanceCorrectionRefusedException extends RuntimeException
{
    public function __construct(public readonly CorrectionRefusalReason $reason)
    {
        parent::__construct($reason->message());
    }
}
