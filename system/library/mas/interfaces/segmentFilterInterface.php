<?php
/**
 * MAS - Marketing Automation Suite
 * SegmentFilterInterface
 *
 * Contract for all segment filter classes used for audience segmentation in MAS.
 * Each filter represents a distinct criterion or strategy for segmenting customers (RFM, behavioral, predictive, etc).
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Interfaces;

interface SegmentFilterInterface
{
    /**
     * Returns the unique filter type (e.g., 'rfm', 'behavioral', 'predictive', 'demographic').
     *
     * @return string
     */
    public static function getType(): string;

    /**
     * Returns a human-readable label for this filter.
     *
     * @return string
     */
    public static function getLabel(): string;

    /**
     * Returns a description of the filter's purpose.
     *
     * @return string
     */
    public static function getDescription(): string;

    /**
     * Returns the schema for this filter's configuration.
     * Used to generate the UI for filter criteria.
     *
     * @return array
     */
    public static function getConfigSchema(): array;

    /**
     * Sets the configuration array for this filter instance.
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void;

    /**
     * Gets this filter instance's configuration.
     *
     * @return array
     */
    public function getConfig(): array;

    /**
     * Checks whether the configuration set for this filter is valid.
     *
     * @return bool
     */
    public function validate(): bool;

    /**
     * Applies the filter and returns the array of matching customer IDs.
     *
     * @param array $context Context data (may include database/persistence objects, input dataset, etc)
     * @return array Matching customer IDs
     */
    public function apply(array $context): array;

    /**
     * Serializes the filter to an array for storage.
     *
     * @return array
     */
    public function toArray(): array;

    /**
     * Creates a filter instance from an array (for deserialization).
     *
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): self;
}
