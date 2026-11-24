<?php

namespace App\Console\Commands;

use App\Services\Discord\DiscordCommands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DiscordRegisterCommands extends Command
{
    protected $signature = 'docchat:discord-register-commands {--guild= : Register in one server only (shows up at once, good for testing)}';

    protected $description = 'Register the /ask, /link, /new and /unlink slash commands with Discord';

    public function handle(): int
    {
        $applicationId = (string) config('docchat.channels.discord.application_id');
        $token = (string) config('docchat.channels.discord.bot_token');

        if ($applicationId === '' || $token === '') {
            $this->components->error('Set DISCORD_APPLICATION_ID and DISCORD_BOT_TOKEN in .env first.');

            return self::FAILURE;
        }

        $url = DiscordCommands::apiBase().'/applications/'.$applicationId
            .($this->option('guild') ? '/guilds/'.$this->option('guild') : '').'/commands';

        // PUT replaces the whole set, so running this again is safe.
        $response = Http::withHeaders(['Authorization' => 'Bot '.$token])->timeout(15)->put($url, DiscordCommands::definitions());

        if ($response->failed()) {
            $this->components->error('Discord answered HTTP '.$response->status().': '.$response->body());

            return self::FAILURE;
        }

        $this->components->info('Registered '.count($response->json()).' commands'.($this->option('guild') ? ' in server '.$this->option('guild') : ' globally (they can take a few minutes to appear)').'.');

        return self::SUCCESS;
    }
}
