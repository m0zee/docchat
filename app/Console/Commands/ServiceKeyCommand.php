<?php

namespace App\Console\Commands;

use App\Enums\ApiAbility;
use App\Enums\ApiKeyType;
use App\Models\ApiKey;
use Illuminate\Console\Command;

/**
 * Platform keys for shared bots. They belong to no workspace: they link
 * chats, then act in the linked chat's workspace (X-DocChat-Link header).
 */
class ServiceKeyCommand extends Command
{
    protected $signature = 'docchat:service-key
        {name? : A name for the key, for example "telegram bot"}
        {--list : List service keys}
        {--revoke= : Revoke the service key with this id}';

    protected $description = 'Create, list or revoke platform service keys for the shared chat bots';

    public function handle(): int
    {
        if ($this->option('list')) {
            $this->table(['Id', 'Name', 'Prefix', 'Last used', 'Revoked'], ApiKey::query()
                ->where('type', ApiKeyType::Service->value)->orderBy('id')->get()
                ->map(fn (ApiKey $key): array => [$key->id, $key->name, $key->prefix.'...', $key->last_used_at?->diffForHumans() ?? '-', $key->revoked_at?->toDateString() ?? '-'])
                ->all());

            return self::SUCCESS;
        }

        if ($this->option('revoke') !== null) {
            $key = ApiKey::query()->where('type', ApiKeyType::Service->value)->find((int) $this->option('revoke'));

            if ($key === null) {
                $this->components->error('No service key with that id.');

                return self::FAILURE;
            }

            $key->revoke();
            $this->components->info('Revoked '.$key->name.'.');

            return self::SUCCESS;
        }

        [, $plain] = ApiKey::issue(null, (string) ($this->argument('name') ?? 'service key'), ApiAbility::forServiceKeys(), ApiKeyType::Service);

        $this->components->info('Service key created. It is shown only now; put it in an n8n Header Auth credential.');
        $this->line($plain);

        return self::SUCCESS;
    }
}
