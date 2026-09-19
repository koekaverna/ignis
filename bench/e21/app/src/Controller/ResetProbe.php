<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ResetWitness;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Is a service still this request's after `handle()` has returned?
 *
 * `Kernel::handle()` arms `resetServices` and counts itself in `requestStackSize`; the **next**
 * `handle()` calls `boot()`, which resets every resettable service — but only while that counter is
 * zero. So a request still inside `handle()` is safe, and everything after it is not: a streamed body
 * is produced by the loop *after* `handle()` returned, with the counter back down.
 *
 * The producer remembers a value, parks, and reads it back. Coming back to `null` means another
 * request reset a service this one was using.
 */
final class ResetProbe
{
    public function __construct(private readonly ResetWitness $witness) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $tag = (string) $request->query->get('tag', '');
        $milliseconds = (int) $request->query->get('ms', 0);
        $witness = $this->witness;

        return new StreamedResponse(static function () use ($witness, $tag, $milliseconds): void {
            $witness->remember($tag);
            if ($milliseconds > 0) {
                \Ignis\sleep($milliseconds);      // parks: another request enters handle() meanwhile
            }
            $seen = $witness->recall();
            echo json_encode(['tag' => $tag, 'seen' => $seen, 'leaked' => $seen !== $tag], JSON_UNESCAPED_SLASHES);
        }, 200, ['content-type' => 'application/json']);
    }
}
