<?php
/**
 * MAS - Marketing Automation Suite
 * NodeInterface
 *
 * Contract for all workflow node types (trigger, action, delay, condition, etc.) used in the MAS Workflow Engine.
 * Nodes define the structure of workflow steps and their runtime logic.
 */

namespace Opencart\Library\Mas\Interfaces;

interface NodeInterface
{
    /**
     * Returns the unique identifier of the node instance.
     *
     * @return string
     */
    public function getId(): string;

    /**
     * Returns the node type (e.g., 'trigger', 'action', 'delay', 'condition').
     *
     * @return string
     */
    public static function getType(): string;

    /**
     * Returns a short human-readable label for the node.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Returns a description of the node.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Returns the configuration array for this node.
     *
     * @return array
     */
    public function getConfig(): array;

    /**
     * Sets the configuration array for this node.
     *
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void;

    /**
     * Validates the node configuration.
     *
     * @return bool True if valid, otherwise false
     */
    public function validate(): bool;

    /**
     * Returns the array schema for the node’s configuration.
     *
     * @return array
     */
    public static function getConfigSchema(): array;

    /**
     * Executes the node logic.
     *
     * @param array $context Workflow context (payload, data, state, etc.)
     * @return array{
     *     success: bool,
     *     output?: array,
     *     error?: string
     * }
     */
    public function execute(array $context): array;

    /**
     * Serializes this node (including its config) to array (for JSON storage).
     *
     * @return array
     */
    public function toArray(): array;

    /**
     * Static factory to create node instance from array (for deserialization).
     *
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): self;
}
