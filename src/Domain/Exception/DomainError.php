<?php
/**
 * Marker interface for domain errors.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Exception;

/**
 * Implemented by every exception thrown by the domain layer.
 *
 * Messages are developer-facing (English, not translated); adapters (REST, admin)
 * map them to translated, user-facing messages.
 */
interface DomainError extends \Throwable {
}
