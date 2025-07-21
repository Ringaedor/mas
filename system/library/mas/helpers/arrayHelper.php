<?php
/**
 * MAS - Marketing Automation Suite
 * Array Helper
 *
 * This file provides utility methods for array manipulation, filtering,
 * and transformation, specifically tailored for MAS internal use.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Helper;

/**
 * Static helper class for array operations.
 */
class ArrayHelper
{
    /**
     * Gets a value from an array by key, with a default fallback.
     *
     * @param array $array The array to search
     * @param string|int $key The key to look for
     * @param mixed $default The default value if key is not found
     * @return mixed The value or default
     */
    public static function get(array $array, $key, $default = null)
    {
        return $array[$key] ?? $default;
    }

    /**
     * Sets a value in an array by key, creating the array if it does not exist.
     *
     * @param array $array The array to modify
     * @param string|int $key The key to set
     * @param mixed $value The value to assign
     * @return array The modified array
     */
    public static function set(array &$array, $key, $value): array
    {
        $array[$key] = $value;
        return $array;
    }

    /**
     * Checks if an array has a specific key.
     *
     * @param array $array The array to check
     * @param string|int $key The key to look for
     * @return bool True if the key exists, false otherwise
     */
    public static function has(array $array, $key): bool
    {
        return array_key_exists($key, $array);
    }

    /**
     * Removes a value from an array by key.
     *
     * @param array $array The array to modify
     * @param string|int $key The key to remove
     * @return array The modified array
     */
    public static function remove(array &$array, $key): array
    {
        unset($array[$key]);
        return $array;
    }

    /**
     * Filters an array by a callback function.
     *
     * @param array $array The array to filter
     * @param callable $callback The callback function
     * @return array The filtered array
     */
    public static function filter(array $array, callable $callback): array
    {
        return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Maps an array through a callback function.
     *
     * @param array $array The array to map
     * @param callable $callback The callback function
     * @return array The mapped array
     */
    public static function map(array $array, callable $callback): array
    {
        return array_map($callback, $array);
    }

    /**
     * Merges two or more arrays recursively.
     *
     * @param array ...$arrays Arrays to merge
     * @return array The merged array
     */
    public static function mergeRecursive(array ...$arrays): array
    {
        $result = array_shift($arrays);

        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                    $result[$key] = self::mergeRecursive($result[$key], $value);
                } else {
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Flattens a multi-dimensional array into a single level.
     *
     * @param array $array The array to flatten
     * @return array The flattened array
     */
    public static function flatten(array $array): array
    {
        $result = [];
        array_walk_recursive($array, function ($value) use (&$result) {
            $result[] = $value;
        });
        return $result;
    }

    /**
     * Returns the first element of an array.
     *
     * @param array $array The array to process
     * @return mixed|null The first element or null if empty
     */
    public static function first(array $array)
    {
        return reset($array) ?: null;
    }

    /**
     * Returns the last element of an array.
     *
     * @param array $array The array to process
     * @return mixed|null The last element or null if empty
     */
    public static function last(array $array)
    {
        return end($array) ?: null;
    }

    /**
     * Returns an array of values for a specific key in a multi-dimensional array.
     *
     * @param array $array The array to process
     * @param string $key The key to collect
     * @return array The array of values
     */
    public static function pluck(array $array, string $key): array
    {
        return array_column($array, $key);
    }

    /**
     * Groups an array by a specific key.
     *
     * @param array $array The array to group
     * @param string $key The key to group by
     * @return array The grouped array
     */
    public static function groupBy(array $array, string $key): array
    {
        $result = [];
        foreach ($array as $item) {
            $groupKey = $item[$key] ?? null;
            if ($groupKey !== null) {
                $result[$groupKey][] = $item;
            }
        }
        return $result;
    }

    /**
     * Sorts an array by a specific key.
     *
     * @param array $array The array to sort
     * @param string $key The key to sort by
     * @param int $direction SORT_ASC or SORT_DESC
     * @return array The sorted array
     */
    public static function sortBy(array $array, string $key, int $direction = SORT_ASC): array
    {
        $keys = array_column($array, $key);
        array_multisort($keys, $direction, $array);
        return $array;
    }

    /**
     * Converts an array to a JSON string.
     *
     * @param array $array The array to convert
     * @param int $options JSON encoding options
     * @return string The JSON string
     */
    public static function toJson(array $array, int $options = 0): string
    {
        return json_encode($array, $options);
    }

    /**
     * Converts a JSON string to an array.
     *
     * @param string $json The JSON string to decode
     * @param bool $assoc Whether to return associative arrays
     * @return array|null The decoded array, or null on failure
     */
    public static function fromJson(string $json, bool $assoc = true): ?array
    {
        return json_decode($json, $assoc);
    }

    /**
     * Checks if all values in an array are true (strict).
     *
     * @param array $array The array to check
     * @return bool True if all values are true, false otherwise
     */
    public static function allTrue(array $array): bool
    {
        foreach ($array as $value) {
            if ($value !== true) {
                return false;
            }
        }
        return true;
    }

    /**
     * Checks if any value in an array is true (strict).
     *
     * @param array $array The array to check
     * @return bool True if any value is true, false otherwise
     */
    public static function anyTrue(array $array): bool
    {
        foreach ($array as $value) {
            if ($value === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns a new array with only the specified keys.
     *
     * @param array $array The array to filter
     * @param array $keys The keys to keep
     * @return array The filtered array
     */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * Returns a new array without the specified keys.
     *
     * @param array $array The array to filter
     * @param array $keys The keys to exclude
     * @return array The filtered array
     */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /**
     * Returns whether the array is associative (has non-numeric keys).
     *
     * @param array $array The array to check
     * @return bool True if associative, false otherwise
     */
    public static function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}
