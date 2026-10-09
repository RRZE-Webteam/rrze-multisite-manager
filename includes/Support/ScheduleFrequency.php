<?php

namespace RRZE\MultisiteManager\Support;

use RRZE\MultisiteManager\Config;

defined('ABSPATH') || exit;

/**
 * Converts between persisted scheduler frequency keys and intervals.
 */
class ScheduleFrequency {
    protected Config $config;

    public function __construct(Config $config) {
        $this->config = $config;
    }

    public function fromHours(int $hours): string {
        return $this->config->getSchedulerFrequencyFromHours($hours);
    }

    public function toHours(string $frequency): int {
        return $this->config->getSchedulerFrequencyHours($frequency);
    }

    public function toSeconds(string $frequency): int {
        return $this->toHours($frequency) * HOUR_IN_SECONDS;
    }

    public function label(string $frequency): string {
        return $this->config->getSchedulerFrequencyLabel($frequency);
    }
}
