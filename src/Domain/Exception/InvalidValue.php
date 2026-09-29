<?php
/**
 * Invalid value passed to a domain object.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Exception;

/**
 * Thrown when a value object or entity is constructed with invalid data.
 */
final class InvalidValue extends \InvalidArgumentException implements DomainError {
}
