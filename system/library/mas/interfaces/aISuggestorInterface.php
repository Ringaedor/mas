<?php
/**
 * MAS - Marketing Automation Suite
 * AiSuggestorInterface
 *
 * Contract for all AI-based suggestor/advisor classes in MAS.
 * Each AI suggestor provides prediction, recommendation, or text/content generation
 * for workflows, segmentation, campaign optimization, etc.
 *
 * @copyright Copyright (c) 2025, Your Company
 * @license   Proprietary
 */

namespace Opencart\Library\Mas\Interfaces;

interface AiSuggestorInterface
{
    /**
     * Returns the unique suggestor type string (e.g. 'openai', 'googleai', 'product_recommend', 'segment_suggestor').
     *
     * @return string
     */
    public static function getType(): string;

    /**
     * Returns a human-readable label for this suggestor.
     *
     * @return string
     */
    public static function getLabel(): string;

    /**
     * Returns a description of what the suggestor does (purpose/AI model/pipeline).
     *
     * @return string
     */
    public static function getDescription(): string;

    /**
     * Returns the configuration schema accepted by this suggestor (parameters, AI options).
     *
     * @return array
     */
    public static function getConfigSchema(): array;

    /**
     * Sets this suggestor's working configuration.
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void;

    /**
     * Gets the current configuration array.
     *
     * @return array
     */
    public function getConfig(): array;

    /**
     * Checks if this suggestor is enabled and can be used.
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * Returns all capabilities supported (e.g. ['text_generate', 'recommend_product', 'segment_prediction']).
     *
     * @return string[]
     */
    public static function getCapabilities(): array;

    /**
     * Accepts input/context array and produces an AI-powered suggestion.
     * The structure of context and returned value depends on suggestor type.
     *
     * @param array $context Contextual input (dataset, prompt, customer segment, workflow state, etc.)
     * @return array{
     *     success: bool,
     *     suggestion?: mixed,
     *     message?: string,
     *     meta?: array
     * }
     */
    public function suggest(array $context): array;

    /**
     * Serializes this suggestor (including config) to array for storage.
     *
     * @return array
     */
    public function toArray(): array;

    /**
     * Creates a suggestor instance from array data (for deserialization).
     *
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): self;
}
