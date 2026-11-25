<?php

namespace App\Services\Discord;

/**
 * The slash commands DocChat registers for its Discord application.
 */
class DiscordCommands
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'ask',
                'description' => 'Ask a question about this server\'s documents',
                'type' => 1,
                'options' => [['type' => 3, 'name' => 'question', 'description' => 'Your question', 'required' => true, 'max_length' => 2000]],
            ],
            [
                'name' => 'link',
                'description' => 'Link this server to a DocChat workspace (needs Manage Server)',
                'type' => 1,
                'options' => [['type' => 3, 'name' => 'code', 'description' => 'The code from DocChat > Workspace > Chat apps', 'required' => true]],
            ],
            ['name' => 'new', 'description' => 'Start a new conversation in this channel', 'type' => 1],
            ['name' => 'unlink', 'description' => 'Unlink this server from DocChat (needs Manage Server)', 'type' => 1],
        ];
    }

    public static function apiBase(): string
    {
        return 'https://discord.com/api/v10';
    }
}
