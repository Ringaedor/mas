<?php
/**
 * MAS - Marketing Automation Suite
 * Date Helper
 *
 * This file provides utility methods for date manipulation, formatting,
 * timezone handling, and calculations specifically tailored for MAS internal use.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Helper;

use DateTime;
use DateTimeZone;
use DateInterval;
use DatePeriod;
use Exception;

/**
 * Static helper class for date operations.
 */
class DateHelper
{
    /**
     * Default date format used throughout MAS.
     */
    public const DEFAULT_DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * ISO 8601 date format.
     */
    public const ISO_FORMAT = 'c';

    /**
     * Date only format.
     */
    public const DATE_FORMAT = 'Y-m-d';

    /**
     * Time only format.
     */
    public const TIME_FORMAT = 'H:i:s';

    /**
     * Human readable format.
     */
    public const HUMAN_FORMAT = 'F j, Y g:i A';

    /**
     * Gets the current date and time.
     *
     * @param string $format The format to return
     * @param DateTimeZone|null $timezone The timezone to use
     * @return string The formatted current date
     */
    public static function now(string $format = self::DEFAULT_DATE_FORMAT, ?DateTimeZone $timezone = null): string
    {
        $dateTime = new DateTime('now', $timezone);
        return $dateTime->format($format);
    }

    /**
     * Gets the current date and time as a DateTime object.
     *
     * @param DateTimeZone|null $timezone The timezone to use
     * @return DateTime The DateTime object
     */
    public static function nowObject(?DateTimeZone $timezone = null): DateTime
    {
        return new DateTime('now', $timezone);
    }

