<?php

namespace RRZE\MultisiteManager\Metrics;

defined('ABSPATH') || exit;

/** Capacity contention is deferred; it is not an analysis failure. */
class StorageAnalysisBusyException extends \RuntimeException {
}
