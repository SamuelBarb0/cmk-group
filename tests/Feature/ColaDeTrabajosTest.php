<?php

namespace Tests\Feature;

use App\Jobs\GenerarPresentacionJob;
use App\Jobs\GenerateAiDocumentJob;
use App\Jobs\MapearImportacionJob;
use ReflectionClass;
use Tests\TestCase;

/**
 * Si un job dura más que el `retry_after` de la cola, el worker del minuto
 * siguiente lo da por abandonado y lo vuelve a correr mientras el primero
 * sigue: una presentación o un documento con IA se generaba dos veces.
 */
class ColaDeTrabajosTest extends TestCase
{
    public function test_ningun_job_dura_mas_que_el_retry_after_de_la_cola(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        foreach ([GenerarPresentacionJob::class, GenerateAiDocumentJob::class, MapearImportacionJob::class] as $job) {
            $timeout = (new ReflectionClass($job))->getProperty('timeout')->getDefaultValue();

            $this->assertLessThan($retryAfter, $timeout, "{$job} ({$timeout} s) no cabe en retry_after ({$retryAfter} s).");
        }
    }
}
