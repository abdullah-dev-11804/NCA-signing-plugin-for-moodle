<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.

namespace local_ncasign\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Request-scoped runtime overrides for customcert elements.
 *
 * This allows ncasign to render a customcert template with per-document values
 * without mutating the saved customcert template definition.
 */
class customcert_runtime_overrides {
    /** @var array<int, array<string, string>> */
    private static $stack = [];

    /**
     * Push a new override scope.
     *
     * @param array<string, string> $overrides
     * @return void
     */
    public static function push(array $overrides): void {
        $normalised = [];
        foreach ($overrides as $key => $value) {
            $key = self::normalise_key((string)$key);
            if ($key === '') {
                continue;
            }
            $normalised[$key] = (string)$value;
        }
        self::$stack[] = $normalised;
        error_log(
            'NCASIGN_CANARY customcert_runtime_overrides push' .
            ' depth=' . count(self::$stack) .
            ' key_count=' . count($normalised) .
            ' has_user_full_name=' . (array_key_exists('user_full_name', $normalised) ? '1' : '0') .
            ' user_full_name_length=' .
            (array_key_exists('user_full_name', $normalised)
                ? \core_text::strlen((string)$normalised['user_full_name'])
                : 0) .
            ' user_full_name_hash=' .
            (array_key_exists('user_full_name', $normalised)
                ? hash('sha256', (string)$normalised['user_full_name'])
                : '-')
        );
    }

    /**
     * Pop the current override scope.
     *
     * @return void
     */
    public static function pop(): void {
        if (self::$stack) {
            array_pop(self::$stack);
        }
    }

    /**
     * Resolve an override for a customcert element name.
     *
     * @param string $elementname
     * @return string|null
     */
    public static function get_text_override(string $elementname): ?string {
        $key = self::normalise_key($elementname);
        if ($key === '') {
            return null;
        }

        for ($i = count(self::$stack) - 1; $i >= 0; $i--) {
            if (array_key_exists($key, self::$stack[$i])) {
                if ($key === 'user_full_name') {
                    error_log(
                        'NCASIGN_CANARY customcert_runtime_overrides hit' .
                        ' element=' . $elementname .
                        ' depth=' . count(self::$stack) .
                        ' value_length=' . \core_text::strlen((string)self::$stack[$i][$key]) .
                        ' value_hash=' . hash('sha256', (string)self::$stack[$i][$key])
                    );
                }
                return self::$stack[$i][$key];
            }
        }

        if ($key === 'user_full_name') {
            error_log(
                'NCASIGN_CANARY customcert_runtime_overrides miss' .
                ' element=' . $elementname .
                ' depth=' . count(self::$stack)
            );
        }
        return null;
    }

    /**
     * Normalise keys so template element names can vary slightly in case/style.
     *
     * @param string $key
     * @return string
     */
    private static function normalise_key(string $key): string {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        return \core_text::strtolower($key);
    }
}
