<?php

namespace App\Console\Commands;

use App\Services\DocumentScanner;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * Asks the configured scanner driver whether it is actually there.
 *
 * `DocumentScanner::isEnabled()` deliberately asks config rather than the
 * network — pinging on every page render would put an HTTP call in front of
 * every screen and tie the test suite to whatever happens to be running. The
 * cost of that choice is a real failure mode: a key that is present but wrong,
 * revoked, or out of quota draws the Scan button and then fails silently on
 * every upload. From the form, a bad key, an exhausted free tier, and a model
 * that cannot read an image all look identical.
 *
 * This is the other half of that bargain. It is not run automatically and it
 * does not gate anything; it exists so somebody can *ask*, once, after a
 * deployment or an env change, and get a straight answer instead of finding
 * out from an HR user whose form came back blank.
 *
 * It sends a real image through the real driver, because the failures worth
 * catching — a wrong endpoint, a revoked key, a model that was never pulled —
 * all live past the point where a config check would have stopped.
 */
class CheckScanner extends Command
{
    protected $signature = 'scanner:check {--file= : An image to send instead of the generated one}';

    protected $description = 'Send one image through the configured scanner driver and report what came back';

    public function handle(DocumentScanner $scanner): int
    {
        $driver = (string) config('scanner.driver');

        $this->newLine();
        $this->line("  Driver    <options=bold>{$driver}</>");
        $this->line('  Model     '.$this->modelFor($driver));
        $this->line('  Endpoint  '.$this->endpointFor($driver));
        $this->newLine();

        /*
         * The config gate first. If this is false the feature is *deliberately*
         * off — the button is not drawn and the endpoint 404s — which is a
         * different answer from "configured but broken", and saying so keeps
         * somebody from hunting for a fault that is not there.
         */
        if (! $scanner->isEnabled()) {
            $this->warn('  The scanner is switched off: '.$this->whyDisabled($driver));
            $this->line('  Uploading documents by hand works exactly as before.');
            $this->newLine();

            return self::SUCCESS;
        }

        $file = $this->option('file')
            ? new UploadedFile($this->option('file'), basename($this->option('file')), null, null, true)
            : $this->generateProbe();

        if (! $file) {
            $this->error('  Could not read that file.');

            return self::FAILURE;
        }

        $this->line('  Sending one image…');

        $started = microtime(true);
        $reading = $scanner->scan($file);
        $elapsed = round(microtime(true) - $started, 1);

        $this->newLine();

        /*
         * A null reading is what every driver failure collapses to — read()
         * logs and returns null so a scan never takes a form down with it. On
         * this screen that is the answer being looked for, so it is reported
         * as a failure rather than passed over.
         */
        if ($reading === null) {
            $this->error("  No answer after {$elapsed}s.");
            $this->newLine();
            $this->line('  The reason is in storage/logs/laravel.log. Usually one of:');

            foreach ($this->likelyCauses($driver) as $cause) {
                $this->line("    · {$cause}");
            }

            $this->newLine();

            return self::FAILURE;
        }

        $this->info("  The driver answered in {$elapsed}s.");
        $this->newLine();
        $this->line('  It read a generated test card, so the *values* mean nothing —');
        $this->line('  what this proves is that the request, the key, and the model all work.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * A small image with text on it.
     *
     * Generated rather than committed: a fixture would be a binary in the
     * repository that exists only for this command, and anything a vision
     * model can read at all is enough to prove the pipe is open.
     */
    private function generateProbe(): ?UploadedFile
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->warn('  GD is not available — pass --file with an image instead.');

            return null;
        }

        $image = imagecreatetruecolor(600, 320);
        imagefilledrectangle($image, 0, 0, 600, 320, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 0, 0, 0);

        imagestring($image, 5, 30, 40, 'REPUBLIC OF THE PHILIPPINES', $ink);
        imagestring($image, 5, 30, 80, 'SCANNER CONNECTIVITY TEST', $ink);
        imagestring($image, 4, 30, 140, 'No. T01-99-000001', $ink);
        imagestring($image, 4, 30, 180, 'Issued  2026/01/01', $ink);
        imagestring($image, 4, 30, 220, 'Expires 2030/01/01', $ink);

        $path = tempnam(sys_get_temp_dir(), 'scan').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        // Deleted by the OS on reboot at worst; the command is short-lived.
        File::ensureDirectoryExists(dirname($path));

        return new UploadedFile($path, 'scanner-check.png', 'image/png', null, true);
    }

    private function modelFor(string $driver): string
    {
        return match ($driver) {
            'gemini' => (string) config('scanner.gemini.model'),
            'openrouter' => (string) config('scanner.openrouter.model'),
            'anthropic' => (string) config('scanner.model'),
            default => '—',
        };
    }

    private function endpointFor(string $driver): string
    {
        return match ($driver) {
            'gemini' => (string) config('scanner.gemini.endpoint'),
            'openrouter' => (string) config('scanner.openrouter.endpoint'),
            'anthropic' => 'api.anthropic.com',
            default => '—',
        };
    }

    private function whyDisabled(string $driver): string
    {
        return match ($driver) {
            'gemini' => 'GEMINI_API_KEY is empty.',
            'openrouter' => 'OPENROUTER_API_KEY is empty.',
            'anthropic' => 'ANTHROPIC_API_KEY is empty.',
            default => "SCANNER_DRIVER is \"{$driver}\", which is not one of gemini, openrouter, or anthropic.",
        };
    }

    /** @return array<int, string> */
    private function likelyCauses(string $driver): array
    {
        return match ($driver) {
            'gemini' => [
                'The API key is wrong, revoked, or has no quota left.',
                'The model name is not one this key can reach: '.config('scanner.gemini.model').'.',
                'The server cannot reach generativelanguage.googleapis.com.',
            ],
            /*
             * The model is the likely culprit here, not the key — which is
             * the opposite of every other driver, and is why it is listed
             * first. OpenRouter's catalogue is large and only some of it can
             * take an image or honour a JSON schema; a model that can do
             * neither answers prose, and prose reaches the form as a scan
             * that simply found nothing.
             */
            'openrouter' => [
                'The model cannot read images: '.config('scanner.openrouter.model').'.',
                'The model ignores response_format, so it answered prose rather than JSON.',
                'The API key is wrong, or the account has no credit.',
                '`provider.data_collection: deny` may leave no upstream able to serve this model.',
            ],
            'anthropic' => [
                'The API key is wrong or has no credit.',
                'The server cannot reach api.anthropic.com.',
            ],
            default => ['SCANNER_DRIVER is not a driver this system knows.'],
        };
    }
}
