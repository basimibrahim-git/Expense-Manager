<?php

namespace App\Helpers;

/**
 * Error from the Lean API. The message never contains tokens or secrets.
 *
 * $leanStatus is Lean's own "status" field when the response had one
 * (e.g. RECONNECT_REQUIRED, CONSENT_EXPIRED, PENDING).
 */
class LeanException extends \RuntimeException
{
    public int $httpStatus;
    public ?string $leanStatus;

    public function __construct(string $message, int $httpStatus = 0, ?string $leanStatus = null)
    {
        parent::__construct($message, $httpStatus);
        $this->httpStatus = $httpStatus;
        $this->leanStatus = $leanStatus;
    }

    /** The bank connection needs the user to go through the Link flow again. */
    public function needsReconnect(): bool
    {
        return in_array($this->leanStatus, ['RECONNECT_REQUIRED', 'CONSENT_EXPIRED'], true);
    }
}
