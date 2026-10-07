<?php

namespace RRZE\MultisiteManager\SiteOptions;

defined('ABSPATH') || exit;

/**
 * Safely validates values submitted for WordPress option editing.
 */
class OptionValueCodec {
    /** @return array{valid: bool, value: mixed} */
    public function decode(string $rawValue): array {
        $trimmedValue = trim($rawValue);

        if (!is_serialized($trimmedValue)) {
            return ['valid' => true, 'value' => $rawValue];
        }

        if (preg_match('/^(O|C):\d+:/', $trimmedValue) === 1) {
            return ['valid' => false, 'value' => null];
        }

        $value = @unserialize($trimmedValue, ['allowed_classes' => false]);

        if (($value === false && $trimmedValue !== 'b:0;') || is_object($value)) {
            return ['valid' => false, 'value' => null];
        }

        return ['valid' => true, 'value' => $value];
    }

    public function isEditable(string $rawValue): bool {
        $value = maybe_unserialize($rawValue);

        return !is_array($value) && !is_object($value);
    }

    public function toEditableString(string $rawValue): string {
        $value = maybe_unserialize($rawValue);

        if (is_array($value) || is_object($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value === null ? 'null' : (string)$value;
    }
}
