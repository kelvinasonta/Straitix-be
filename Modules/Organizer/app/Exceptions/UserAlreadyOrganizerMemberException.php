<?php

namespace Modules\Organizer\app\Exceptions;

class UserAlreadyOrganizerMemberException extends OrganizerException
{
    public function __construct(
        string $message = 'User is already a team member of this organizer.',
        int $status = 409,
        string $errorCode = 'USER_ALREADY_ORGANIZER_MEMBER',
    ) {
        parent::__construct(
            message: $message,
            status: $status,
            errorCode: $errorCode,
        );
    }
}