    /**
     * Formats a date string or DateTime object.
     *
     * @param string|DateTime $date The date to format
     * @param string $format The format to use
     * @param DateTimeZone|null $timezone The timezone to convert to
     * @return string|null The formatted date or null on failure
     */
    public static function format($date, string $format = self::DEFAULT_DATE_FORMAT, ?DateTimeZone $timezone = null): ?string
    {
        try {
            if (is_string($date)) {
                $dateTime = new DateTime($date);
            } elseif ($date instanceof DateTime) {
                $dateTime = clone $date;
            } else {
                return null;
            }

            if ($timezone) {
                $dateTime->setTimezone($timezone);
            }

            return $dateTime->format($format);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Parses a date string into a DateTime object.
     *
     * @param string $date The date string to parse
     * @param string|null $format The format to expect (null for auto-detection)
     * @param DateTimeZone|null $timezone The timezone to use
     * @return DateTime|null The DateTime object or null on failure
     */
    public static function parse(string $date, ?string $format = null, ?DateTimeZone $timezone = null): ?DateTime
    {
        try {
            if ($format) {
                return DateTime::createFromFormat($format, $date, $timezone);
            }
            return new DateTime($date, $timezone);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Adds time to a date.
     *
     * @param string|DateTime $date The base date
     * @param string $interval The interval to add (e.g., '+1 day', '+2 hours')
     * @return DateTime|null The modified DateTime object or null on failure
     */
    public static function add($date, string $interval): ?DateTime
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : clone $date;
            $dateTime->modify($interval);
            return $dateTime;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Subtracts time from a date.
     *
     * @param string|DateTime $date The base date
     * @param string $interval The interval to subtract (e.g., '-1 day', '-2 hours')
     * @return DateTime|null The modified DateTime object or null on failure
     */
    public static function sub($date, string $interval): ?DateTime
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : clone $date;
            $dateTime->modify($interval);
            return $dateTime;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Calculates the difference between two dates.
     *
     * @param string|DateTime $date1 The first date
     * @param string|DateTime $date2 The second date
     * @param bool $absolute Whether to return absolute difference
     * @return DateInterval|null The DateInterval object or null on failure
     */
    public static function diff($date1, $date2, bool $absolute = false): ?DateInterval
    {
        try {
            $dateTime1 = is_string($date1) ? new DateTime($date1) : $date1;
            $dateTime2 = is_string($date2) ? new DateTime($date2) : $date2;
            
            return $dateTime1->diff($dateTime2, $absolute);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Calculates the difference in seconds between two dates.
     *
     * @param string|DateTime $date1 The first date
     * @param string|DateTime $date2 The second date
     * @return int|null The difference in seconds or null on failure
     */
    public static function diffInSeconds($date1, $date2): ?int
    {
        try {
            $dateTime1 = is_string($date1) ? new DateTime($date1) : $date1;
            $dateTime2 = is_string($date2) ? new DateTime($date2) : $date2;
            
            return $dateTime2->getTimestamp() - $dateTime1->getTimestamp();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Calculates the difference in minutes between two dates.
     *
     * @param string|DateTime $date1 The first date
     * @param string|DateTime $date2 The second date
     * @return int|null The difference in minutes or null on failure
     */
    public static function diffInMinutes($date1, $date2): ?int
    {
        $seconds = self::diffInSeconds($date1, $date2);
        return $seconds !== null ? intval($seconds / 60) : null;
    }

    /**
     * Calculates the difference in hours between two dates.
     *
     * @param string|DateTime $date1 The first date
     * @param string|DateTime $date2 The second date
     * @return int|null The difference in hours or null on failure
     */
    public static function diffInHours($date1, $date2): ?int
    {
        $seconds = self::diffInSeconds($date1, $date2);
        return $seconds !== null ? intval($seconds / 3600) : null;
    }

    /**
     * Calculates the difference in days between two dates.
     *
     * @param string|DateTime $date1 The first date
     * @param string|DateTime $date2 The second date
     * @return int|null The difference in days or null on failure
     */
    public static function diffInDays($date1, $date2): ?int
    {
        $seconds = self::diffInSeconds($date1, $date2);
        return $seconds !== null ? intval($seconds / 86400) : null;
    }

    /**
     * Checks if a date is in the past.
     *
     * @param string|DateTime $date The date to check
     * @return bool True if the date is in the past
     */
    public static function isPast($date): bool
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : $date;
            return $dateTime < new DateTime();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Checks if a date is in the future.
     *
     * @param string|DateTime $date The date to check
     * @return bool True if the date is in the future
     */
    public static function isFuture($date): bool
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : $date;
            return $dateTime > new DateTime();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Checks if a date is today.
     *
     * @param string|DateTime $date The date to check
     * @return bool True if the date is today
     */
    public static function isToday($date): bool
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : $date;
            $today = new DateTime();
            return $dateTime->format('Y-m-d') === $today->format('Y-m-d');
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Checks if a date is yesterday.
     *
     * @param string|DateTime $date The date to check
     * @return bool True if the date is yesterday
     */
    public static function isYesterday($date): bool
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : $date;
            $yesterday = new DateTime('-1 day');
            return $dateTime->format('Y-m-d') === $yesterday->format('Y-m-d');
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Checks if a date is tomorrow.
     *
     * @param string|DateTime $date The date to check
     * @return bool True if the date is tomorrow
     */
    public static function isTomorrow($date): bool
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : $date;
            $tomorrow = new DateTime('+1 day');
            return $dateTime->format('Y-m-d') === $tomorrow->format('Y-m-d');
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Gets the start of the day for a given date.
     *
     * @param string|DateTime $date The date
     * @return DateTime|null The start of the day or null on failure
     */
    public static function startOfDay($date): ?DateTime
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : clone $date;
            return $dateTime->setTime(0, 0, 0);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Gets the end of the day for a given date.
     *
     * @param string|DateTime $date The date
     * @return DateTime|null The end of the day or null on failure
     */
    public static function endOfDay($date): ?DateTime
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : clone $date;
            return $dateTime->setTime(23, 59, 59);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Gets the start of the week for a given date.
     *
     * @param string|DateTime $date The date
     * @return DateTime|null The start of the week or null on failure
     */
    public static function startOfWeek($date): ?DateTime
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : clone $date;
            $dayOfWeek = $dateTime->format('N');
            $dateTime->modify('-' . ($dayOfWeek - 1) . ' days');
            return $dateTime->setTime(0, 0, 0);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Gets the end of the week for a given date.
     *
     * @param string|DateTime $date The date
     * @return DateTime|null The end of the week or null on failure
     */
    public static function endOfWeek($date): ?DateTime
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : clone $date;
            $dayOfWeek = $dateTime->format('N');
            $dateTime->modify('+' . (7 - $dayOfWeek) . ' days');
            return $dateTime->setTime(23, 59, 59);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Gets the start of the month for a given date.
     *
     * @param string|DateTime $date The date
     * @return DateTime|null The start of the month or null on failure
     */
    public static function startOfMonth($date): ?DateTime
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : clone $date;
            return $dateTime->setDate($dateTime->format('Y'), $dateTime->format('n'), 1)->setTime(0, 0, 0);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Gets the end of the month for a given date.
     *
     * @param string|DateTime $date The date
     * @return DateTime|null The end of the month or null on failure
     */
    public static function endOfMonth($date): ?DateTime
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : clone $date;
            return $dateTime->setDate($dateTime->format('Y'), $dateTime->format('n'), $dateTime->format('t'))->setTime(23, 59, 59);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Converts a date to a different timezone.
     *
     * @param string|DateTime $date The date to convert
     * @param string|DateTimeZone $timezone The target timezone
     * @return DateTime|null The converted DateTime object or null on failure
     */
    public static function convertTimezone($date, $timezone): ?DateTime
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : clone $date;
            $timezoneObj = is_string($timezone) ? new DateTimeZone($timezone) : $timezone;
            return $dateTime->setTimezone($timezoneObj);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Gets a human-readable time difference (e.g., "2 hours ago", "in 3 days").
     *
     * @param string|DateTime $date The date to compare
     * @param string|DateTime|null $reference The reference date (default: now)
     * @return string The human-readable difference
     */
    public static function humanDiff($date, $reference = null): string
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : $date;
            $referenceTime = $reference ? (is_string($reference) ? new DateTime($reference) : $reference) : new DateTime();
            
            $diff = $referenceTime->diff($dateTime);
            $isPast = $dateTime < $referenceTime;
            
            if ($diff->y > 0) {
                $unit = $diff->y == 1 ? 'year' : 'years';
                return $isPast ? "{$diff->y} {$unit} ago" : "in {$diff->y} {$unit}";
            } elseif ($diff->m > 0) {
                $unit = $diff->m == 1 ? 'month' : 'months';
                return $isPast ? "{$diff->m} {$unit} ago" : "in {$diff->m} {$unit}";
            } elseif ($diff->d > 0) {
                $unit = $diff->d == 1 ? 'day' : 'days';
                return $isPast ? "{$diff->d} {$unit} ago" : "in {$diff->d} {$unit}";
            } elseif ($diff->h > 0) {
                $unit = $diff->h == 1 ? 'hour' : 'hours';
                return $isPast ? "{$diff->h} {$unit} ago" : "in {$diff->h} {$unit}";
            } elseif ($diff->i > 0) {
                $unit = $diff->i == 1 ? 'minute' : 'minutes';
                return $isPast ? "{$diff->i} {$unit} ago" : "in {$diff->i} {$unit}";
            } else {
                return $isPast ? "just now" : "now";
            }
        } catch (Exception $e) {
            return "unknown";
        }
    }

    /**
     * Creates a date range between two dates.
     *
     * @param string|DateTime $start The start date
     * @param string|DateTime $end The end date
     * @param string $interval The interval (e.g., 'P1D' for daily)
     * @return array Array of DateTime objects
     */
    public static function createDateRange($start, $end, string $interval = 'P1D'): array
    {
        try {
            $startDate = is_string($start) ? new DateTime($start) : $start;
            $endDate = is_string($end) ? new DateTime($end) : $end;
            $intervalObj = new DateInterval($interval);
            
            $period = new DatePeriod($startDate, $intervalObj, $endDate);
            $dates = [];
            
            foreach ($period as $date) {
                $dates[] = clone $date;
            }
            
            return $dates;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Validates if a string is a valid date.
     *
     * @param string $date The date string to validate
     * @param string|null $format The expected format (null for auto-detection)
     * @return bool True if valid, false otherwise
     */
    public static function isValid(string $date, ?string $format = null): bool
    {
        return self::parse($date, $format) !== null;
    }

    /**
     * Gets the timestamp from a date.
     *
     * @param string|DateTime $date The date
     * @return int|null The timestamp or null on failure
     */
    public static function getTimestamp($date): ?int
    {
        try {
            $dateTime = is_string($date) ? new DateTime($date) : $date;
            return $dateTime->getTimestamp();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Creates a DateTime object from a timestamp.
     *
     * @param int $timestamp The timestamp
     * @param DateTimeZone|null $timezone The timezone to use
     * @return DateTime|null The DateTime object or null on failure
     */
    public static function fromTimestamp(int $timestamp, ?DateTimeZone $timezone = null): ?DateTime
    {
        try {
            $dateTime = new DateTime();
            $dateTime->setTimestamp($timestamp);
            if ($timezone) {
                $dateTime->setTimezone($timezone);
            }
            return $dateTime;
        } catch (Exception $e) {
            return null;
        }
    }
}