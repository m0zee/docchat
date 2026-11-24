<?php

namespace App\Http\Requests\Api;

use App\Enums\ApiKeyType;
use App\Enums\ConversationChannel;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\ApiKey;
use App\Services\Formatting\MessageFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AskRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * The question, up to 2,000 characters.
             *
             * @example How long can I keep a DVD?
             */
            'question' => ['required', 'string', 'max:2000'],
            /** Search only these documents. Default: every ready document in the workspace. */
            'document_ids' => ['nullable', 'array', 'max:50'],
            'document_ids.*' => ['integer'],
            /** Which channel asks, for usage reports. Default "api". */
            'channel' => ['nullable', Rule::enum(ConversationChannel::class)->only(self::channels())],
            /**
             * Your id for the chat. The same id continues the same conversation, with follow-up context.
             *
             * @example telegram:12345
             */
            'external_conversation_id' => ['nullable', 'string', 'max:255'],
            /**
             * Adds a "formatted" array of ready-to-send message parts.
             *
             * @example telegram_html
             */
            'format' => ['nullable', Rule::enum(MessageFormat::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Website visitors (public keys) can only use widget conversations,
            // named by a random visitor id the widget keeps in the browser.
            if ($this->fromPublicKey() && ! preg_match('/^[A-Za-z0-9_-]{16,64}$/', $this->string('external_conversation_id')->toString())) {
                $validator->errors()->add('external_conversation_id', 'Widget requests need a random conversation id of 16 to 64 letters, digits, _ or -.');
            }
        });
    }

    public function fromPublicKey(): bool
    {
        $key = $this->attributes->get(AuthenticateApiKey::ATTRIBUTE);

        return $key instanceof ApiKey && $key->type === ApiKeyType::Public;
    }

    /**
     * @return list<ConversationChannel>
     */
    public static function channels(): array
    {
        return [
            ConversationChannel::Api,
            ConversationChannel::Widget,
            ConversationChannel::Telegram,
            ConversationChannel::WhatsApp,
            ConversationChannel::Discord,
        ];
    }

    public function channel(): ConversationChannel
    {
        if ($this->fromPublicKey()) {
            return ConversationChannel::Widget;
        }

        return $this->enum('channel', ConversationChannel::class) ?? ConversationChannel::Api;
    }

    public function messageFormat(): ?MessageFormat
    {
        return $this->enum('format', MessageFormat::class);
    }

    /**
     * @return list<int>|null
     */
    public function documentIds(): ?array
    {
        if ($this->fromPublicKey()) {
            return null;
        }

        $ids = $this->input('document_ids');

        return is_array($ids) && $ids !== [] ? array_values(array_unique(array_map('intval', $ids))) : null;
    }
}
