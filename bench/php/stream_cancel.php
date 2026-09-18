<?php

/**
 * R-STREAM-CANCEL: a streaming handler must learn that the client left.
 *
 * Until 2026-09-18 the cancel guard was disarmed the moment the oneshot resolved, and for a streamed
 * answer that is when the *headers* go out — so a hang-up during the body was never delivered as
 * Outcome::Cancelled. A fiber parked in write() still found out (the channel closes), but one parked
 * on a slow query between chunks did not, and kept producing for a client that was gone.
 *
 *   /slow    one chunk, then a long sleep between chunks — the window this is about
 *   /state   what the producer observed, as JSON
 */

declare(strict_types=1);

require __DIR__ . '/../../php/packages/runtime/src/ignis.php';

use Ignis\Http\Request;
use Ignis\Http\Response;
use Ignis\Http\StreamedResponse;

$state = ['cancelled' => 0, 'finally_ran' => 0, 'chunks_written' => 0, 'cancel_ms' => null];

Ignis\serve(static function (Request $request) use (&$state): Response {
    if ($request->path() === '/state') {
        return Response::json($state + ['loop_cancelled' => \Ignis\Loop::$cancelled]);
    }

    $state = ['cancelled' => 0, 'finally_ran' => 0, 'chunks_written' => 0, 'cancel_ms' => null];

    return new StreamedResponse(static function () use (&$state): void {
        $startedAt = hrtime(true);
        try {
            Ignis\write("chunk1\n");
            $state['chunks_written'] = 1;
            // The window: parked on a timer between chunks, not inside write(). Nothing here can
            // notice a closed channel by itself — the runtime has to deliver the cancellation.
            for ($i = 0; $i < 20; $i++) {
                Ignis\sleep(250);
                Ignis\write('chunk' . ($i + 2) . "\n");
                $state['chunks_written']++;
            }
        } catch (\Throwable $e) {
            $state['cancelled'] = 1;
            $state['cancel_ms'] = (int) ((hrtime(true) - $startedAt) / 1e6);
        } finally {
            $state['finally_ran'] = 1;
        }
    }, 200, ['content-type' => 'text/plain']);
}, getenv('IGNIS_LISTEN') ?: '127.0.0.1:8198');
