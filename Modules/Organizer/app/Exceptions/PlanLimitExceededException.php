<?php

namespace Modules\Organizer\app\Exceptions;

class PlanLimitExceededException extends OrganizerException
{
    public function __construct(string $message = 'Plan limit exceeded.')
    {
        parent::__construct(
            message: $message,
            status: 422,
            errorCode: 'PLAN_LIMIT_EXCEEDED',
        );
    }
}

