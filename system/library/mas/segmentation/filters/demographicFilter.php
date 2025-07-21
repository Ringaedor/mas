<?php
/**
 * MAS - Marketing Automation Suite
 * DemographicFilter
 *
 * Demographic filter for customer segmentation based on:
 * - Age and birth date analysis
 * - Gender demographics
 * - Geographic location (country, region, city)
 * - Language preferences
 * - Customer group membership
 * - Registration date patterns
 * - Contact preferences and channels
 *
 * Supports complex demographic targeting with multiple criteria
 * combinations and geographic radius calculations.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Segmentation\Filter;

use Opencart\Library\Mas\Interfaces\SegmentFilterInterface;
use Opencart\Library\Mas\Exception\SegmentException;
use Opencart\Library\Mas\Helper\ArrayHelper;
use Opencart\Library\Mas\Helper\DateHelper;

class DemographicFilter implements SegmentFilterInterface
{
    /**
     * @var array Filter configuration
     */
    protected array $config = [];

    /**
     * @var array Supported age ranges
     */
    protected array $ageRanges = [
        'gen_z' => ['min' => 18, 'max' => 27],
        'millennials' => ['min' => 28, 'max' => 43],
        'gen_x' => ['min' => 44, 'max' => 59],
        'baby_boomers' => ['min' => 60, 'max' => 78],
        'silent_generation' => ['min' => 79, 'max' => 96],
    ];

    /**
     * @var array Supported languages
     */
    protected array $supportedLanguages = [
        'en' => 'English',
        'es' => 'Spanish',
        'fr' => 'French',
        'de' => 'German',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'ru' => 'Russian',
        'zh' => 'Chinese',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'ar' => 'Arabic',
        'hi' => 'Hindi',
    ];

    /**
     * @var array Validation errors
     */
    protected array $validationErrors = [];

    /**
     * Returns the unique filter type.
     *
     * @return string
     */
    public static function getType(): string
    {
        return 'demographic';
    }

    /**
     * Returns a human-readable label for this filter.
     *
     * @return string
     */
    public static function getLabel(): string
    {
        return 'Demographic Analysis';
    }

    /**
     * Returns a description of the filter's purpose.
     *
     * @return string
     */
    public static function getDescription(): string
    {
        return 'Segments customers based on demographic characteristics like age, gender, location, and language';
    }

    /**
     * Returns the schema for this filter's configuration.
     *
     * @return array
     */
    public static function getConfigSchema(): array
    {
        return [
            'age_range' => [
                'type' => 'select',
                'required' => false,
                'label' => 'Age Range',
                'description' => 'Target age demographic',
                'options' => [
                    'gen_z' => 'Gen Z (18-27)',
                    'millennials' => 'Millennials (28-43)',
                    'gen_x' => 'Gen X (44-59)',
                    'baby_boomers' => 'Baby Boomers (60-78)',
                    'silent_generation' => 'Silent Generation (79-96)',
                    'custom' => 'Custom Age Range',
                ],
            ],
            'min_age' => [
                'type' => 'integer',
                'required' => false,
                'min' => 13,
                'max' => 120,
                'label' => 'Minimum Age',
                'description' => 'Minimum age for custom range',
            ],
            'max_age' => [
                'type' => 'integer',
                'required' => false,
                'min' => 13,
                'max' => 120,
                'label' => 'Maximum Age',
                'description' => 'Maximum age for custom range',
            ],
            'gender' => [
                'type' => 'select',
                'required' => false,
                'label' => 'Gender',
                'description' => 'Target gender',
                'options' => [
                    'male' => 'Male',
                    'female' => 'Female',
                    'other' => 'Other',
                    'not_specified' => 'Not Specified',
                ],
            ],
            'countries' => [
                'type' => 'array',
                'required' => false,
                'label' => 'Countries',
                'description' => 'Target countries (ISO codes)',
            ],
            'regions' => [
                'type' => 'array',
                'required' => false,
                'label' => 'Regions/States',
                'description' => 'Target regions or states',
            ],
            'cities' => [
                'type' => 'array',
                'required' => false,
                'label' => 'Cities',
                'description' => 'Target cities',
            ],
            'postal_codes' => [
                'type' => 'array',
                'required' => false,
                'label' => 'Postal Codes',
                'description' => 'Target postal codes or patterns',
            ],
            'radius_km' => [
                'type' => 'integer',
                'required' => false,
                'min' => 1,
                'max' => 1000,
                'label' => 'Radius (km)',
                'description' => 'Geographic radius for location-based targeting',
            ],
            'center_latitude' => [
                'type' => 'float',
                'required' => false,
                'min' => -90,
                'max' => 90,
                'label' => 'Center Latitude',
                'description' => 'Latitude for radius center',
            ],
            'center_longitude' => [
                'type' => 'float',
                'required' => false,
                'min' => -180,
                'max' => 180,
                'label' => 'Center Longitude',
                'description' => 'Longitude for radius center',
            ],
            'languages' => [
                'type' => 'array',
                'required' => false,
                'label' => 'Languages',
                'description' => 'Target languages (ISO codes)',
            ],
            'customer_groups' => [
                'type' => 'array',
                'required' => false,
                'label' => 'Customer Groups',
                'description' => 'Target customer group IDs',
            ],
            'registration_date_from' => [
                'type' => 'date',
                'required' => false,
                'label' => 'Registration Date From',
                'description' => 'Minimum registration date',
            ],
            'registration_date_to' => [
                'type' => 'date',
                'required' => false,
                'label' => 'Registration Date To',
                'description' => 'Maximum registration date',
            ],
            'birth_month' => [
                'type' => 'select',
                'required' => false,
                'label' => 'Birth Month',
                'description' => 'Target birth month',
                'options' => [
                    '1' => 'January',
                    '2' => 'February',
                    '3' => 'March',
                    '4' => 'April',
                    '5' => 'May',
                    '6' => 'June',
                    '7' => 'July',
                    '8' => 'August',
                    '9' => 'September',
                    '10' => 'October',
                    '11' => 'November',
                    '12' => 'December',
                ],
            ],
            'birth_day_range' => [
                'type' => 'array',
                'required' => false,
                'label' => 'Birth Day Range',
                'description' => 'Target birth day range [min, max]',
            ],
            'newsletter_subscription' => [
                'type' => 'boolean',
                'required' => false,
                'label' => 'Newsletter Subscription',
                'description' => 'Newsletter subscription status',
            ],
            'phone_verified' => [
                'type' => 'boolean',
                'required' => false,
                'label' => 'Phone Verified',
                'description' => 'Phone verification status',
            ],
            'email_verified' => [
                'type' => 'boolean',
                'required' => false,
                'label' => 'Email Verified',
                'description' => 'Email verification status',
            ],
            'preferred_contact_method' => [
                'type' => 'select',
                'required' => false,
                'label' => 'Preferred Contact Method',
                'description' => 'Preferred communication channel',
                'options' => [
                    'email' => 'Email',
                    'sms' => 'SMS',
                    'phone' => 'Phone',
                    'mail' => 'Mail',
                    'push' => 'Push Notifications',
                ],
            ],
            'time_zone' => [
                'type' => 'select',
                'required' => false,
                'label' => 'Time Zone',
                'description' => 'Target time zone',
                'options' => [
                    'UTC' => 'UTC',
                    'America/New_York' => 'Eastern Time',
                    'America/Chicago' => 'Central Time',
                    'America/Denver' => 'Mountain Time',
                    'America/Los_Angeles' => 'Pacific Time',
                    'Europe/London' => 'GMT',
                    'Europe/Paris' => 'CET',
                    'Europe/Berlin' => 'CET',
                    'Asia/Tokyo' => 'JST',
                    'Asia/Shanghai' => 'CST',
                    'Australia/Sydney' => 'AEDT',
                ],
            ],
            'include_guests' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'label' => 'Include Guest Customers',
                'description' => 'Include customers without accounts',
            ],
            'exclude_inactive' => [
                'type' => 'boolean',
                'required' => false,
                'default' => true,
                'label' => 'Exclude Inactive Customers',
                'description' => 'Exclude disabled customer accounts',
            ],
            'combine_logic' => [
                'type' => 'select',
                'required' => false,
                'default' => 'AND',
                'label' => 'Combine Logic',
                'description' => 'How to combine multiple criteria',
                'options' => [
                    'AND' => 'All criteria must match',
                    'OR' => 'Any criteria can match',
                ],
            ],
        ];
    }

    /**
     * Sets the configuration array for this filter instance.
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Gets this filter instance's configuration.
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Checks whether the configuration set for this filter is valid.
     *
     * @return bool
     */
    public function validate(): bool
    {
        $this->validationErrors = [];

        // Validate age range
        $this->validateAgeRange();

        // Validate geographic coordinates
        $this->validateGeographicCoordinates();

        // Validate date ranges
        $this->validateDateRanges();

        // Validate birth day range
        $this->validateBirthDayRange();

        // Validate arrays
        $this->validateArrayFields();

        return empty($this->validationErrors);
    }

    /**
     * Applies the filter and returns the array of matching customer IDs.
     *
     * @param array $context
     * @return array
     */
    public function apply(array $context): array
    {
        $db = $context['db'];
        $combineLogic = ArrayHelper::get($this->config, 'combine_logic', 'AND');

        // Build SQL query based on criteria
        $sqlConditions = [];
        $sqlJoins = [];

        // Age criteria
        $this->addAgeCriteria($sqlConditions);

        // Gender criteria
        $this->addGenderCriteria($sqlConditions);

        // Geographic criteria
        $this->addGeographicCriteria($sqlConditions, $sqlJoins);

        // Language criteria
        $this->addLanguageCriteria($sqlConditions);

        // Customer group criteria
        $this->addCustomerGroupCriteria($sqlConditions);

        // Registration date criteria
        $this->addRegistrationDateCriteria($sqlConditions);

        // Birth date criteria
        $this->addBirthDateCriteria($sqlConditions);

        // Contact preferences criteria
        $this->addContactPreferencesCriteria($sqlConditions);

        // Verification status criteria
        $this->addVerificationCriteria($sqlConditions);

        // Base query
        $sql = "SELECT DISTINCT c.customer_id FROM `customer` c";

        // Add joins
        if (!empty($sqlJoins)) {
            $sql .= " " . implode(" ", $sqlJoins);
        }

        // Add conditions
        $whereConditions = [];

        // Include/exclude criteria
        if (!ArrayHelper::get($this->config, 'include_guests', false)) {
            $whereConditions[] = "c.customer_id > 0";
        }

        if (ArrayHelper::get($this->config, 'exclude_inactive', true)) {
            $whereConditions[] = "c.status = 1";
        }

        // Add demographic conditions
        if (!empty($sqlConditions)) {
            $connector = $combineLogic === 'OR' ? ' OR ' : ' AND ';
            $whereConditions[] = '(' . implode($connector, $sqlConditions) . ')';
        }

        if (!empty($whereConditions)) {
            $sql .= " WHERE " . implode(" AND ", $whereConditions);
        }

        $sql .= " ORDER BY c.customer_id";

        $query = $db->query($sql);
        return array_column($query->rows, 'customer_id');
    }

    /**
     * Serializes the filter to an array for storage.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'type' => static::getType(),
            'config' => $this->config,
        ];
    }

    /**
     * Creates a filter instance from an array.
     *
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): self
    {
        $filter = new static();
        $filter->setConfig($data['config'] ?? []);
        return $filter;
    }

    /**
     * Validates age range configuration.
     *
     * @return void
     */
    protected function validateAgeRange(): void
    {
        $ageRange = ArrayHelper::get($this->config, 'age_range');
        $minAge = ArrayHelper::get($this->config, 'min_age');
        $maxAge = ArrayHelper::get($this->config, 'max_age');

        if ($ageRange === 'custom') {
            if ($minAge === null || $maxAge === null) {
                $this->validationErrors[] = 'Custom age range requires both min_age and max_age';
            } elseif ($minAge > $maxAge) {
                $this->validationErrors[] = 'Minimum age cannot be greater than maximum age';
            }
        }

        if ($minAge !== null && ($minAge < 13 || $minAge > 120)) {
            $this->validationErrors[] = 'Minimum age must be between 13 and 120';
        }

        if ($maxAge !== null && ($maxAge < 13 || $maxAge > 120)) {
            $this->validationErrors[] = 'Maximum age must be between 13 and 120';
        }
    }

    /**
     * Validates geographic coordinates.
     *
     * @return void
     */
    protected function validateGeographicCoordinates(): void
    {
        $radiusKm = ArrayHelper::get($this->config, 'radius_km');
        $centerLat = ArrayHelper::get($this->config, 'center_latitude');
        $centerLng = ArrayHelper::get($this->config, 'center_longitude');

        if ($radiusKm !== null) {
            if ($centerLat === null || $centerLng === null) {
                $this->validationErrors[] = 'Radius filtering requires both center_latitude and center_longitude';
            }
        }

        if ($centerLat !== null && ($centerLat < -90 || $centerLat > 90)) {
            $this->validationErrors[] = 'Center latitude must be between -90 and 90';
        }

        if ($centerLng !== null && ($centerLng < -180 || $centerLng > 180)) {
            $this->validationErrors[] = 'Center longitude must be between -180 and 180';
        }
    }

    /**
     * Validates date ranges.
     *
     * @return void
     */
    protected function validateDateRanges(): void
    {
        $dateFrom = ArrayHelper::get($this->config, 'registration_date_from');
        $dateTo = ArrayHelper::get($this->config, 'registration_date_to');

        if ($dateFrom && $dateTo) {
            $fromDate = DateHelper::parse($dateFrom);
            $toDate = DateHelper::parse($dateTo);

            if ($fromDate && $toDate && $fromDate > $toDate) {
                $this->validationErrors[] = 'Registration date from cannot be after registration date to';
            }
        }
    }

    /**
     * Validates birth day range.
     *
     * @return void
     */
    protected function validateBirthDayRange(): void
    {
        $birthDayRange = ArrayHelper::get($this->config, 'birth_day_range');

        if ($birthDayRange && is_array($birthDayRange) && count($birthDayRange) === 2) {
            $min = $birthDayRange[0];
            $max = $birthDayRange[1];

            if ($min < 1 || $min > 31 || $max < 1 || $max > 31) {
                $this->validationErrors[] = 'Birth day range must be between 1 and 31';
            }

            if ($min > $max) {
                $this->validationErrors[] = 'Birth day range minimum cannot be greater than maximum';
            }
        }
    }

    /**
     * Validates array fields.
     *
     * @return void
     */
    protected function validateArrayFields(): void
    {
        $arrayFields = ['countries', 'regions', 'cities', 'postal_codes', 'languages', 'customer_groups'];

        foreach ($arrayFields as $field) {
            $value = ArrayHelper::get($this->config, $field);
            if ($value !== null && !is_array($value)) {
                $this->validationErrors[] = "Field {$field} must be an array";
            }
        }
    }

    /**
     * Adds age criteria to SQL conditions.
     *
     * @param array &$sqlConditions
     * @return void
     */
    protected function addAgeCriteria(array &$sqlConditions): void
    {
        $ageRange = ArrayHelper::get($this->config, 'age_range');
        $minAge = ArrayHelper::get($this->config, 'min_age');
        $maxAge = ArrayHelper::get($this->config, 'max_age');

        if ($ageRange && $ageRange !== 'custom' && isset($this->ageRanges[$ageRange])) {
            $range = $this->ageRanges[$ageRange];
            $minAge = $range['min'];
            $maxAge = $range['max'];
        }

        if ($minAge !== null || $maxAge !== null) {
            $ageConditions = [];

            if ($minAge !== null) {
                $ageConditions[] = "TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) >= " . (int)$minAge;
            }

            if ($maxAge !== null) {
                $ageConditions[] = "TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) <= " . (int)$maxAge;
            }

            if (!empty($ageConditions)) {
                $sqlConditions[] = "(" . implode(" AND ", $ageConditions) . ")";
            }
        }
    }

    /**
     * Adds gender criteria to SQL conditions.
     *
     * @param array &$sqlConditions
     * @return void
     */
    protected function addGenderCriteria(array &$sqlConditions): void
    {
        $gender = ArrayHelper::get($this->config, 'gender');

        if ($gender) {
            if ($gender === 'not_specified') {
                $sqlConditions[] = "(c.gender IS NULL OR c.gender = '')";
            } else {
                $sqlConditions[] = "c.gender = '" . $gender . "'";
            }
        }
    }

    /**
     * Adds geographic criteria to SQL conditions.
     *
     * @param array &$sqlConditions
     * @param array &$sqlJoins
     * @return void
     */
    protected function addGeographicCriteria(array &$sqlConditions, array &$sqlJoins): void
    {
        $countries = ArrayHelper::get($this->config, 'countries');
        $regions = ArrayHelper::get($this->config, 'regions');
        $cities = ArrayHelper::get($this->config, 'cities');
        $postalCodes = ArrayHelper::get($this->config, 'postal_codes');
        $radiusKm = ArrayHelper::get($this->config, 'radius_km');

        if ($countries || $regions || $cities || $postalCodes || $radiusKm) {
            $sqlJoins[] = "LEFT JOIN `address` a ON c.address_id = a.address_id";

            if ($countries) {
                $countryList = "'" . implode("','", array_map('addslashes', $countries)) . "'";
                $sqlConditions[] = "a.country IN ({$countryList})";
            }

            if ($regions) {
                $regionList = "'" . implode("','", array_map('addslashes', $regions)) . "'";
                $sqlConditions[] = "a.zone IN ({$regionList})";
            }

            if ($cities) {
                $cityList = "'" . implode("','", array_map('addslashes', $cities)) . "'";
                $sqlConditions[] = "a.city IN ({$cityList})";
            }

            if ($postalCodes) {
                $postalConditions = [];
                foreach ($postalCodes as $postalCode) {
                    $postalConditions[] = "a.postcode LIKE '" . addslashes($postalCode) . "%'";
                }
                $sqlConditions[] = "(" . implode(" OR ", $postalConditions) . ")";
            }

            if ($radiusKm) {
                $centerLat = ArrayHelper::get($this->config, 'center_latitude');
                $centerLng = ArrayHelper::get($this->config, 'center_longitude');

                if ($centerLat !== null && $centerLng !== null) {
                    $sqlConditions[] = "
                        (6371 * acos(
                            cos(radians({$centerLat})) * 
                            cos(radians(a.latitude)) * 
                            cos(radians(a.longitude) - radians({$centerLng})) + 
                            sin(radians({$centerLat})) * 
                            sin(radians(a.latitude))
                        )) <= {$radiusKm}
                    ";
                }
            }
        }
    }

    /**
     * Adds language criteria to SQL conditions.
     *
     * @param array &$sqlConditions
     * @return void
     */
    protected function addLanguageCriteria(array &$sqlConditions): void
    {
        $languages = ArrayHelper::get($this->config, 'languages');

        if ($languages) {
            $languageList = "'" . implode("','", array_map('addslashes', $languages)) . "'";
            $sqlConditions[] = "c.language_id IN ({$languageList})";
        }
    }

    /**
     * Adds customer group criteria to SQL conditions.
     *
     * @param array &$sqlConditions
     * @return void
     */
    protected function addCustomerGroupCriteria(array &$sqlConditions): void
    {
        $customerGroups = ArrayHelper::get($this->config, 'customer_groups');

        if ($customerGroups) {
            $groupList = implode(',', array_map('intval', $customerGroups));
            $sqlConditions[] = "c.customer_group_id IN ({$groupList})";
        }
    }

    /**
     * Adds registration date criteria to SQL conditions.
     *
     * @param array &$sqlConditions
     * @return void
     */
    protected function addRegistrationDateCriteria(array &$sqlConditions): void
    {
        $dateFrom = ArrayHelper::get($this->config, 'registration_date_from');
        $dateTo = ArrayHelper::get($this->config, 'registration_date_to');

        if ($dateFrom) {
            $sqlConditions[] = "DATE(c.date_added) >= '" . addslashes($dateFrom) . "'";
        }

        if ($dateTo) {
            $sqlConditions[] = "DATE(c.date_added) <= '" . addslashes($dateTo) . "'";
        }
    }

    /**
     * Adds birth date criteria to SQL conditions.
     *
     * @param array &$sqlConditions
     * @return void
     */
    protected function addBirthDateCriteria(array &$sqlConditions): void
    {
        $birthMonth = ArrayHelper::get($this->config, 'birth_month');
        $birthDayRange = ArrayHelper::get($this->config, 'birth_day_range');

        if ($birthMonth) {
            $sqlConditions[] = "MONTH(c.dob) = " . (int)$birthMonth;
        }

        if ($birthDayRange && is_array($birthDayRange) && count($birthDayRange) === 2) {
            $minDay = (int)$birthDayRange[0];
            $maxDay = (int)$birthDayRange[1];
            $sqlConditions[] = "DAY(c.dob) BETWEEN {$minDay} AND {$maxDay}";
        }
    }

    /**
     * Adds contact preferences criteria to SQL conditions.
     *
     * @param array &$sqlConditions
     * @return void
     */
    protected function addContactPreferencesCriteria(array &$sqlConditions): void
    {
        $newsletterSubscription = ArrayHelper::get($this->config, 'newsletter_subscription');
        $preferredContactMethod = ArrayHelper::get($this->config, 'preferred_contact_method');
        $timeZone = ArrayHelper::get($this->config, 'time_zone');

        if ($newsletterSubscription !== null) {
            $sqlConditions[] = "c.newsletter = " . ($newsletterSubscription ? 1 : 0);
        }

        if ($preferredContactMethod) {
            $sqlConditions[] = "c.preferred_contact_method = '" . addslashes($preferredContactMethod) . "'";
        }

        if ($timeZone) {
            $sqlConditions[] = "c.timezone = '" . addslashes($timeZone) . "'";
        }
    }

    /**
     * Adds verification criteria to SQL conditions.
     *
     * @param array &$sqlConditions
     * @return void
     */
    protected function addVerificationCriteria(array &$sqlConditions): void
    {
        $phoneVerified = ArrayHelper::get($this->config, 'phone_verified');
        $emailVerified = ArrayHelper::get($this->config, 'email_verified');

        if ($phoneVerified !== null) {
            $sqlConditions[] = "c.phone_verified = " . ($phoneVerified ? 1 : 0);
        }

        if ($emailVerified !== null) {
            $sqlConditions[] = "c.email_verified = " . ($emailVerified ? 1 : 0);
        }
    }

    /**
     * Gets validation errors.
     *
     * @return array
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Gets supported age ranges.
     *
     * @return array
     */
    public function getSupportedAgeRanges(): array
    {
        return $this->ageRanges;
    }

    /**
     * Gets supported languages.
     *
     * @return array
     */
    public function getSupportedLanguages(): array
    {
        return $this->supportedLanguages;
    }

    /**
     * Calculates distance between two geographic points.
     *
     * @param float $lat1
     * @param float $lng1
     * @param float $lat2
     * @param float $lng2
     * @return float Distance in kilometers
     */
    protected function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // Earth's radius in kilometers

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Gets demographic statistics for a set of customers.
     *
     * @param array $customerIds
     * @param object $db
     * @return array
     */
    public function getDemographicStatistics(array $customerIds, $db): array
    {
        if (empty($customerIds)) {
            return [];
        }

        $customerList = implode(',', array_map('intval', $customerIds));

        $query = $db->query("
            SELECT 
                COUNT(*) as total_customers,
                AVG(TIMESTAMPDIFF(YEAR, c.dob, CURDATE())) as avg_age,
                SUM(CASE WHEN c.gender = 'male' THEN 1 ELSE 0 END) as male_count,
                SUM(CASE WHEN c.gender = 'female' THEN 1 ELSE 0 END) as female_count,
                SUM(CASE WHEN c.newsletter = 1 THEN 1 ELSE 0 END) as newsletter_subscribers,
                COUNT(DISTINCT a.country) as countries_count,
                COUNT(DISTINCT a.zone) as regions_count,
                COUNT(DISTINCT a.city) as cities_count
            FROM `customer` c
            LEFT JOIN `address` a ON c.address_id = a.address_id
            WHERE c.customer_id IN ({$customerList})
        ");

        $stats = $query->row;

        return [
            'total_customers' => (int)$stats['total_customers'],
            'average_age' => round($stats['avg_age'], 1),
            'male_percentage' => $stats['total_customers'] > 0 ? 
                round(($stats['male_count'] / $stats['total_customers']) * 100, 2) : 0,
            'female_percentage' => $stats['total_customers'] > 0 ? 
                round(($stats['female_count'] / $stats['total_customers']) * 100, 2) : 0,
            'newsletter_percentage' => $stats['total_customers'] > 0 ? 
                round(($stats['newsletter_subscribers'] / $stats['total_customers']) * 100, 2) : 0,
            'countries_count' => (int)$stats['countries_count'],
            'regions_count' => (int)$stats['regions_count'],
            'cities_count' => (int)$stats['cities_count'],
        ];
    }

    /**
     * Gets top countries for a set of customers.
     *
     * @param array $customerIds
     * @param object $db
     * @param int $limit
     * @return array
     */
    public function getTopCountries(array $customerIds, $db, int $limit = 10): array
    {
        if (empty($customerIds)) {
            return [];
        }

        $customerList = implode(',', array_map('intval', $customerIds));

        $query = $db->query("
            SELECT 
                a.country,
                COUNT(*) as customer_count,
                (COUNT(*) / (SELECT COUNT(*) FROM `customer` WHERE customer_id IN ({$customerList}))) * 100 as percentage
            FROM `customer` c
            JOIN `address` a ON c.address_id = a.address_id
            WHERE c.customer_id IN ({$customerList})
            AND a.country IS NOT NULL
            AND a.country != ''
            GROUP BY a.country
            ORDER BY customer_count DESC
            LIMIT {$limit}
        ");

        return $query->rows;
    }

    /**
     * Gets age distribution for a set of customers.
     *
     * @param array $customerIds
     * @param object $db
     * @return array
     */
    public function getAgeDistribution(array $customerIds, $db): array
    {
        if (empty($customerIds)) {
            return [];
        }

        $customerList = implode(',', array_map('intval', $customerIds));

        $query = $db->query("
            SELECT 
                CASE 
                    WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) BETWEEN 18 AND 27 THEN 'Gen Z (18-27)'
                    WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) BETWEEN 28 AND 43 THEN 'Millennials (28-43)'
                    WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) BETWEEN 44 AND 59 THEN 'Gen X (44-59)'
                    WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) BETWEEN 60 AND 78 THEN 'Baby Boomers (60-78)'
                    WHEN TIMESTAMPDIFF(YEAR, c.dob, CURDATE()) >= 79 THEN 'Silent Generation (79+)'
                    ELSE 'Unknown'
                END as age_group,
                COUNT(*) as customer_count
            FROM `customer` c
            WHERE c.customer_id IN ({$customerList})
            AND c.dob IS NOT NULL
            GROUP BY age_group
            ORDER BY customer_count DESC
        ");

        return $query->rows;
    }
}
