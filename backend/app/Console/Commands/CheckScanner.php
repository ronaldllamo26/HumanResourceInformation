<?php

namespace App\Console\Commands;

use App\Services\DocumentScanner;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

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

        // Only Gemini has a chain, and it is the whole reason this command
        // can now say *which* model answered rather than only whether one did.
        if ($driver === 'gemini' && $this->fallbacks() !== []) {
            $this->line('  Fallbacks '.implode(', ', $this->fallbacks()));
        }
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

            /*
             * Measured, not guessed.
             *
             * This used to print three plausible causes and leave somebody to
             * work out which — and on the outage that prompted the chain it
             * would have sent them hunting for a bad key, because the honest
             * answer ("both models are out of capacity") was not on the list.
             * A three-line probe per model tells them apart for certain: 400
             * and 401 are the request, 404 is the name, 503 is the pool.
             */
            if ($driver === 'gemini') {
                $this->line('  Asking each model directly, to tell a busy pool from a bad key:');
                $this->newLine();

                $kinds = [];

                foreach ($this->geminiChain() as $model) {
                    [$status, $reason, $kind] = $this->probeGemini($model);
                    $kinds[] = $kind;
                    $this->line(sprintf('    %-26s %-4s %s', $model, $status, $reason));
                }

                $this->newLine();

                /*
                 * The advice follows what came back rather than being printed
                 * over it. Saying "this is capacity, nothing to fix" under a
                 * column of 404s would be the old three-guess list again, just
                 * with a measurement above it that contradicts it.
                 */
                foreach ($this->adviceFor($kinds) as $line) {
                    $this->line('  '.$line);
                }
            } else {
                $this->line('  The reason is in storage/logs/laravel.log. Usually one of:');

                foreach ($this->likelyCauses($driver) as $cause) {
                    $this->line("    · {$cause}");
                }
            }

            $this->newLine();

            return self::FAILURE;
        }

        $answered = $scanner->modelUsed();

        if ($answered !== null && $answered !== $this->modelFor($driver)) {
            // The configured model is congested and a fallback carried the
            // scan. Worth saying out loud: the feature works, and the default
            // in `.env` is no longer the one doing the work.
            $this->warn("  Answered by the fallback {$answered} in {$elapsed}s.");
            $this->line('  The configured model '.$this->modelFor($driver).' did not answer.');
            $this->line('  Set GEMINI_MODEL='.$answered.' to stop paying for that detour on every scan.');
        } else {
            $this->info("  The driver answered in {$elapsed}s.");
        }
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

    /**
     * What to do about it, decided from what came back rather than listed.
     *
     * The old version of this command printed three plausible causes and left
     * somebody to pick — and on the one outage it existed for, the true cause
     * was not among them. So the advice follows the measurement: a single
     * sentence is only printed when every model agreed, and a mixed column
     * points at the rows instead of being wrong about one of them.
     *
     * @param  array<int, string>  $kinds  one per model, from probeGemini()
     * @return array<int, string>
     */
    private function adviceFor(array $kinds): array
    {
        $all = fn (string $kind) => $kinds !== [] && array_unique($kinds) === [$kind];

        return match (true) {
            $all('key') => [
                'Every model refused the key, so this is GEMINI_API_KEY and not the models.',
                'Issue a new one at https://aistudio.google.com/apikey, then run',
                'php artisan config:clear and try again.',
            ],
            $all('model') => [
                'None of these model names exists for this key, so nothing here can answer.',
                'GET '.config('scanner.gemini.endpoint').'/models lists what it may reach;',
                'put a name from that list in GEMINI_MODEL.',
            ],
            $all('quota') => [
                'The free tier is spent for today — 20 scans per model, and it resets on',
                "Google's clock rather than at local midnight. Until then HR types the",
                'fields, which is where every scanner failure lands.',
            ],
            $all('busy') => [
                'Busy, not misconfigured: the key and the request are both fine and there',
                'is nothing here to fix. Add a model that does answer to',
                'GEMINI_FALLBACK_MODELS, or wait for the pool to clear.',
            ],
            $all('request') => [
                'The request itself was rejected by every model, which points at this',
                'code rather than at the configuration — the schema field and the body',
                'shape are what changed last. storage/logs/laravel.log has the reason.',
            ],
            $all('network') => [
                'Nothing was reachable at all, so this is the network before it is',
                'anything else: a firewall or proxy between here and',
                'generativelanguage.googleapis.com.',
            ],
            default => [
                'The rows above differ, so read them one by one: a busy pool clears on',
                'its own, a missing model needs a real name, and a refused key needs a',
                'new one. A model that answers belongs in GEMINI_MODEL.',
            ],
        };
    }

    /** @return array<int, string> */
    private function fallbacks(): array
    {
        return array_values(array_filter((array) config('scanner.gemini.fallback_models', [])));
    }

    /**
     * The same order the driver walks, de-duplicated the same way.
     *
     * @return array<int, string>
     */
    private function geminiChain(): array
    {
        return array_values(array_unique(array_filter([
            (string) config('scanner.gemini.model'),
            ...$this->fallbacks(),
        ])));
    }

    /**
     * One cheap text request per model, purely to read the answer back.
     *
     * Deliberately not the real scan: no image, no schema, one word of input,
     * no retry. The question is only whether this model answers *at all*, and
     * a failing scan has already established that the real request does not.
     *
     * @return array{0: string, 1: string, 2: string} status, reason, kind
     */
    private function probeGemini(string $model): array
    {
        $base = rtrim((string) config('scanner.gemini.endpoint'), '/');

        try {
            $response = Http::timeout(30)
                ->withHeaders(['x-goog-api-key' => config('scanner.gemini.api_key')])
                ->post("{$base}/models/{$model}:generateContent", [
                    'contents' => [['role' => 'user', 'parts' => [['text' => 'ok']]]],
                    'generationConfig' => ['maxOutputTokens' => 8, 'thinkingConfig' => ['thinkingBudget' => 0]],
                ]);
        } catch (\Throwable $exception) {
            return ['—  ', 'could not be reached: '.$exception->getMessage(), 'network'];
        }

        $status = $response->status();

        /*
         * **A bad key is a 400 here, not a 401**, and reading the status alone
         * would name the wrong culprit — which is the mistake this whole
         * command is being fixed for. Gemini validates the body before the
         * key and answers `400 INVALID_ARGUMENT` with `API_KEY_INVALID` in
         * `error.details[].reason`, the same conflation that once made a wrong
         * schema and a wrong key indistinguishable from outside. So the reason
         * codes are read, and only then the status.
         */
        $reasons = array_column((array) $response->json('error.details', []), 'reason');

        [$reason, $kind] = match (true) {
            $response->successful() => ['answers — this one is available', 'ok'],
            in_array('API_KEY_INVALID', $reasons, true) => ['the API key is not valid', 'key'],
            in_array('SERVICE_DISABLED', $reasons, true) => ['the Generative Language API is not enabled for this project', 'key'],
            $status === 401, $status === 403 => ['the key is wrong, revoked, or not entitled to this model', 'key'],
            $status === 404 => ['no such model for this key', 'model'],
            $status === 429 => ['the free-tier daily quota is spent (20 scans per model)', 'quota'],
            $status >= 500 => ['out of capacity — busy, not misconfigured', 'busy'],
            $status === 400 => [
                // Google's own sentence, which is more specific than anything
                // that can be inferred from the number on its own.
                (string) $response->json('error.message', 'the request was rejected'),
                'request',
            ],
            default => [(string) $response->json('error.status', 'unexpected'), 'request'],
        };

        return [(string) $status, $reason, $kind];
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
