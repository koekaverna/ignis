<?php

/**
 * The one thing this transport needs from its host, and the only file that cannot be upstreamed.
 *
 * Everything else in this directory translates between temporal sdk-core (Rust) and the command
 * model of temporalio/sdk-php. That translation is host-agnostic on purpose: give it a source of
 * activation JSON and it works — over Ignis today, over a PHP extension or sdk-core's C bridge
 * tomorrow, or over a file of recorded activations in a test.
 */

declare(strict_types=1);

namespace Temporal\Worker\Transport\Core;

interface ActivationSource
{
    public const WORKFLOW = 'workflow';
    public const ACTIVITY = 'activity';

    /**
     * Blocks until the next task of that kind. `null` means the worker is shutting down and the
     * sdk-php loop should end.
     *
     * @param self::WORKFLOW|self::ACTIVITY $kind
     * @return string|null JSON: a sdk-core `WorkflowActivation` or `ActivityTask`
     */
    public function poll(string $kind): ?string;

    /**
     * @param self::WORKFLOW|self::ACTIVITY $kind
     * @param string $json a workflow completion (`{"run_id":…,"commands":[…]}`) or an activity
     *        completion (`{"task_token":…,"result":…}`)
     */
    public function complete(string $kind, string $json): void;

    public function taskQueue(): string;

    public function namespace(): string;
}
