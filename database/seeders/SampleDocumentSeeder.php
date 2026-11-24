<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Documents\DocumentService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the demo user and uploads the sample handbook through the normal
 * upload path, so ingestion runs on the queue. Not run by default:
 *   php artisan db:seed --class=SampleDocumentSeeder
 */
class SampleDocumentSeeder extends Seeder
{
    public const EMAIL = 'demo@docchat.test';

    public const PASSWORD = 'password';

    public const FILE = 'harbor-point-library-handbook.pdf';

    public function run(DocumentService $documents): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => self::EMAIL],
            ['name' => 'Demo User', 'password' => Hash::make(self::PASSWORD)],
        );

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        $workspace = $user->currentWorkspace() ?? Workspace::createFor($user, 'Demo workspace');

        if (Document::query()->inWorkspace($workspace)->where('original_filename', self::FILE)->exists()) {
            $this->command?->info('The sample document is already uploaded for '.self::EMAIL.'.');

            return;
        }

        $file = new UploadedFile(
            database_path('seeders/samples/'.self::FILE),
            self::FILE,
            'application/pdf',
            null,
            true,
        );

        $documents->createFromUpload($file, $workspace, $user);

        $this->command?->info('Uploaded the sample for '.self::EMAIL.' (password: '.self::PASSWORD.'). Ingestion runs on the queue.');
    }
}
