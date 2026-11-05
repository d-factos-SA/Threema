<?php

/**
 * -------------------------------------------------------------------------
 * Threema plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

namespace GlpiPlugin\Threema;

/**
 * Error returned by the Threema Gateway (or raised before reaching it).
 */
class GatewayException extends \RuntimeException
{
    /** @var int HTTP status code, 0 for transport errors / local validation errors */
    private $http_code;

    /** @var bool */
    private $permanent;

    public function __construct(string $message, int $http_code = 0, bool $permanent = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, $http_code, $previous);
        $this->http_code = $http_code;
        $this->permanent = $permanent;
    }

    public function getHttpCode(): int
    {
        return $this->http_code;
    }

    /**
     * A permanent error will never succeed by retrying the same message
     * (invalid recipient, unknown ID, message too long...).
     * Everything else (network, 401/402 misconfiguration or no credits,
     * 429 rate limit, 5xx) is worth retrying later.
     */
    public function isPermanent(): bool
    {
        return $this->permanent || in_array($this->http_code, [400, 404, 413], true);
    }
}
