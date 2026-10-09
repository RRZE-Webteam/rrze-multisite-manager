<?php

namespace RRZE\MultisiteManager\Support;

defined('ABSPATH') || exit;

/** Decodes serialized scalar and array values without allowing PHP objects. */
class SafeSerializedValue {
    public const MAX_BYTES = 1048576;

    /** @return array{valid: bool, value: mixed, serialized: bool} */
    public function decode(mixed $value, int $maxBytes = self::MAX_BYTES): array {
        if (!is_string($value) || !is_serialized($value)) {
            return ['valid' => true, 'value' => $value, 'serialized' => false];
        }

        $value = trim($value);

        if (strlen($value) > max(1, $maxBytes) || preg_match('/^(?:O|C):\d+:/', $value) === 1) {
            return ['valid' => false, 'value' => null, 'serialized' => true];
        }

        $decoded = @unserialize($value, ['allowed_classes' => false]);

        if (($decoded === false && $value !== 'b:0;') || is_object($decoded)) {
            return ['valid' => false, 'value' => null, 'serialized' => true];
        }

        return ['valid' => true, 'value' => $decoded, 'serialized' => true];
    }
}
