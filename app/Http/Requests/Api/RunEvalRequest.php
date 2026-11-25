<?php

namespace App\Http\Requests\Api;

use App\Evals\EvalCase;
use App\Evals\EvalFile;
use App\Models\EvalRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class RunEvalRequest extends FormRequest
{
    /** @var list<EvalCase> */
    private array $parsedCases = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * Name of an eval file in the repository's evals/ folder. Used when cases is not sent.
             *
             * @example sample
             */
            'eval_set' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
            /** Eval cases, in the same format as an eval file. Up to 50. */
            'cases' => ['nullable', 'array', 'list', 'max:'.EvalRun::MAX_CASES],
            /** Limit retrieval to these ready documents. */
            'document_ids' => ['nullable', 'array', 'max:100'],
            'document_ids.*' => ['integer'],
            /**
             * Receives the results as a signed POST when the run finishes, such as an n8n Wait node's resume URL.
             *
             * @example https://n8n.example.com/webhook-waiting/123
             */
            'callback_url' => ['nullable', 'url:http,https', 'max:2048'],
            /** Seconds between chat calls, to stay inside free-tier rate limits. Defaults to the server setting. */
            'delay_seconds' => ['nullable', 'numeric', 'min:0', 'max:60'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $this->parsedCases = $this->parseCases();
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add($this->filled('cases') ? 'cases' : 'eval_set', $exception->getMessage());

                return;
            }

            if (count($this->parsedCases) > EvalRun::MAX_CASES) {
                $validator->errors()->add('eval_set', 'An eval run can have at most '.EvalRun::MAX_CASES.' cases.');
            }
        });
    }

    /**
     * @return list<EvalCase>
     */
    public function evalCases(): array
    {
        return $this->parsedCases;
    }

    /**
     * @return list<int>|null
     */
    public function documentIds(): ?array
    {
        $ids = $this->input('document_ids');

        return is_array($ids) && $ids !== [] ? array_values(array_unique(array_map('intval', $ids))) : null;
    }

    /**
     * @return list<EvalCase>
     */
    private function parseCases(): array
    {
        $cases = $this->input('cases');

        if (is_array($cases) && $cases !== []) {
            return EvalFile::fromArray(array_values($cases));
        }

        $set = $this->string('eval_set')->toString() ?: 'sample';

        return EvalFile::load(base_path('evals/'.$set.'.json'));
    }
}
