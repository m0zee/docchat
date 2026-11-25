<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Evals\EvalFile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RunEvalRequest;
use App\Jobs\RunEvalJob;
use App\Models\Document;
use App\Models\EvalRun;
use App\Models\Webhook;
use App\Support\Api\ApiError;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Eval runs: measure retrieval and answers on a set of known questions.
 */
class EvalController extends Controller
{
    public function __construct(private CurrentWorkspace $current) {}

    /**
     * Start an eval run.
     *
     * Runs in the background, one case at a time with a pause between chat
     * calls. Poll GET /evals/{id}, or pass callback_url: the results are
     * POSTed there when the run finishes, signed with callback_secret the
     * same way as webhooks (X-DocChat-Signature). Workspace webhooks that
     * subscribe to eval.completed get the summary too.
     *
     * @status 202
     *
     * @response 202 array{data: array{id: int, status: 'queued', cases: int, completed_cases: int, summary: null, error: null, callback_secret?: string}}
     */
    public function store(RunEvalRequest $request): JsonResponse
    {
        $workspace = $this->current->require();
        $documentIds = $request->documentIds();
        $ready = Document::query()->inWorkspace($workspace)->where('status', DocumentStatus::Ready);

        if ($documentIds !== null) {
            $documentIds = (clone $ready)->whereIn('id', $documentIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();

            if ($documentIds === []) {
                throw ValidationException::withMessages(['document_ids' => 'None of these ids is a ready document in this workspace.']);
            }
        } elseif (! $ready->exists()) {
            return ApiError::response(422, 'no_ready_documents', 'This workspace has no ready documents to evaluate.');
        }

        $callbackUrl = $request->string('callback_url')->toString() ?: null;
        $secret = $callbackUrl === null ? null : Webhook::newSecret();

        $run = EvalRun::query()->create([
            'workspace_id' => $workspace->id,
            'api_key_id' => $this->current->apiKey()?->id,
            'cases' => array_map(EvalFile::toArray(...), $request->evalCases()),
            'document_ids' => $documentIds,
            'delay_seconds' => $request->filled('delay_seconds')
                ? (float) $request->input('delay_seconds')
                : (float) config('docchat.evals.delay_seconds'),
            'callback_url' => $callbackUrl,
            'callback_secret' => $secret,
        ]);

        RunEvalJob::dispatch($run);

        return new JsonResponse(['data' => array_filter([
            ...$run->refresh()->payload(withCases: false),
            'callback_secret' => $secret,
        ], fn (mixed $value, string $key): bool => $value !== null || $key !== 'callback_secret', ARRAY_FILTER_USE_BOTH)], 202);
    }

    /**
     * Get an eval run with its per-case results.
     */
    public function show(int $evalRun): JsonResponse
    {
        $run = EvalRun::query()->inWorkspace($this->current->require())->findOrFail($evalRun);

        return new JsonResponse(['data' => $run->payload()]);
    }
}
