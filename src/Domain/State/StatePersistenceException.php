<?php
/**
 * StatePersistenceException — typed failure for persistence READ operations.
 *
 * Distinguishes structural/data/hydration failures from "row not found"
 * (which is a normal null/omitted result, never an exception). Message
 * text is technical, bounded, and contains NO raw SQL (SF-003A §2/§12).
 *
 * Thrown when:
 * - a persisted row violates a domain invariant (never silently
 *   normalized or manufactured into a valid object),
 * - a query fails at the database level,
 * - or hydration encounters structurally impossible data (duplicate
 *   identity within one result set).
 *
 * The resolver layer decides later how to degrade safely.
 *
 * @package StateFlow\Domain\State
 */

declare( strict_types = 1 );

namespace StateFlow\Domain\State;

/**
 * Typed persistence-read failure.
 */
final class StatePersistenceException extends \RuntimeException {
}
