<?php

namespace RRZE\MultisiteManager\SiteOptions;

use RRZE\MultisiteManager\Support\SafeSerializedValue;

defined('ABSPATH') || exit;

/**
 * Safely validates values submitted for WordPress option editing.
 */
class OptionValueCodec {
    protected SafeSerializedValue $serializedValue;

    public function __construct(?SafeSerializedValue $serializedValue = null) {
        $this->serializedValue = $serializedValue ?? new SafeSerializedValue();
    }

    /** @return array{valid: bool, value: mixed} */
    public function decode(string $rawValue): array {
        $decoded = $this->serializedValue->decode($rawValue);

        if (!$decoded['serialized']) {
            return ['valid' => true, 'value' => $rawValue];
        }

        return ['valid' => $decoded['valid'], 'value' => $decoded['value']];
    }

    public function isEditable(string $rawValue): bool {
        $decoded = $this->serializedValue->decode($rawValue);
        $value = $decoded['value'];

        return $decoded['valid'] && !is_array($value) && !is_object($value);
    }

    public function toEditableString(string $rawValue): string {
        $decoded = $this->serializedValue->decode($rawValue);
        $value = $decoded['value'];

        if (!$decoded['valid'] || is_array($value) || is_object($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value === null ? 'null' : (string)$value;
    }
}
