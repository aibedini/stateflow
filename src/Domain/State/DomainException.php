<?php
/**
 * Domain exception for StateFlow state invariants.
 *
 * @package StateFlow\Domain\State
 */

declare( strict_types = 1 );

namespace StateFlow\Domain\State;

/**
 * Thrown when domain invariants are violated (invalid keys, invalid
 * definition fields). Part of the public domain contract.
 */
class DomainException extends \DomainException {
}
