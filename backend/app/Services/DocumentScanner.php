<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Module 1 — reads a scanned 201-file document and proposes the fields.
 *
 * The scanner **never writes to the database.** It returns a suggestion; the
 * upload form shows it, HR corrects anything wrong, and the existing
 * StoreEmployeeDocumentRequest validates it on save exactly as it does a
 * hand-typed entry. Same shape as the rest of the system: PayrollReadiness
 * warns without blocking, salary bands flag without enforcing.
 *
 * Why it matters: `CredentialExpiryScanner` is only as good as the
 * `expires_at` someone typed. A licence keyed a year late is a driver the
 * system believes is legal to dispatch.
 */
class DocumentScanner
{
    /**
     * How much of the name printed on a document must belong to the employee
     * before it counts as theirs.
     *
     * Set above half deliberately. At exactly half, a document sharing only a
     * given name — "Antonio Santos" on Antonio Reyes's file — scored 1 of 2
     * and passed, and given names repeat constantly. A truncated "JOHN GAVE"
     * still scores 2 of 2, so the honest case is unaffected.
     */
    /**
     * Where a document's type was decided, strongest first.
     *
     * Exposed to the form, which refuses a contradicting upload only on the
     * first two — see resolveType() for why the other three are worth saying
     * but not worth refusing over.
     */
    public const TYPE_FROM_STORED_NUMBER = 'stored_number';

    public const TYPE_FROM_HEADING = 'heading';

    /**
     * A heading HR has filed the same way more than once — see
     * `ScannerCorrectionMemory`. Ranked below the curated keyword list and
     * above every machine-derived signal, because a correction has a person
     * behind it and a number's shape does not.
     */
    public const TYPE_FROM_LEARNED = 'learned';

    public const TYPE_FROM_NUMBER_FORMAT = 'number_format';

    public const TYPE_FROM_VALIDITY = 'validity';

    public const TYPE_FROM_MODEL = 'model';

    /**
     * Kept low deliberately, to stop a runaway response costing real money on
     * a blurry photo.
     *
     * **It said here that a cap this size "cannot truncate" the answer, and
     * that was the bug.** On a thinking model the budget is not spent on the
     * answer alone: Gemini's `thoughtsTokenCount` is charged against
     * `maxOutputTokens` too, and a real 201-file photograph is exactly the
     * input it thinks hardest about. Measured on one synthetic card, thinking
     * took 458–574 tokens of the 1024 — and the failure it produces is the
     * worst-shaped one available, because the JSON is cut mid-string:
     *
     *     {"document_type": "DIGITAL TIN ID",
     *
     * which `json_decode` refuses, so a document the model read *correctly*
     * reaches the form as "nothing found". That is why `thinkingConfig` is
     * sent below, and why a truncation is now logged rather than counted as
     * an empty answer.
     */
    private const MAX_TOKENS = 1024;

    private const NAME_MATCH_THRESHOLD = 0.6;

    /** Which model answered the last scan — see `modelUsed()`. */
    private ?string $answeredWith = null;

    public function __construct(private readonly ?Client $client = null) {}

    /**
     * False when the configured driver has nothing to call — the feature
     * stays dark rather than offering a button that cannot work.
     *
     * Deliberately a config question, not a live one. Calling the provider
     * here would answer "does this key work *right now*", which is a better
     * button but puts an HTTP request in every page render and makes the test
     * suite depend on a third party being up. A revoked key or an exhausted
     * quota is handled where every other failure is: read() logs it and
     * returns null, the form stays empty, and HR types the fields.
     *
     * `php artisan scanner:check` is the other half of that bargain — one
     * real image through the real driver, run deliberately.
     */
    public function isEnabled(): bool
    {
        return match (config('scanner.driver')) {
            'gemini' => filled(config('scanner.gemini.api_key')),
            'openrouter' => filled(config('scanner.openrouter.api_key')),
            'anthropic' => filled(config('scanner.api_key')),
            default => false,
        };
    }

    /** Whether this particular upload is worth spending a request on. */
    public function canScan(UploadedFile $file): bool
    {
        return $this->isEnabled()
            && in_array($file->getMimeType(), config('scanner.accepts', []), true)
            && $file->getSize() <= (int) config('scanner.max_bytes');
    }

    /**
     * @return array{
     *     type: string|null,
     *     title: string|null,
     *     document_number: string|null,
     *     issued_at: string|null,
     *     expires_at: string|null,
     *     name_on_document: string|null,
     *     name_matches: bool|null,
     *     confidence: string|null,
     *     note: string|null,
     * }|null  null when the scan could not run at all
     */
    public function scan(UploadedFile $file, ?Employee $employee = null): ?array
    {
        if (! $this->canScan($file)) {
            return null;
        }

        $raw = $this->read($file);

        return $raw === null ? null : $this->normalise($raw, $employee, $file);
    }

    /**
     * Reads a filled-in 201 form or job application and proposes the employee
     * record it describes.
     *
     * The other half of digitising a workforce. A spreadsheet imports in bulk;
     * a filing cabinet does not, and keying a personal-data sheet by hand is
     * where an agency's backlog actually sits.
     *
     * Same guarantees as scan(): nothing is written, every value is validated
     * in PHP against the same enums StoreEmployeeRequest uses, and a null is a
     * correct answer that leaves the field alone rather than clearing it.
     *
     * @return array<string, mixed>|null null when the scanner is off, the file
     *                                   is not a readable image, or the call failed
     */
    public function scanEmployeeForm(UploadedFile $file): ?array
    {
        if (! $this->canScan($file)) {
            return null;
        }

        $raw = $this->readForm($file);

        return $raw === null ? null : $this->normaliseForm($raw);
    }

    /**
     * Null when there is nothing to compare. Matches on surname plus first
     * name rather than the whole string — Philippine documents order names
     * inconsistently ("DOE, JANE" vs "Jane Doe") and carry middle names the
     * 201 file may not.
     */
    /**
     * Whether a name printed on a document is this employee.
     *
     * Public because the batch filer asks the same question in reverse — not
     * "is this Anastasia's licence" but "whose licence is this" — and a
     * second implementation of the token and Levenshtein rules would be two
     * places for the married-name and truncated-card cases to be handled
     * differently. One reading of a name, used both ways.
     */
    public function nameMatches(mixed $onDocument, ?Employee $employee): ?bool
    {
        if (! is_string($onDocument) || ! $employee) {
            return null;
        }

        $printed = $this->nameTokens($onDocument);

        if ($printed === []) {
            return null;
        }

        $expected = $this->nameTokens(
            implode(' ', array_filter([
                $employee->first_name,
                $employee->middle_name,
                $employee->last_name,
            ])),
        );

        if ($expected === []) {
            return null;
        }

        /*
         * Scored against what the *document* shows, not against the full
         * record.
         *
         * The first version demanded that both the first and the last name
         * appear verbatim, and it refused a real ID on its first outing: a
         * licence printed "JOHN GAVE" without the surname, the model read it
         * as "JONN GAVE", and both halves of the test failed at once — one to
         * a missing word, one to a single misread letter.
         *
         * Neither is unusual. Philippine IDs truncate long names, print the
         * surname on its own line the model may miss, and use maiden names;
         * OCR confuses H with N, O with D, and I with 1. Requiring an exact
         * double match makes every one of those a refusal.
         *
         * So: how much of what is printed is accounted for by this employee's
         * name. A document that shares nothing with them — "Marie Jumio" on
         * Anastasia Zemlak's file — scores zero and is still caught, which is
         * the case this check exists for.
         */
        $matched = 0;

        foreach ($printed as $token) {
            foreach ($expected as $candidate) {
                if ($this->tokensAlike($token, $candidate)) {
                    $matched++;
                    break;
                }
            }
        }

        if (($matched / count($printed)) >= self::NAME_MATCH_THRESHOLD) {
            return true;
        }

        /*
         * Second chance: the employee's whole name is in there, with extra.
         *
         * The ratio above is scored against the document, so a longer printed
         * name drags it down even when every word of the employee's own name
         * is present — a married "Maria Santos dela Cruz" on Maria Santos's
         * file scores 2 of 4 and would be refused. Turning the test around
         * catches that without loosening the first: if nothing of the
         * employee's name is missing, the document is about them.
         */
        foreach ($expected as $candidate) {
            $found = false;

            foreach ($printed as $token) {
                if ($this->tokensAlike($token, $candidate)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * The model that actually answered the last scan.
     *
     * Read by the controller when it writes `document_scans.model`. With a
     * fallback chain the configured model and the answering model are no
     * longer the same thing, and Scanner Accuracy compares readings *by
     * model* — recording the one in config while another did the work would
     * put the blame for a bad reading on a model that never saw the document.
     */
    public function modelUsed(): ?string
    {
        return $this->answeredWith;
    }

    /**
     * The one method that talks to the API, kept alone and `protected` so the
     * rules around it — type validation, date parsing, the name check — can
     * be tested without a network call or an API key. The SDK's
     * MessagesService is `final`, so there is nothing to mock otherwise.
     *
     * @return array<string, mixed>|null null when the call failed
     */
    protected function read(UploadedFile $file): ?array
    {
        return $this->ask($file, $this->systemPrompt(), $this->schema());
    }

    /** Split out for the same reason read() is: so tests can stub one call. */
    protected function readForm(UploadedFile $file): ?array
    {
        return $this->ask($file, $this->formPrompt(), $this->formSchema());
    }

    /**
     * Reads a PSA certificate for the fields it really carries.
     *
     * Kept deliberately short. The measured lesson from the main schema is
     * that a 0.9B model has a budget and spending it on a ninth field costs
     * the other eight, so this asks only for what the 201 file can use: who
     * the certificate is about, when they were born, and — the important part
     * — **who the parents are**.
     *
     * The parents matter because they are the check. A birth certificate in
     * an employee's 201 file is almost always their child's, so comparing the
     * name on the document against the employee can only ever fail. Comparing
     * the *parents* against the employee is the question actually worth
     * asking: is this certificate one this person has a claim to file?
     *
     * @return array<string, mixed>|null
     */
    protected function readCivilRegistry(?UploadedFile $file): ?array
    {
        if ($file === null) {
            return null;
        }

        return $this->ask($file, $this->registryPrompt(), $this->registrySchema());
    }

    /**
     * Cleans what the registry pass returned.
     *
     * Split from the call for the same reason normalise() is split from
     * read(): the rules — the name joining, the parent collision — are the
     * part worth testing, and they should not need a model to run.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normaliseRegistry(array $raw): array
    {
        $child = $this->joinName(
            $raw['child_first_name'] ?? null,
            $raw['child_middle_name'] ?? null,
            $raw['child_last_name'] ?? null,
        );

        $mother = $this->joinName(
            $raw['mother_first_name'] ?? null,
            $raw['mother_middle_name'] ?? null,
            $raw['mother_maiden_last_name'] ?? null,
        );

        $father = $this->joinName(
            $raw['father_first_name'] ?? null,
            $raw['father_middle_name'] ?? null,
            $raw['father_last_name'] ?? null,
        );

        /*
         * Three people are named on this page, and the model mixes them up.
         *
         * Measured: on a certificate where the father is "Leopoldo Jr. Arong
         * Pikit Pikit" and the mother "Adelia Formento Bontigao", it returned
         * the mother as "Leopoldo Jr. Arong Bontigao" — her surname, read from
         * the right box, with his given names bled in from item 13.
         *
         * Two parents do not share given names, so a collision means one of
         * the two boxes was misread. Which one cannot be told from the values
         * alone, so neither is trusted: the names are still shown, and the
         * claim check below is skipped rather than answered wrongly. Declining
         * is the same choice the validity ranges make where real documents
         * overlap.
         */
        $uncertain = $this->givenNamesCollide($mother, $father);

        return [
            'registry_no' => $this->text($raw['registry_no'] ?? null, 32),
            'child' => $child,
            'sex' => $this->oneOf($raw['child_sex'] ?? null, ['male', 'female']),
            'birth_date' => $this->date($raw['child_birth_date'] ?? null),
            'birth_place' => $this->text($raw['child_birth_place'] ?? null, 160),
            'mother' => $mother,
            'father' => $father,
            'parents_uncertain' => $uncertain,
        ];
    }

    /**
     * Whether the two parent names share their given names.
     *
     * Compared on the **first two tokens** — the first and middle names.
     * Everything-but-the-last was tried first and missed the case it was
     * written for: the father's surname on the measured certificate is two
     * words ("Pikit Pikit") and the mother's is one, so the tails differed and
     * the collision went unseen even though the given names were identical.
     *
     * Two parents sharing both a first *and* a middle name does not happen;
     * the model copying one box into the other does. A shared surname alone is
     * ordinary and is deliberately not enough to fire this.
     */
    private function givenNamesCollide(?string $mother, ?string $father): bool
    {
        if ($mother === null || $father === null) {
            return false;
        }

        $given = fn (string $name): string => implode(' ', array_slice($this->nameTokens($name), 0, 2));

        $a = $given($mother);
        $b = $given($father);

        // One token on either side is not enough to call a collision: a lone
        // shared first name is a coincidence people really have.
        return $a !== '' && $a === $b && count($this->nameTokens($mother)) > 1;
    }

    /**
     * Sends one image (or PDF) to whichever driver is configured.
     *
     * The prompt and the schema are arguments rather than fixed, because the
     * scanner now reads two different things: an ID card, and a filled-in 201
     * form. Everything that differs between those two is in the prompt and the
     * schema; everything that differs between the three *drivers* is in here.
     * Copying the driver plumbing to add the second reading would have meant
     * three more places for the Gemini envelope to be wrong in — and it was
     * already wrong in the one place it existed.
     *
     * **PDF handling:** Gemini accepts `application/pdf` natively, so a PDF
     * goes straight through. The other two drivers are image-only, so a PDF
     * is rendered to a JPEG first — see `pdfToImage()`.
     *
     * read() keeps its own signature so the tests that stub it still can.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null null when the call failed
     */
    private function ask(UploadedFile $file, string $prompt, array $schema): ?array
    {
        $driver = config('scanner.driver');

        // Gemini accepts PDFs natively — no conversion needed.
        if ($driver === 'gemini') {
            return $this->readWithGemini($file, $prompt, $schema);
        }

        // The other two drivers are image-only. A PDF must become a JPEG
        // before it can be sent, and a conversion failure degrades to
        // "the scan found nothing" rather than blocking the upload.
        $effective = $this->isPdf($file) ? $this->pdfToImage($file) : $file;

        if ($effective === null) {
            return null;
        }

        return match ($driver) {
            'openrouter' => $this->readWithOpenRouter($effective, $prompt, $schema),
            default => $this->readWithAnthropic($effective, $prompt, $schema),
        };
    }

    /** Whether this upload is a PDF rather than an image. */
    private function isPdf(UploadedFile $file): bool
    {
        return $file->getMimeType() === 'application/pdf';
    }

    /**
     * Renders the first page of a PDF to a temporary JPEG.
     *
     * Uses Imagick, which is the same extension Laravel's image manipulation
     * already depends on. The conversion is deliberately low-ceremony: one
     * page, 200 DPI (enough for a government ID's text), JPEG quality 90.
     * A multi-page employment contract carries its fields on the first page,
     * so the rest is not read.
     *
     * Returns null when Imagick is not installed or the PDF cannot be read —
     * neither is an error the user should see; it degrades to "no scan".
     */
    private function pdfToImage(UploadedFile $file): ?UploadedFile
    {
        if (! extension_loaded('imagick')) {
            Log::warning('PDF scan skipped: Imagick extension not available');

            return null;
        }

        try {
            $imagick = new \Imagick;
            $imagick->setResolution(200, 200);
            // Read only the first page (index 0).
            $imagick->readImage($file->getRealPath().'[0]');
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(90);

            $tempPath = tempnam(sys_get_temp_dir(), 'scan_pdf_').'_converted.jpg';
            $imagick->writeImage($tempPath);
            $imagick->clear();
            $imagick->destroy();

            return new UploadedFile(
                $tempPath,
                pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.jpg',
                'image/jpeg',
                null,
                true, // test mode — skip is_uploaded_file check
            );
        } catch (\Throwable $e) {
            Log::warning('PDF to image conversion failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Reads the document through Google's hosted model — the default.
     *
     * Free at the tier this project runs on, and reachable from anywhere,
     * which is what a deployed instance needs. There was a local driver once
     * and it was the default; it went because it could not run on the server,
     * which made it a privacy control that was switched off in the only place
     * it would have mattered.
     *
     * So the trade-off it used to avoid is now simply the situation: a
     * 201-file scan is a photograph of somebody's PhilSys ID, and sending it
     * here is a cross-border transfer of personal data under RA 10173. That
     * obligation is met with disclosure and consent on the employee's side,
     * not with a config value — there is no driver left that sidesteps it.
     *
     * Of the three, this is the one to prefer: a single named processor.
     * OpenRouter is a broker, so the same image reaches two.
     *
     * @return array<string, mixed>|null null when the call failed
     */
    /**
     * Gemini names the model in the URL, not in the body.
     *
     * `scanner.gemini.endpoint` is therefore the API *base*, and this appends
     * the rest. Keeping the base configurable is what lets a deployment point
     * at a different API version without a code change; keeping the
     * `:generateContent` suffix here is what stops someone configuring a URL
     * that is syntactically fine and semantically not this API — which is
     * exactly the mistake this method was written to fix.
     */
    private function geminiEndpoint(string $model): string
    {
        $base = rtrim((string) config('scanner.gemini.endpoint'), '/');

        return "{$base}/models/{$model}:generateContent";
    }

    /**
     * The models to try, in order: the configured one, then the fallbacks.
     *
     * De-duplicated, because a fallback list that repeats the primary is a
     * list that spends two slots on the same congested pool.
     *
     * @return array<int, string>
     */
    private function geminiModels(): array
    {
        return array_values(array_unique(array_filter([
            (string) config('scanner.gemini.model'),
            ...(array) config('scanner.gemini.fallback_models', []),
        ])));
    }

    /**
     * Asks Gemini, moving down the model list while the answer is "busy".
     *
     * The 503 that took the scanner down was not a misconfiguration: the key
     * was valid and the model existed and took images — it was simply out of
     * capacity, and every retry heard the same thing. A different model is a
     * different pool, so the chain is the recovery and the per-model retry is
     * only there for the momentary case.
     */
    private function readWithGemini(UploadedFile $file, string $prompt, array $schema): ?array
    {
        $this->answeredWith = null;
        $models = $this->geminiModels();

        foreach ($models as $index => $model) {
            $attempt = $this->askGemini($model, $file, $prompt, $schema);

            if ($attempt['json'] !== null) {
                $this->answeredWith = $model;

                // Worth a line in the log: a fallback answering means the
                // configured model is congested, which is the thing somebody
                // would otherwise only learn from a dead Scan button.
                if ($index > 0) {
                    Log::info('Document scan answered by a fallback model', [
                        'configured' => $models[0],
                        'answered' => $model,
                    ]);
                }

                return $attempt['json'];
            }

            // A rejected schema or a bad key is an answer. Asking the next
            // model the same rejected question arrives at the same place.
            if (! $attempt['retryable']) {
                return null;
            }
        }

        Log::warning('Document scan found no available Gemini model', [
            'tried' => $models,
        ]);

        return null;
    }

    /**
     * One model, one request.
     *
     * @return array{json: array<string, mixed>|null, retryable: bool}
     */
    private function askGemini(string $model, UploadedFile $file, string $prompt, array $schema): array
    {
        try {
            /*
             * Retried, unlike the other two drivers, because this one shares a
             * quota with everybody else on the free tier. Measured on a real
             * key: six identical scans, three answered in about nine seconds
             * and three came back **429** — Google's body says "experiencing
             * high demand", but the status is a rate limit. A driver that gave
             * up on the first one would look broken half the time while being
             * perfectly configured.
             *
             * Only 429 and 5xx are waited out. A 400 or a 401 is an answer, not
             * a queue: retrying a wrong key or a rejected schema spends three
             * times as long arriving at the same place.
             *
             * `$throw` is left at its default, and that is load-bearing —
             * Laravel's retry only fires on a thrown exception, so passing
             * `throw: false` here (which the first attempt at this did) turns
             * the whole thing into an expensive no-op. The throw is caught
             * below and handled exactly like a failed response.
             */
            $response = Http::timeout((int) config('scanner.gemini.timeout'))
                ->retry(
                    (int) config('scanner.gemini.retries'),
                    (int) config('scanner.gemini.retry_delay_ms'),
                    fn ($exception) => $exception instanceof ConnectionException
                        || ($exception->response?->status() ?? 0) === 429
                        || ($exception->response?->status() ?? 0) >= 500,
                )
                ->withHeaders(['x-goog-api-key' => config('scanner.gemini.api_key')])
                ->post($this->geminiEndpoint($model), [
                    // Gemini takes the image as a `part` beside the text, in a
                    // `contents` array — not as a flat list of typed inputs.
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [
                            ['text' => 'Read this document.'],
                            [
                                'inline_data' => [
                                    'mime_type' => $file->getMimeType(),
                                    'data' => base64_encode(file_get_contents($file->getRealPath())),
                                ],
                            ],
                        ],
                    ]],

                    // The prompt is a first-class field here rather than a
                    // message, which is why it does not sit in `contents`.
                    'systemInstruction' => [
                        'parts' => [['text' => $prompt]],
                    ],

                    /*
                     * `responseJsonSchema`, not `responseSchema`.
                     *
                     * Gemini has two schema fields and they take different
                     * dialects. `responseSchema` is an OpenAPI 3.0 subset: its
                     * `type` is a single value, so a `["string", "null"]` union
                     * is rejected outright ("Proto field is not repeating"),
                     * and `additionalProperties` is not a field it knows.
                     * `responseJsonSchema` takes real JSON Schema, which is
                     * what the other two drivers are already sent.
                     *
                     * This was `responseSchema` and every request 400'd —
                     * before the API key was even looked at, which is why a
                     * wrong key and a wrong schema were indistinguishable from
                     * the outside. Sending the same schema to all three drivers
                     * is the point: everything downstream of `read()` stays
                     * driver-agnostic only if the constraint really is the
                     * same. Only one of the two fields may be present.
                     */
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseJsonSchema' => $schema['schema'],
                        'maxOutputTokens' => self::MAX_TOKENS,

                        /*
                         * Thinking off, and this is a design statement rather
                         * than a tuning knob.
                         *
                         * This call is **transcription**: read what is printed
                         * and hand back the fields. Every judgement the system
                         * makes about a document is made afterwards, in PHP —
                         * `resolveType()` ranks five sources of evidence,
                         * `nameMatches()` compares the name, Carbon re-parses
                         * the dates — precisely because the small model is
                         * good at reading and poor at judging. So the thinking
                         * it does here is spent on a decision this code does
                         * not use, and it is charged against the same budget
                         * the answer has to fit in.
                         *
                         * Measured: 458–574 thinking tokens against a 1024
                         * budget on a synthetic card, and a real photograph is
                         * harder. With it off, the whole answer came back in
                         * 99 tokens and still fit inside 600.
                         *
                         * Gemini-only. `maxOutputTokens` is shared with the
                         * other two drivers; this field is not, and neither is
                         * the failure — OpenRouter and Anthropic are not
                         * spending this budget on thought.
                         */
                        'thinkingConfig' => ['thinkingBudget' => 0],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Document scan failed to reach Gemini', [
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            // The network, not the model — but the next model is on the same
            // network, so there is nothing to fall through to.
            return ['json' => null, 'retryable' => false];
        } catch (RequestException $exception) {
            /*
             * Thrown once the retries above are spent, because they have to
             * throw to be retried at all. Logged with the same two fields as a
             * plain failure, so a quota exhausted after four attempts and a
             * key rejected on the first read the same way in the log.
             */
            $status = $exception->response?->status() ?? 0;

            Log::warning('Document scan rejected by Gemini', [
                'model' => $model,
                'status' => $status,
                'body' => $exception->response?->body(),
                'attempts_exhausted' => true,
            ]);

            // 429 and 5xx are capacity; anything else is an answer about the
            // request itself, which the next model would repeat.
            return [
                'json' => null,
                'retryable' => $status === 429 || $status >= 500,
            ];
        }

        if ($response->failed()) {
            // The body carries Google's own reason — a bad key, an exhausted
            // free-tier quota, a rejected schema — and all three look the same
            // from the form, so the log is the only place to tell them apart.
            Log::warning('Document scan rejected by Gemini', [
                'model' => $model,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'json' => null,
                'retryable' => $response->status() === 429 || $response->status() >= 500,
            ];
        }

        return ['json' => $this->firstGeminiJson($response->json() ?? []), 'retryable' => false];
    }

    /**
     * Digs the model's answer out of Gemini's envelope.
     *
     * Written defensively on purpose: the response shape is the one part of a
     * third-party API most likely to move under you, and a missing key here
     * must degrade to "the scan found nothing" rather than throw on an
     * employee's upload form.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function firstGeminiJson(array $body): array
    {
        // Walk the likely paths rather than assuming one. Each is a place the
        // text has been returned under across Gemini's API versions.
        $candidates = [
            data_get($body, 'output.0.content.0.text'),
            data_get($body, 'candidates.0.content.parts.0.text'),
            data_get($body, 'output_text'),
            data_get($body, 'text'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            // Always json_decode — never string-match a model's output.
            $decoded = json_decode($candidate, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        /*
         * `finish_reason` is the field that names the cause, and logging only
         * the top-level keys is what made this undiagnosable for two days.
         *
         * The envelope looks perfectly healthy when the answer is truncated —
         * `candidates`, `usageMetadata`, `modelVersion` all present — so the
         * old line reported the one thing that is identical in the working and
         * the broken case. `MAX_TOKENS` here means the reply was cut off
         * mid-JSON and the reading is *recoverable* by widening the budget or
         * taking thought out of it; `SAFETY` or `RECITATION` mean the model
         * declined, which is a different problem with a different fix. The
         * same mistake as replacing the scanned `heading` with a derived
         * label: the evidence that says what to fix must survive the failure.
         *
         * The text is logged by *length and head* rather than in full — it is
         * a transcription of somebody's government ID, and the log is not
         * where that belongs. Thirty characters is enough to see that JSON
         * started and stopped.
         */
        $raw = data_get($body, 'candidates.0.content.parts.0.text');

        Log::warning('Gemini returned no parseable JSON', [
            'keys' => array_keys($body),
            'finish_reason' => data_get($body, 'candidates.0.finishReason'),
            'thoughts_tokens' => data_get($body, 'usageMetadata.thoughtsTokenCount'),
            'output_tokens' => data_get($body, 'usageMetadata.candidatesTokenCount'),
            'text_length' => is_string($raw) ? strlen($raw) : null,
            'text_head' => is_string($raw) ? substr($raw, 0, 30) : null,
        ]);

        return [];
    }

    /**
     * Reads the document through OpenRouter.
     *
     * OpenAI-shaped, which makes this the least inventive of the three
     * envelopes: one `messages` array, the image as an `image_url` part
     * carrying a data URI, and the schema in `response_format`. The only real
     * translation is the wrapper — `schema()` returns
     * `['type' => 'json_schema', 'schema' => …]` and this endpoint wants the
     * schema one level deeper, under a *named* `json_schema` object. Gemini
     * takes the same inner schema as `responseJsonSchema` and Anthropic as
     * `outputConfig.format`; all three are handed the identical constraint,
     * which is the only reason everything downstream of read() can stay
     * driver-agnostic.
     *
     * `strict` is what makes the schema binding rather than advisory. Without
     * it a model may answer prose that merely resembles the shape, and the
     * failure is silent: normalise() finds nothing and the form is blank.
     *
     * **This driver reaches a broker, not a provider.** See
     * config/scanner.php for what that means for RA 10173 — the short of it
     * is two processors rather than one, so `data_collection: deny` is sent
     * on every request rather than left to a dashboard setting somebody has
     * to remember.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null null when the call failed
     */
    private function readWithOpenRouter(UploadedFile $file, string $prompt, array $schema): ?array
    {
        $image = 'data:'.$file->getMimeType().';base64,'
            .base64_encode(file_get_contents($file->getRealPath()));

        try {
            /*
             * Retried like the Gemini driver and for the same reason: a broker
             * in front of a shared pool answers 429 under load, and a driver
             * that gave up on the first one would look broken while being
             * perfectly configured. Only 429 and 5xx are waited out — a 401 is
             * an answer, not a queue.
             *
             * `$throw` stays at its default because Laravel's retry only fires
             * on a thrown exception; the throw is caught below and logged with
             * the same fields a plain failure carries.
             */
            $response = Http::timeout((int) config('scanner.openrouter.timeout'))
                ->retry(
                    (int) config('scanner.openrouter.retries'),
                    (int) config('scanner.openrouter.retry_delay_ms'),
                    fn ($exception) => $exception instanceof ConnectionException
                        || ($exception->response?->status() ?? 0) === 429
                        || ($exception->response?->status() ?? 0) >= 500,
                )
                ->withToken((string) config('scanner.openrouter.api_key'))
                ->withHeaders([
                    // OpenRouter's own attribution headers. Not required, and
                    // a wrong value is not an error — but a key nobody can
                    // trace back to this system is a key nobody can turn off.
                    'HTTP-Referer' => (string) config('scanner.openrouter.referer'),
                    'X-Title' => (string) config('scanner.openrouter.title'),
                ])
                ->post((string) config('scanner.openrouter.endpoint'), [
                    'model' => config('scanner.openrouter.model'),

                    /*
                     * The prompt is a `system` message here rather than a
                     * first-class field — the one structural difference from
                     * Gemini, which carries it in `systemInstruction`.
                     */
                    'messages' => [
                        ['role' => 'system', 'content' => $prompt],
                        [
                            'role' => 'user',
                            'content' => [
                                ['type' => 'text', 'text' => 'Read this document.'],
                                ['type' => 'image_url', 'image_url' => ['url' => $image]],
                            ],
                        ],
                    ],

                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'document',
                            // Binding rather than advisory — see above.
                            'strict' => true,
                            'schema' => $schema['schema'],
                        ],
                    ],

                    'max_tokens' => self::MAX_TOKENS,

                    /*
                     * Routing preferences, sent per request so the guarantee
                     * travels with the code that depends on it rather than
                     * living in a dashboard nobody reads back.
                     *
                     * `data_collection: deny` keeps the scan away from
                     * upstreams that retain or train on it. A 201-file
                     * photograph is the wrong thing to leave in somebody's
                     * training set, and it is the difference between one
                     * cross-border transfer and an indefinite one.
                     */
                    'provider' => ['data_collection' => 'deny'],
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Document scan failed to reach OpenRouter', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        } catch (RequestException $exception) {
            Log::warning('Document scan rejected by OpenRouter', [
                'status' => $exception->response?->status(),
                'body' => $exception->response?->body(),
                'attempts_exhausted' => true,
            ]);

            return null;
        }

        if ($response->failed()) {
            /*
             * The body carries OpenRouter's reason, and the three that matter
             * look identical from the form: a bad key, a model that does not
             * exist, and a model that exists but cannot take an image or
             * honour a schema. Only the log tells them apart, which is why the
             * whole body goes in.
             */
            Log::warning('Document scan rejected by OpenRouter', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $this->firstOpenRouterJson($response->json() ?? []);
    }

    /**
     * Digs the model's answer out of OpenRouter's envelope.
     *
     * Defensive for the same reason firstGeminiJson() is: the response shape
     * is the part of a third-party API most likely to move, and a missing key
     * must degrade to "the scan found nothing" rather than throw on an
     * employee's upload form.
     *
     * An upstream that ignored `response_format` answers prose here. That is
     * not an exception — it is a model that cannot do the job — so it is
     * logged as such and the form stays empty.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function firstOpenRouterJson(array $body): array
    {
        $content = data_get($body, 'choices.0.message.content');

        if (is_string($content) && trim($content) !== '') {
            // Always json_decode — never string-match a model's output.
            $decoded = json_decode($content, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        Log::warning('OpenRouter returned no parseable JSON', [
            'model' => config('scanner.openrouter.model'),
            'keys' => array_keys($body),
            // OpenRouter reports a refusal separately from the content, and it
            // is the one field that says *why* nothing came back.
            'refusal' => data_get($body, 'choices.0.message.refusal'),
        ]);

        return [];
    }

    /** @return array<string, mixed>|null null when the call failed */
    private function readWithAnthropic(UploadedFile $file, string $prompt, array $schema): ?array
    {
        try {
            $message = $this->client()->messages->create(
                model: config('scanner.model'),
                maxTokens: self::MAX_TOKENS,
                system: $prompt,
                messages: [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'mediaType' => $file->getMimeType(),
                                'data' => base64_encode(file_get_contents($file->getRealPath())),
                            ],
                        ],
                        ['type' => 'text', 'text' => 'Read this document.'],
                    ],
                ]],
                outputConfig: ['format' => $schema],
            );
        } catch (APIStatusException $exception) {
            // A failed scan must never block the upload — HR types the fields
            // and carries on, exactly as before the feature existed.
            Log::warning('Document scan failed', [
                'type' => $exception->type?->value,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        return $this->firstJson($message->content);
    }

    /**
     * Cleans what the model wrote about a person.
     *
     * Everything here is untrusted input. Enums are checked against the same
     * lists the form validates against — a hallucinated civil status becomes
     * null rather than a value the save would then reject — and dates are
     * re-parsed through Carbon. A birth date in the future is dropped: it is
     * always a misread, never a person.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normaliseForm(array $raw): array
    {
        $birthDate = $this->date($raw['birth_date'] ?? null);

        $fields = [
            'last_name' => $this->text($raw['last_name'] ?? null),
            'first_name' => $this->text($raw['first_name'] ?? null),
            'middle_name' => $this->text($raw['middle_name'] ?? null),
            'suffix' => $this->text($raw['suffix'] ?? null),

            // A future birth date is a misread year, and it would fail
            // `before:today` on save anyway — better to leave the field empty
            // than to fill it with something the form will refuse.
            'birth_date' => $birthDate !== null && $birthDate < now()->toDateString()
                ? $birthDate
                : null,

            'birth_place' => $this->text($raw['birth_place'] ?? null),
            'gender' => $this->oneOf($raw['gender'] ?? null, ['male', 'female']),
            'civil_status' => $this->oneOf(
                $raw['civil_status'] ?? null,
                ['single', 'married', 'widowed', 'separated'],
            ),
            'nationality' => $this->text($raw['nationality'] ?? null),
            'religion' => $this->text($raw['religion'] ?? null),
            'blood_type' => $this->text($raw['blood_type'] ?? null),

            'email' => $this->email($raw['email'] ?? null),
            'mobile_number' => $this->text($raw['mobile_number'] ?? null),
            'present_address' => $this->text($raw['present_address'] ?? null),
            'permanent_address' => $this->text($raw['permanent_address'] ?? null),

            'emergency_contact_name' => $this->text($raw['emergency_contact_name'] ?? null),
            'emergency_contact_relationship' => $this->text($raw['emergency_contact_relationship'] ?? null),
            'emergency_contact_number' => $this->text($raw['emergency_contact_number'] ?? null),

            /*
             * Government numbers get the same shape check the ID scanner
             * applies, and for the same reason: a form line read as
             * "SSS NO. 34-1234567-8" is a caption folded into a value, and an
             * address read as a TIN is a line that was never a number at all.
             */
            'sss_number' => $this->governmentNumber($raw['sss_number'] ?? null),
            'philhealth_number' => $this->governmentNumber($raw['philhealth_number'] ?? null),
            'pagibig_number' => $this->governmentNumber($raw['pagibig_number'] ?? null),
            'tin' => $this->governmentNumber($raw['tin'] ?? null),
            'drivers_license_number' => $this->governmentNumber($raw['drivers_license_number'] ?? null),

            'note' => $this->text($raw['note'] ?? null),
        ];

        return $this->dropBleed($fields);
    }

    /**
     * Removes values the model copied from a neighbouring line.
     *
     * The prompt says to return null for a field that is not on the form. A
     * 0.9B model does not reliably obey that: on a sheet with no RELIGION and
     * no PERMANENT ADDRESS it answered "Mother" (taken from the emergency
     * contact's relationship) and "34-1234567-8" (taken from the SSS line).
     * Both were measured, repeatedly, on a form where those labels are simply
     * absent.
     *
     * Two checks, because there are two ways a bleed shows itself:
     *
     * 1. **A value in the wrong shape.** An address is words; a line that is
     *    almost all digits is somebody's ID number wearing an address's label.
     * 2. **A value that appears twice.** Two unrelated fields holding the
     *    identical string is a copy, not a coincidence — and the field with no
     *    shape of its own is the one that loses, because it is the one with no
     *    other way to be checked.
     *
     * `permanent_address` matching `present_address` is exempt: "same as
     * present address" is what most people actually write.
     *
     * @param  array<string, string|null>  $fields
     * @return array<string, string|null>
     */
    private function dropBleed(array $fields): array
    {
        foreach (['present_address', 'permanent_address'] as $field) {
            if ($fields[$field] !== null && ! $this->looksLikeProse($fields[$field])) {
                $fields[$field] = null;
            }
        }

        // Fields with no shape of their own, and so nothing else to check
        // them against.
        $unverifiable = ['religion', 'blood_type', 'birth_place', 'nationality', 'permanent_address'];

        foreach ($unverifiable as $field) {
            $value = $fields[$field] ?? null;

            if ($value === null) {
                continue;
            }

            foreach ($fields as $other => $otherValue) {
                if ($other === $field || $otherValue === null) {
                    continue;
                }

                if ($field === 'permanent_address' && $other === 'present_address') {
                    continue;
                }

                if (mb_strtolower((string) $otherValue) === mb_strtolower($value)) {
                    $fields[$field] = null;

                    break;
                }
            }
        }

        return $fields;
    }

    /** Words rather than a number: at least one run of three letters. */
    private function looksLikeProse(string $value): bool
    {
        return preg_match('/\p{L}{3}/u', $value) === 1;
    }

    /** Reuses documentNumber(), so both readings drop a caption the same way. */
    private function governmentNumber(mixed $value): ?string
    {
        return $this->documentNumber($value);
    }

    private function oneOf(mixed $value, array $allowed): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function email(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text !== null && filter_var($text, FILTER_VALIDATE_EMAIL) ? $text : null;
    }

    private function formPrompt(): string
    {
        return <<<'PROMPT'
        You read filled-in Philippine HR forms — a 201 personal data sheet, a
        job application, or a bio-data — and report what is written on them.
        You are filling in a form a person will check before it is saved.

        Rules that matter:
        - **A value is what is written beside a label, never the label.**
          "SSS NO.: 34-1234567-8" means the number is 34-1234567-8.
        - Philippine forms print the surname first and often in a separate box.
          Report last_name, first_name and middle_name separately, as written.
          A middle *initial* is still the middle name — report it.
        - Report only what is legible. Never infer, complete, or invent a
          number, a date, or a spelling that is not written there.
        - If a field is absent or unreadable, return null. A null is correct and
          useful; a guess is not, because a wrong birth date is worse than an
          empty one somebody types.
        - birth_date must be ISO (YYYY-MM-DD). These forms print "March 24,
          1997" and "07/31/1997" — the second is month/day/year.
        - gender must be exactly "male" or "female"; civil_status exactly
          "single", "married", "widowed" or "separated". If what is written does
          not fit, return null rather than the nearest one.
        - Addresses are one line. Join what is written; do not reformat it.
        - Put anything worth flagging in note — handwriting you could not read,
          two conflicting values, a form that is not a 201 form at all.
        PROMPT;
    }

    /** @return array<string, mixed> */
    private function formSchema(): array
    {
        $string = ['type' => ['string', 'null']];

        $properties = [];

        foreach ([
            'last_name', 'first_name', 'middle_name', 'suffix',
            'birth_date', 'birth_place', 'nationality', 'religion', 'blood_type',
            'email', 'mobile_number', 'present_address', 'permanent_address',
            'emergency_contact_name', 'emergency_contact_relationship',
            'emergency_contact_number',
            'sss_number', 'philhealth_number', 'pagibig_number', 'tin',
            'drivers_license_number', 'note',
        ] as $field) {
            $properties[$field] = $string;
        }

        $properties['gender'] = ['type' => ['string', 'null'], 'enum' => ['male', 'female', null]];
        $properties['civil_status'] = [
            'type' => ['string', 'null'],
            'enum' => ['single', 'married', 'widowed', 'separated', null],
        ];

        return [
            'type' => 'json_schema',
            'schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => array_keys($properties),
                'additionalProperties' => false,
            ],
        ];
    }

    private function systemPrompt(): string
    {
        $types = collect(config('scanner.document_types'))
            ->map(fn (string $description, string $key) => "- {$key}: {$description}")
            ->implode("\n");

        return <<<PROMPT
        You read scanned Philippine HR documents for a 201 file and report what
        is printed on them. You are filling in a form a person will check.

        Classify the document as exactly one of these keys:
        {$types}

        Rules that matter:
        - **Classify from the document's own title, not from words inside its
          fields.** The heading at the top says what the document is. An NBI
          clearance whose purpose reads "LOCAL EMPLOYMENT" is still a
          clearance; a medical certificate issued for a driving job is still
          medical. The purpose of a document is not its type.
        - **A value is what is printed beside a label, not the label itself.**
          "NBI ID NO. / N-A1234567890" means the number is N-A1234567890 —
          never fold the caption into the value you report.
        - **NBI Clearance / Official Seal Recognition:** An authentic NBI
          clearance features an official circular NBI dry seal / security watermark
          (displaying the Philippine sun with rays, scales of justice, and
          National Bureau of Investigation / Department of Justice seal stamped
          partially over the applicant's photograph and onto the patterned security
          paper). When this official seal or watermark is visible, classify the
          document as `clearance` and set title to "NBI Clearance".
        - **TIN ID / BIR Seal & Authenticity Check:** An authentic BIR TIN ID
          card has these security features: (1) the official circular BIR seal
          at the upper-right corner, bearing the Bureau of Internal Revenue /
          Department of Finance emblem; (2) the heading "Republic of the
          Philippines / Department of Finance / BUREAU OF INTERNAL REVENUE"
          printed vertically along the right side; (3) a large 9-digit
          TAXPAYER IDENTIFICATION NUMBER (format: XXX-XXX-XXX); (4) a "TIN ID
          ISSUE / EXPIRY DATE" field — check that the expiry date has not
          passed; (5) a green-patterned security paper background; (6) a QR
          code at the bottom with a DIGITAL TIN ID CONTROL NUMBER. When the
          BIR seal is visible and the card layout matches, classify as
          `government_id` and set title to "TIN ID". If the BIR seal is
          missing, the QR code is absent, the security paper pattern is wrong,
          text appears misaligned or digitally altered, or the expiry date
          has passed, note these findings in the `note` field and lower
          confidence accordingly.
        - Report only what is legible on the document. Never infer, complete, or
          invent a number or a date that is not printed there.
        - If a field is unreadable or absent, return null for it. A null is
          correct and useful; a guess is not.
        - Dates must be ISO (YYYY-MM-DD). Philippine documents commonly print
          "March 24, 1997" or "07/31/2025" — those are month/day/year.
        - Philippine forms label the same two dates a dozen ways. Treat any of
          these as the **issue** date: "Date Issued", "Date of Issue", "Date
          Signed", "Date of Examination", "Issued On". And any of these as the
          **expiry**: "Expiration Date", "Valid Until", "Expiry", "Contract
          End", "Valid Thru".
        - A sample or specimen document (placeholder text like "JANE DOE",
          "YYYY/MM/DD", or a SAMPLE watermark) has no real data: set
          confidence to "low" and say so in the note.
        - confidence is "high" only when the document is clearly legible and
          you are reporting text you can actually read.

        Two fields are written by you rather than copied off the document, and
        smaller models get them wrong in the same way — by pasting back the
        block of text they were read out of:
        - title: what the document *is*, in three or four words. "Driver's
          Licence", "NBI Clearance", "Certificate of Employment". Never the
          person's name, never a field label, never more than one line.
        - note: null unless something is genuinely worth flagging — a sample
          document, an expiry already past, text you could not make out. One
          short sentence. Do not use it to repeat what you read.
        - **Driver's Licence restriction / DL codes:** If the document is a
          Philippine LTO driver's licence, read the **DL CODES** or
          **RESTRICTION** or **CONDITIONS** field printed on the card. These
          are the vehicle categories the holder is allowed to drive. Common
          codes: A (motorcycles), A1 (mopeds), B (cars/light vehicles),
          B1 (three-wheelers), B2 (car with automatic transmission),
          BE (car + trailer), C (trucks), D (buses), CE (articulated trucks),
          1–8 (old restriction numbers). Report them exactly as printed,
          comma-separated. If the document is not a driver's licence or the
          codes are not legible, return null for dl_codes.
        PROMPT;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'json_schema',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'type' => [
                        'type' => 'string',
                        'enum' => array_keys(config('scanner.document_types')),
                        'description' => 'Which document this is.',
                    ],
                    /*
                     * A separate `heading` field was tried here and measurably
                     * made everything worse: asking this model for one more
                     * required field dropped expiry-date accuracy from 6/6 to
                     * 3/6 across the same six documents, and turned the titles
                     * into transcription dumps. A 0.9B model has a budget, and
                     * spending it on a ninth field costs the other eight. Do
                     * not add fields here without measuring the rest again.
                     */
                    'title' => [
                        'type' => ['string', 'null'],
                        'description' => 'A short human label, e.g. "Non-Professional Driver\'s Licence".',
                    ],
                    'document_number' => [
                        'type' => ['string', 'null'],
                        'description' => 'Licence No., NBI ID No., PhilSys number, or certificate number.',
                    ],
                    'issued_at' => [
                        'type' => ['string', 'null'],
                        'description' => 'Date of issue in YYYY-MM-DD, or null.',
                    ],
                    'expires_at' => [
                        'type' => ['string', 'null'],
                        'description' => 'Expiry / "valid until" date in YYYY-MM-DD, or null.',
                    ],
                    'name_on_document' => [
                        'type' => ['string', 'null'],
                        'description' => 'The person named on the document, as printed.',
                    ],
                    'confidence' => [
                        'type' => 'string',
                        'enum' => ['high', 'medium', 'low'],
                    ],
                    'note' => [
                        'type' => ['string', 'null'],
                        'description' => 'One short sentence if something is worth flagging.',
                    ],
                    'dl_codes' => [
                        'type' => ['string', 'null'],
                        'description' => 'Driver\'s licence restriction / DL codes as printed, comma-separated (e.g. "A, B, B2"). Null for non-licence documents.',
                    ],
                ],
                'required' => [
                    'type', 'title', 'document_number', 'issued_at',
                    'expires_at', 'name_on_document', 'confidence', 'note',
                    'dl_codes',
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    /** @param array<int, mixed> $content */
    private function firstJson(array $content): array
    {
        foreach ($content as $block) {
            if ($block->type === 'text') {
                // Always json_decode — never string-match a model's output.
                return json_decode($block->text, true) ?? [];
            }
        }

        return [];
    }

    /**
     * Everything the model returned is treated as untrusted input: the type is
     * checked against the real list, dates are re-parsed, and the name is
     * compared here rather than asked of the model.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalise(array $raw, ?Employee $employee, ?UploadedFile $file = null): array
    {
        $claimed = in_array($raw['type'] ?? null, EmployeeDocument::TYPES, true)
            ? $raw['type']
            : null;

        /*
         * The document's own heading overrules the model's choice of key.
         *
         * Measured, not assumed: on an NBI clearance this model writes title
         * "NBI Clearance" and then answers type "drivers_license", four times
         * out of four. The heading is text it transcribed off the page; the
         * enum is a judgement it made about that text, and it is reliably
         * better at the first than the second. So the heading is read here,
         * in PHP, against a keyword list — the same reason the name check
         * lives here rather than in the prompt.
         *
         * Falls back to the model's key when the heading carries no keyword,
         * which is the common case for a licence whose title line is just the
         * field caption above the name.
         */
        [$type, $typeSource] = $this->resolveType($raw, $claimed, $employee);

        // Named rather than spread inline, because the expiry check below has
        // to read the *parsed* date rather than what the model wrote.
        $dates = $this->dates($raw['issued_at'] ?? null, $raw['expires_at'] ?? null);

        /*
         * A document of this type cannot carry these fields, so it is not
         * offered them.
         *
         * The same `type_cannot_have` config that rejects a contradicted
         * *guess*, doing the other half of its job. One statement of fact —
         * a résumé has no ID number and does not expire, a PSA civil registry
         * document does not expire — with two consequences: it rules the type
         * out when the model claims it, and it clears the field once the type
         * is settled by any means.
         *
         * The second half matters on its own. A PSA certificate identified
         * from its letterhead is *correctly* typed, and the model can still
         * have read a date off it — Philippine PSA paper prints an issue date
         * and a registry date, and a misread of either arrives here looking
         * like an expiry. Filled in, it would put a birth certificate into
         * CredentialExpiryScanner's renewal queue, where it would be chased
         * forever for a renewal that does not exist.
         */
        foreach (config("scanner.type_cannot_have.{$type}", []) as $field) {
            if (array_key_exists($field, $dates)) {
                $dates[$field] = null;
            }

            $raw[$field] = null;
        }

        /*
         * The same clearing, for the cards `type_cannot_have` cannot reach.
         *
         * A TIN ID is a `government_id` and so is a passport, so the fact
         * cannot be stated per type — see `neverExpires()`. The date is
         * dropped for the same reason a PSA's is: a TIN is issued for life,
         * so an expiry on one is the model answering a question the card does
         * not have, and keeping it would put a permanent number into
         * `CredentialExpiryScanner`'s renewal queue for good.
         *
         * The prompt now says a TIN ID carries no expiry — it used to assert
         * the opposite, in as many words, which was the system *instructing*
         * the hallucination. This is the guard behind that fix rather than a
         * substitute for it: a model told the right thing can still read a
         * control number or an issue date as an expiry.
         */
        $neverExpires = $this->neverExpires($type, $raw);

        if ($neverExpires) {
            $dates['expires_at'] = null;
            $raw['expires_at'] = null;
        }

        /*
         * A civil registry document is read again, in its own terms.
         *
         * The general prompt asks for an ID card's fields — a number, an issue
         * date, an expiry — and a PSA certificate has none of those in that
         * sense. Measured on one: it answered the registry number correctly,
         * duplicated the receiving date into `expires_at`, and put the
         * mother's occupation in `note`. It was not misreading the page; it
         * was answering questions the page does not have.
         *
         * So the second pass asks for what is actually printed there, by the
         * numbered labels the form itself uses. It costs another call, and it
         * runs only when the type has already resolved to `psa` — which is a
         * handful of uploads, not every one.
         */
        $registryRaw = $type === 'psa' ? $this->readCivilRegistry($file) : null;
        $registry = $registryRaw === null ? null : $this->normaliseRegistry($registryRaw);

        /*
         * For a certificate, the question the name check should be asking.
         *
         * Comparing the name on a birth certificate against the employee can
         * only ever fail — it names their child. The claim they actually have
         * to it is being a parent, so that is what is compared, using the same
         * name rules as everywhere else rather than a second set.
         *
         * Null when it cannot be answered: no employee in hand, no parent
         * read, or the two parent names collided and neither can be trusted.
         */
        if ($registry !== null) {
            $registry['claimed_by_employee'] = $this->parentClaim($registry, $employee);
        }

        return [
            'type' => $type,
            /*
             * Where the type came from, because the two sources are not worth
             * the same.
             *
             * A type read off the document's own printed heading — "NBI
             * CLEARANCE" across the top — is evidence about what the paper is.
             * The model's own choice of key is a guess about that evidence,
             * and a measurably worse one: on the same clearance it answered
             * "drivers_license" four times out of four while transcribing the
             * heading correctly every time.
             *
             * The form uses this to decide how hard to argue when HR files the
             * document under a different type: a heading that says otherwise
             * is worth refusing over, a guess is only worth mentioning.
             */
            'type_source' => $typeSource,

            /*
             * Whether the type is worth refusing an upload over.
             *
             * The form blocks a contradiction only on evidence from the
             * document itself — a number already on the employee's 201 file,
             * or the heading printed across the top. The weaker signals are
             * said out loud and left to HR: a number *shape* is shared between
             * cards, a validity period overlaps between types, and the model's
             * bare guess was measured wrong four times out of four on the same
             * clearance. Blocking on any of those would refuse correct filings.
             */
            'type_certain' => in_array($typeSource, [self::TYPE_FROM_STORED_NUMBER, self::TYPE_FROM_HEADING], true),
            // The label wins over the model's own title where there is one:
            // the type it comes from has been checked against a real list,
            // and the free text has not. Falls back to the model only when
            // the type was rejected and there is nothing else to offer.
            'title' => config("scanner.labels.{$type}") ?? $this->text($raw['title'] ?? null),

            /*
             * The heading exactly as the model transcribed it, kept beside the
             * derived label rather than replaced by it.
             *
             * It used to be discarded, and that is what made a wrong type
             * impossible to explain: the keyword rules read this line, so when
             * they miss, this line is the reason — and nobody, HR or developer,
             * could see it. Three separate attempts were made at improving the
             * classifier while the one piece of evidence that would have said
             * what to fix was being thrown away.
             *
             * It is shown on the panel, so "the scanner got it wrong" becomes
             * "the scanner read *this*, which is not in the keyword list" —
             * which is a sentence somebody can act on.
             */
            'heading' => $this->text($raw['title'] ?? null, 200),
            'document_number' => $this->documentNumber($raw['document_number'] ?? null),
            ...$dates,

            /*
             * Whether there was an expiry to find at all, answered here so the
             * panel has one thing to read.
             *
             * It used to be derived in the *component*, from a list of types
             * the controller shipped down — which could only ever be right for
             * types that never expire as a class, and a TIN ID is not one of
             * those: it is a `government_id`, same as a passport. So a TIN ID
             * showed "Not found" under Expires, which reads as the scanner
             * having looked and missed rather than as a fact about the card,
             * and invites HR to type a date that does not exist.
             */
            'never_expires' => $neverExpires,
            'name_on_document' => $this->text($raw['name_on_document'] ?? null),
            // Compared in PHP, not by the model: filing a document under the
            // wrong employee is a real mistake, and the check for it should
            // not itself depend on the thing being checked.
            'name_matches' => $this->nameMatches($raw['name_on_document'] ?? null, $employee),
            /*
             * The stronger of the two identity checks, when it can run at all.
             *
             * A licence number identifies one person; a name does not, and OCR
             * reads digits far more reliably than letters — measured on the
             * same six documents, every document number came back right while
             * names arrived truncated and misread. So where HR has already
             * recorded the number on the 201 file, comparing against it beats
             * comparing names outright.
             *
             * null when there is nothing on file to compare with, which is the
             * common case and is not a finding.
             */
            'number_matches' => $this->numberMatches(
                $raw['document_number'] ?? null,
                $type,
                $employee,
            ),
            /*
             * Whether the number is *shaped* like the type it claims to be.
             *
             * The one check that looks at the document rather than at who it
             * belongs to. Unlike `number_matches` it needs nothing on file, so
             * it still says something on the first document an employee ever
             * has scanned — the case where every identity check is blind.
             */
            /*
             * Checked against the resolved type, or against the guess that was
             * *rejected* when there is no resolved type — because a number
             * that does not fit its claimed type is the reason the type was
             * discarded, and the panel has to be able to say so. Without the
             * fallback, declining to name the type also silences the
             * explanation for declining.
             */
            'number_format_ok' => $this->numberFormatLooksRight(
                $raw['document_number'] ?? null,
                $type ?? $claimed,
            ),
            /*
             * Whether the document being filed has already lapsed.
             *
             * The check existed only *after* the upload — CredentialExpiryScanner
             * reads stored rows and the Credentials screen reports them, which
             * means a licence two years out of date was filed, looked fine, and
             * turned up on another screen later. Saying so at the moment of
             * filing is the cheapest place to catch it.
             *
             * It reads config/credentials.php rather than deciding for itself,
             * so this panel, the Credentials screen and Deployment Readiness
             * cannot come to different conclusions about the same licence —
             * the same reason DeploymentReadinessChecker reuses the scanners
             * instead of judging a lapsed licence itself.
             */
            'expiry' => $this->expiryStatus($dates['expires_at'] ?? null, $type),

            /*
             * Whether a different name on this kind of document is expected
             * rather than wrong. The form still shows whose name was read; it
             * just does not refuse the upload over it. See
             * `scanner.names_may_differ`.
             */
            'name_may_differ' => in_array($type, config('scanner.names_may_differ', []), true),

            /*
             * What a civil registry document actually says. Null for every
             * other type — the second pass does not run for them.
             */
            'registry' => $registry,
            'confidence' => in_array($raw['confidence'] ?? null, ['high', 'medium', 'low'], true)
                ? $raw['confidence']
                : 'low',
            'note' => $this->note($raw['note'] ?? null),

            /*
             * DL restriction codes from a driver's licence, if present.
             *
             * Only meaningful for `drivers_license` type documents. A manpower
             * agency deploys drivers, forklift operators, and truck drivers —
             * knowing which vehicle categories they are licensed for is as
             * important as knowing whether the licence is current.
             *
             * Validated against the known Philippine DL codes so a hallucinated
             * code is dropped rather than shown.
             */
            'dl_codes' => $type === 'drivers_license'
                ? $this->parseDlCodes($raw['dl_codes'] ?? null)
                : null,

            /*
             * Government ID number validation — format and checksum checks
             * for SSS, PhilHealth, TIN, PhilSys, and Pag-IBIG numbers.
             *
             * Only meaningful for `government_id` type documents. The heading
             * tells the validator which card it is, so a TIN ID is checked
             * against TIN rules and an SSS card against SSS rules.
             *
             * Reported as a warning, never blocking — same rule as
             * `number_format_ok`. A failed check is a reason to look, not
             * a reason to refuse.
             */
            'id_validation' => $type === 'government_id'
                ? app(GovernmentIdValidator::class)->validate(
                    $this->documentNumber($raw['document_number'] ?? null),
                    $raw['title'] ?? null,
                )
                : null,

            /*
             * Chronological and logical warnings about the document.
             *
             * These are heuristic checks that catch the kinds of mistakes a
             * person would notice on a second glance: a future issue date, an
             * underage clearance holder, a suspiciously short validity. None
             * of them block the upload — they are flags, not gates.
             */
            'anomalies' => $this->detectAnomalies($dates, $type, $employee),
        ];
    }

    /**
     * The note, unless the model handed the instruction back instead of an
     * observation.
     *
     * A small model asked for "null unless something is worth flagging" will
     * sometimes answer with that sentence, and it arrives looking exactly like
     * a finding about the document. Cheap to catch here, and impossible to
     * rule out in the prompt — the instruction has to say the word "null" to
     * be an instruction at all.
     */
    private function note(mixed $value): ?string
    {
        $note = $this->text($value, 200);

        if ($note === null) {
            return null;
        }

        $echo = str_starts_with(mb_strtolower($note), 'null')
            || str_contains(mb_strtolower($note), 'worth flagging');

        return $echo ? null : $note;
    }

    /**
     * Chronological and logical warnings about a scanned document.
     *
     * Each check is deterministic: a future date is always wrong, an
     * underage clearance holder is always suspicious. None of these block
     * the upload — they are flags that tell HR where to look, the same
     * posture every other warning in this scanner has.
     *
     * The checks are ordered from most to least alarming, and each is
     * phrased as a short sentence HR can read on the panel.
     *
     * @param  array{issued_at: string|null, expires_at: string|null}  $dates
     * @return array<int, string> empty when nothing is anomalous
     */
    private function detectAnomalies(
        array $dates,
        ?string $type,
        ?Employee $employee,
    ): array {
        $warnings = [];
        $today = Carbon::today();

        $issuedAt = $dates['issued_at'] ?? null;
        $expiresAt = $dates['expires_at'] ?? null;

        /*
         * 1. Future issue date — a document cannot have been issued tomorrow.
         *    Always a misread year or a fabricated document.
         */
        if ($issuedAt !== null) {
            $issued = Carbon::parse($issuedAt);

            if ($issued->isAfter($today)) {
                $warnings[] = "Issue date ({$issuedAt}) is in the future.";
            }
        }

        /*
         * 2. Expiry before issue — already partially covered by dates(),
         *    which drops both dates when this happens. This adds explicit
         *    messaging for the panel when the raw data showed the problem
         *    before dates() cleared it.
         */
        if ($issuedAt !== null && $expiresAt !== null) {
            $issued = Carbon::parse($issuedAt);
            $expires = Carbon::parse($expiresAt);

            if ($expires->isBefore($issued)) {
                $warnings[] = "Expiry date ({$expiresAt}) is before the issue date ({$issuedAt}).";
            }
        }

        /*
         * 3. Underage check — an NBI/police/barangay clearance cannot be
         *    issued to someone under 18. If the employee's birth date is
         *    on file and the document was issued when they were a minor,
         *    the dates are inconsistent.
         */
        if (
            $type === 'clearance'
            && $issuedAt !== null
            && $employee?->birth_date !== null
        ) {
            $ageAtIssue = Carbon::parse($employee->birth_date)
                ->diffInYears(Carbon::parse($issuedAt));

            if ($ageAtIssue < 18) {
                $warnings[] = "Holder would have been {$ageAtIssue} years old at the issue date — clearances require minimum age 18.";
            }
        }

        /*
         * 4. Suspiciously short validity — a clearance valid for less than
         *    a month is either a misread date or a document that was issued
         *    already expired. Real clearances run 6–12 months minimum.
         */
        if (
            $type === 'clearance'
            && $issuedAt !== null
            && $expiresAt !== null
        ) {
            $months = Carbon::parse($issuedAt)->diffInMonths(Carbon::parse($expiresAt));

            if ($months < 1) {
                $warnings[] = 'Validity period is less than 1 month — unusually short for a clearance.';
            }
        }

        /*
         * 5. Duplicate document number — removed, and worth the explanation
         *    so it is not written a second time.
         *
         * It queried `employee_documents.document_number`, **a column that
         * does not exist**: the number is a *check* in this system and is
         * never stored on the document row (see the accuracy screen, which
         * leaves it out of the compared fields for the same reason). So the
         * check could never have found a duplicate — and on SQLite it did not
         * even fail, because SQLite reads a double-quoted identifier it cannot
         * resolve as a *string literal*, so `"document_number" = '...'` is
         * simply false and the whole suite passed. Postgres refuses it, which
         * is how it was found.
         *
         * The question it was asking is real and already answered elsewhere:
         * a number keyed against two people is
         * `RecordIntegrityChecker::sharedNumbers()`, which compares in PHP
         * because those columns are encrypted, and a number that contradicts
         * this employee's own file is the `number_matches` check on the panel
         * above. Adding a third opinion here would be a third answer to one
         * question.
         */

        return $warnings;
    }

    /**
     * The type a document's printed heading implies, or null when it says
     * nothing recognisable.
     *
     * @param  string|null  $printed  the heading as the model transcribed it
     */
    /**
     * What kind of document this is, and how confidently that was decided.
     *
     * Five sources, strongest first, and the order is the whole design. It is
     * the same precedence the identity check already uses — a number belongs
     * to one person and OCR reads digits well, while free text is judgement —
     * applied to the second question the scanner has to answer.
     *
     * 1. **A number already on this employee's 201 file.** If what is printed
     *    matches the licence number HR typed into the licence field, the paper
     *    is a licence. Nothing else available is this strong: a human has
     *    already said what that number is, under a named field.
     * 2. **The document's own printed heading.** Evidence about the paper,
     *    from the paper.
     * 3. **The shape of the number.** An LTO licence number is a letter and
     *    ten digits and nothing else in a 201 file is. Weaker than a heading
     *    because shapes are shared — a twelve-digit number is PhilHealth or
     *    Pag-IBIG, and that ambiguity is why this only answers when exactly
     *    one type fits.
     * 4. **How long it is valid for.** Two dates the model transcribed
     *    separately, so the gap between them is not something it can invent to
     *    fit a guess. Sixty months is a licence; nothing else in a 201 file
     *    runs that long.
     * 5. **The model's own choice of key**, which is the last resort and was
     *    measured wrong four times out of four on an NBI clearance whose
     *    heading it transcribed correctly every time.
     *
     * @param  array<string, mixed>  $raw
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveType(array $raw, ?string $claimed, ?Employee $employee): array
    {
        $number = $this->documentNumber($raw['document_number'] ?? null);

        if ($type = $this->typeFromStoredNumber($number, $employee)) {
            return [$type, self::TYPE_FROM_STORED_NUMBER];
        }

        if ($type = $this->typeFromHeading($raw['title'] ?? null)) {
            return [$type, self::TYPE_FROM_HEADING];
        }

        /*
         * What HR's own corrections say this heading is. It sits here rather
         * than higher because the config's keyword list is curated and this
         * list is not, and higher than the three machine signals because
         * those were measured wrong and this one carries somebody's decision.
         */
        if ($type = $this->typeFromCorrections($raw['title'] ?? null)) {
            return [$type, self::TYPE_FROM_LEARNED];
        }

        if ($type = $this->typeFromNumberFormat($number)) {
            return [$type, self::TYPE_FROM_NUMBER_FORMAT];
        }

        if ($type = $this->typeFromValidity($raw['issued_at'] ?? null, $raw['expires_at'] ?? null)) {
            return [$type, self::TYPE_FROM_VALIDITY];
        }

        /*
         * The model's guess is the last resort, and it is only kept if the
         * document does not contradict it. A reading that says "resume" while
         * reporting a printed ID number has argued with itself, and the right
         * answer then is that the type is unknown — a null leaves the form's
         * type field alone for HR rather than filling it with a second guess.
         */
        if ($claimed === null || $this->contradictsDocument($claimed, $raw)) {
            return [null, null];
        }

        return [$claimed, self::TYPE_FROM_MODEL];
    }

    /**
     * The type HR has filed for this printed heading before.
     *
     * Resolved in PHP against our own rows; nothing is sent to the provider,
     * which is deliberate — past readings are other employees' documents, and
     * sending them as examples would be a fresh cross-border transfer for a
     * gain this already achieves. Never `type_certain`: a rule the system
     * wrote for itself has not been measured, so batch filing still holds it
     * for a person.
     */
    private function typeFromCorrections(?string $heading): ?string
    {
        $type = app(ScannerCorrectionMemory::class)->typeFor($heading);

        return in_array($type, EmployeeDocument::TYPES, true) ? $type : null;
    }

    /**
     * Whether what was read rules the guessed type out.
     *
     * Only asked of the model's own guess. The stronger signals are evidence
     * from the document or from the 201 file, and evidence does not need to be
     * second-guessed by an absence — a licence whose expiry the model failed
     * to read is still a licence.
     *
     * @param  array<string, mixed>  $raw
     */
    private function contradictsDocument(string $type, array $raw): bool
    {
        /*
         * The number does not fit the type that was guessed.
         *
         * The system already computes this and shows it — the very reading
         * that prompted this said "Drivers License" and, one line below,
         * "P231GLBO40-MQ2718306 is not shaped like a Drivers License number".
         * It was arguing with itself on screen and filling the field in
         * anyway.
         *
         * `number_format_ok` is documented as reported and never blocking, and
         * that still holds: this does not refuse an upload. It declines to
         * *fill in a type from a bare guess*, and the cost of being wrong is
         * that HR picks the type — which is exactly what already happens when
         * no signal fires at all. Only asked of the model's guess, and only
         * for the two types that have a known format.
         */
        if ($this->numberFormatLooksRight($raw['document_number'] ?? null, $type) === false) {
            return true;
        }

        foreach (config("scanner.type_cannot_have.{$type}", []) as $field) {
            $value = $field === 'document_number'
                ? $this->documentNumber($raw[$field] ?? null)
                : $this->date($raw[$field] ?? null);

            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The number is one a person already filed under a named field.
     *
     * This is the only signal here that is not the model's own reading of the
     * paper — it is HR's earlier reading of the same person's other paperwork,
     * and so the only one with a human behind it.
     */
    private function typeFromStoredNumber(?string $printed, ?Employee $employee): ?string
    {
        if ($printed === null || $employee === null) {
            return null;
        }

        $scanned = $this->digitsAndLetters($printed);

        if ($scanned === '') {
            return null;
        }

        $fields = [
            'drivers_license' => [$employee->drivers_license_number],
            'government_id' => [
                $employee->sss_number,
                $employee->philhealth_number,
                $employee->pagibig_number,
                $employee->tin,
            ],
        ];

        foreach ($fields as $type => $stored) {
            foreach ($stored as $value) {
                $value = $this->digitsAndLetters($value);

                if ($value !== '' && str_contains($scanned, $value)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * The number fits exactly one type's shape.
     *
     * Reads `type_defining_formats`, which is deliberately shorter than the
     * `number_formats` used to check a number once its type is known. A
     * pattern loose enough to be a fair check is not tight enough to be a
     * classifier: the passport shape swallowed a medical certificate numbered
     * "MC-2026-4471", which is two letters and eight digits and therefore a
     * perfect fit for a rule that was never meant to decide anything.
     *
     * "Exactly one" does the rest of the work — a number fitting two types
     * resolves nothing and must say so rather than pick.
     */
    private function typeFromNumberFormat(?string $printed): ?string
    {
        if ($printed === null) {
            return null;
        }

        $scanned = $this->digitsAndLetters($printed);

        if ($scanned === '') {
            return null;
        }

        $fits = [];

        foreach (config('scanner.type_defining_formats', []) as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $scanned) === 1) {
                    $fits[$type] = true;

                    break;
                }
            }
        }

        return count($fits) === 1 ? array_key_first($fits) : null;
    }

    /**
     * How long the document is valid for, in whole months.
     *
     * Only answers when exactly one type's range contains the gap. The ranges
     * in config overlap where the real documents do, so this identifies a
     * licence confidently and declines to separate a clearance from a medical
     * — which is the honest outcome, not a gap in the rule.
     */
    private function typeFromValidity(mixed $issued, mixed $expires): ?string
    {
        $from = $this->date($issued);
        $to = $this->date($expires);

        if ($from === null || $to === null) {
            return null;
        }

        $months = Carbon::parse($from)->diffInMonths(Carbon::parse($to));

        if ($months <= 0) {
            return null;
        }

        $fits = [];

        foreach (config('scanner.validity_months', []) as $type => [$low, $high]) {
            if ($months >= $low && $months <= $high) {
                $fits[] = $type;
            }
        }

        return count($fits) === 1 ? $fits[0] : null;
    }

    /**
     * How the document stands against today, in the terms Credentials uses.
     *
     * Three states, and the split is the module's own: `expired` is past its
     * date, `expiring` is inside the renewal window that
     * `credentials.warning_days` sets for that type, and `valid` is neither.
     * The windows differ on purpose — an LTO licence renewal wants sixty days
     * of lead time and a certificate does not.
     *
     * `blocking` marks the types whose lapse legally stops the employee
     * working. It is reported, never enforced here: an expired licence is
     * often filed deliberately, for the record or while the renewal is in
     * progress, and refusing the upload would leave the 201 file emptier than
     * the truth. Where it *does* stop someone is Deployment Readiness, which
     * is the screen that answers whether they can be sent out tomorrow.
     *
     * @return array{state: string, days: int, blocking: bool}|null
     */
    private function expiryStatus(?string $expiresAt, ?string $type): ?array
    {
        if ($expiresAt === null) {
            return null;
        }

        $days = (int) Carbon::today()->diffInDays(Carbon::parse($expiresAt), false);

        $window = config(
            "credentials.warning_days.{$type}",
            config('credentials.default_warning_days', 30),
        );

        return [
            'state' => match (true) {
                $days < 0 => 'expired',
                $days <= $window => 'expiring',
                default => 'valid',
            },
            'days' => $days,
            'blocking' => in_array($type, config('credentials.blocking_types', []), true),
        ];
    }

    /**
     * Whether this employee is named as a parent on the certificate.
     *
     * @param  array<string, mixed>  $registry
     */
    private function parentClaim(array $registry, ?Employee $employee): ?bool
    {
        if ($employee === null || ($registry['parents_uncertain'] ?? false)) {
            return null;
        }

        $parents = array_filter([$registry['mother'] ?? null, $registry['father'] ?? null]);

        if ($parents === []) {
            return null;
        }

        foreach ($parents as $parent) {
            if ($this->nameMatches($parent, $employee) === true) {
                return true;
            }
        }

        return false;
    }

    /** "Juan", "Ponce", "Dela Cruz" -> "Juan Ponce Dela Cruz". */
    private function joinName(mixed ...$parts): ?string
    {
        $name = trim(implode(' ', array_filter(array_map(
            fn ($part) => $this->text($part, 60),
            $parts,
        ))));

        return $name === '' ? null : $name;
    }

    private function registryPrompt(): string
    {
        return <<<'PROMPT'
        You read Philippine PSA civil registry documents — a Certificate of
        Live Birth, of Marriage, or a CENOMAR — and report what is printed on
        them. You are filling in a form a person will check.

        These forms are numbered. Read the value written **beside** each
        numbered label, never the label itself:

        - "Registry No." near the top right.
        - Item 1, NAME, printed in three boxes: First, Middle, Last. Report
          them separately, exactly as written. A Filipino surname is often two
          words ("Pikit Pikit", "Dela Cruz") — keep both.
        - Item 2, SEX.
        - Item 3, DATE OF BIRTH, printed as Day / Month / Year in that order.
          "02 July 2006" is the second of July.
        - Item 4, PLACE OF BIRTH — the hospital or clinic, and the city.
        - Item 6, MAIDEN NAME — this is the **mother**, and it is her name
          before marriage. Three boxes again: First, Middle, Last.
        - Item 13, NAME — this is the **father**. Three boxes.

        Rules that matter:
        - There are three people named on this page. Do not mix them up: item 1
          is the child, item 6 is the mother, item 13 is the father.
        - Report only what is legible. Never infer or complete a name, a number
          or a date that is not printed there.
        - If a field is absent or unreadable, return null. A null is correct
          and useful; a guess is not.
        - Dates must be ISO (YYYY-MM-DD).
        - **This document has no expiry date and no issue date.** Do not report
          one, and do not treat the date it was received or prepared by the
          registrar as either.
        PROMPT;
    }

    /** @return array<string, mixed> */
    private function registrySchema(): array
    {
        $string = ['type' => ['string', 'null']];

        $properties = [];

        foreach ([
            'registry_no',
            'child_first_name', 'child_middle_name', 'child_last_name',
            'child_birth_date', 'child_birth_place',
            'mother_first_name', 'mother_middle_name', 'mother_maiden_last_name',
            'father_first_name', 'father_middle_name', 'father_last_name',
        ] as $field) {
            $properties[$field] = $string;
        }

        $properties['child_sex'] = ['type' => ['string', 'null'], 'enum' => ['male', 'female', null]];

        return [
            'type' => 'json_schema',
            'schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => array_keys($properties),
                'additionalProperties' => false,
            ],
        ];
    }

    private function typeFromHeading(?string $printed): ?string
    {
        // A letterhead runs longer than the 120 the other fields are capped
        // at, and the keyword is often in the second line of it.
        $heading = mb_strtolower((string) $this->text($printed, 200));

        if ($heading === '') {
            return null;
        }

        foreach (config('scanner.title_keywords', []) as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($heading, $keyword)) {
                    // Still checked against the real list: a config typo must
                    // not smuggle in a type the database will not accept.
                    return in_array($type, EmployeeDocument::TYPES, true) ? $type : null;
                }
            }
        }

        return null;
    }

    /**
     * Whether this document carries no expiry date at all.
     *
     * Two sources, because the fact lives at two different grains and both are
     * real:
     *
     * - **By type**, from `type_cannot_have` — a résumé, a PSA certificate, a
     *   diploma, a transcript. Absolute for every document of that type.
     * - **By which card it is**, from `non_expiring_ids` — and this is the one
     *   the type list cannot express. `government_id` covers a TIN ID and a
     *   passport at once: the first is issued for life, the second expires and
     *   its expiry matters. So the card is identified from the heading printed
     *   on it, which is evidence from the paper rather than the model's guess.
     *
     * Answering this in one place is what lets the upload panel say **"Does
     * not expire"** rather than "Not found". The difference is not cosmetic:
     * "Not found" reads as the scanner having looked and missed, which invites
     * somebody to type a date that does not exist — and a date typed onto a
     * TIN ID puts it in `CredentialExpiryScanner`'s renewal queue to be chased
     * forever for a renewal that will never come.
     *
     * @param  array<string, mixed>  $raw
     */
    private function neverExpires(?string $type, array $raw): bool
    {
        if (in_array('expires_at', config("scanner.type_cannot_have.{$type}", []), true)) {
            return true;
        }

        /*
         * Scoped by the config's own key rather than by naming a type here,
         * and the scoping is load-bearing: an NBI clearance prints a "VALID
         * UNTIL" date and its letterhead names an agency, so a list read
         * against every type would eventually clear a real expiry off a real
         * credential. Only the type whose members disagree with each other is
         * asked the question.
         */
        $cards = config("scanner.non_expiring_ids.{$type}", []);

        if ($cards === []) {
            return false;
        }

        // The same 200-character read of the heading `typeFromHeading()` uses:
        // a letterhead runs longer than the other fields are capped at, and
        // the words that name the issuer are often on its second line.
        $heading = mb_strtolower((string) $this->text($raw['title'] ?? null, 200));

        if ($heading === '') {
            return false;
        }

        foreach ($cards as $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($heading, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Free text, flattened to one line and capped.
     *
     * These values land in single-line form inputs. A smaller model asked for
     * a document title will sometimes hand back the block of text it read the
     * title out of, newlines and all — and a newline pasted into an `<input>`
     * silently becomes a space, so a wall of OCR output arrives looking like
     * a deliberate answer. Trimming it here keeps the untrusted-input rule in
     * one place instead of asking every caller to defend itself.
     */
    private function text(mixed $value, int $limit = 120): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $limit
            ? rtrim(mb_substr($value, 0, $limit)).'…'
            : $value;
    }

    /**
     * The two dates, checked against each other before either is offered.
     *
     * A document that carries no dates at all is where this model lies most
     * readily: given a PhilSys card, which prints a birth date and nothing
     * else, it answered that same date as *both* the issue and the expiry.
     * An invented expiry is the exact failure this whole feature exists to
     * prevent — CredentialExpiryScanner would take it at face value.
     *
     * Two rules, both deterministic, both impossible to argue with:
     * nothing expires on the day it is issued, and nothing expires before it.
     * When the pair fails either, neither date is offered: there is no way to
     * tell which of the two was misread, and half a wrong answer is still a
     * wrong answer. HR types the dates, as they did before this existed.
     *
     * @return array{issued_at: string|null, expires_at: string|null}
     */
    private function dates(mixed $issued, mixed $expires): array
    {
        $from = $this->date($issued);
        $to = $this->date($expires);

        if ($from !== null && $to !== null && $to <= $from) {
            return ['issued_at' => null, 'expires_at' => null];
        }

        return ['issued_at' => $from, 'expires_at' => $to];
    }

    /** Re-parsed rather than trusted: a malformed date becomes null, not a crash. */
    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether the number printed on the document is one already on this
     * employee's 201 file.
     *
     * This is what "is it really their ID" can honestly mean here. Whether a
     * card is *authentic* cannot be answered from a photograph — that needs
     * the issuing agency, and there is no public LTO, PSA, or NBI lookup to
     * ask. Whether it is *theirs* can be, and a recorded number answers it
     * better than any reading of the name.
     *
     * A licence is matched against the licence number on file. A government ID
     * is matched against every government number on file, because one type
     * covers PhilSys, SSS, PhilHealth, Pag-IBIG, and the TIN, and the document
     * itself does not say which it is.
     *
     * @return bool|null null when nothing is recorded to compare against
     */
    private function numberMatches(mixed $printed, ?string $type, ?Employee $employee): ?bool
    {
        if (! is_string($printed) || ! $employee || $type === null) {
            return null;
        }

        $candidates = match ($type) {
            'drivers_license' => [$employee->drivers_license_number],
            'government_id' => [
                $employee->sss_number,
                $employee->philhealth_number,
                $employee->pagibig_number,
                $employee->tin,
            ],
            default => [],
        };

        $candidates = array_values(array_filter(
            array_map(fn ($value) => $this->digitsAndLetters($value), $candidates),
        ));

        if ($candidates === []) {
            return null;
        }

        $scanned = $this->digitsAndLetters($printed);

        if ($scanned === '') {
            return null;
        }

        /*
         * Containment, not equality.
         *
         * The model transcribes the caption along with the value often enough
         * to matter — a real scan of an NBI clearance came back as
         * "NBI ID NO.: N2G4-25-123456" rather than the number alone. Under an
         * exact comparison that reads as a contradiction, and a contradicted
         * number *blocks the upload*: the correct document, correctly read,
         * refused because of the words printed beside the number.
         *
         * A false negative here is the expensive direction. The stored numbers
         * are nine to sixteen characters, so one appearing inside a scan of
         * the same card by coincidence is not a real risk.
         */
        foreach ($candidates as $candidate) {
            if (str_contains($scanned, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the printed number fits any known shape for its type.
     *
     * Deliberately advisory. Agencies revise their formats, an employee may
     * carry a card issued under an older one, and OCR drops a digit often
     * enough that refusing on shape would reject genuine documents. What it
     * buys is a signal on the *first* document scanned for someone, when
     * there is nothing on file to compare a number against and the name is
     * the only other evidence there is.
     *
     * @return bool|null null when no pattern is configured for the type
     */
    private function numberFormatLooksRight(mixed $printed, ?string $type): ?bool
    {
        $patterns = config("scanner.number_formats.{$type}", []);

        if ($patterns === [] || ! is_string($printed)) {
            return null;
        }

        $normalised = $this->digitsAndLetters($printed);

        if ($normalised === '') {
            return null;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalised) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strips everything an ID number is punctuated with.
     *
     * `N01-23-456789`, `N01 23 456789`, and `n0123456789` are the same number
     * written three ways, and which one appears depends on the card and on how
     * whoever typed it into the 201 file felt that day.
     */
    /**
     * The number, without the caption printed beside it.
     *
     * A document labels its number ("LICENSE NO.:", "NBI ID NO.:") and the
     * model reads the label as readily as the value. HR sees this string on
     * the form, so it should be the number rather than a transcription of the
     * line it sat on. Everything after the last colon is kept; a string with
     * no colon is left alone, since not every card captions its number.
     */
    private function documentNumber(mixed $value): ?string
    {
        $text = $this->text($value);

        if ($text === null) {
            return null;
        }

        if (str_contains($text, ':')) {
            $tail = trim(mb_substr($text, mb_strrpos($text, ':') + 1));

            // Only when something is actually left — a number that genuinely
            // ends in a colon must not be trimmed away to nothing.
            $text = $tail !== '' ? $tail : $text;
        }

        return $this->looksLikeANumber($text) ? $text : null;
    }

    /**
     * Whether this is plausibly an ID number rather than another line of the
     * document.
     *
     * A small model asked for "the number" will sometimes hand back whatever
     * line looked most like one. A real scan returned
     * "012 A-345, SAMPLE STREET, MANILA" — the holder's address.
     *
     * Dropping it matters more than it looks. `numberMatches()` compares the
     * scanned number against the 201 file, and a *contradiction* blocks the
     * upload; an address can never match, so keeping it would refuse the
     * employee's own ID. Returning null instead makes the answer "unknown",
     * which blocks nothing and leaves the name check to decide. Garbage should
     * degrade to no evidence, never to evidence against.
     */
    private function looksLikeANumber(string $text): bool
    {
        // Addresses and name lists are comma-separated; ID numbers are not.
        if (str_contains($text, ',')) {
            return false;
        }

        $alnum = $this->digitsAndLetters($text);
        $digits = preg_match_all('/\d/', $alnum);

        // Long enough to identify somebody, short enough to be a number.
        if (mb_strlen($alnum) < 5 || mb_strlen($alnum) > 32) {
            return false;
        }

        /*
         * Mostly digits. Every ID this system files is: an LTO licence is one
         * letter and ten digits, SSS and PhilHealth are digits throughout, and
         * a passport is at most two letters. A street address is mostly words,
         * and this is the line that separates them.
         */
        return $digits / mb_strlen($alnum) >= 0.4;
    }

    private function digitsAndLetters(?string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $value)) ?? '';
    }

    /**
     * A name split into comparable words.
     *
     * Initials and one-letter fragments are dropped: "P." in "John Gave P.
     * Benavidez" carries no evidence either way, and counting it as an
     * unmatched token would drag a good score down for nothing.
     *
     * @return array<int, string>
     */
    private function nameTokens(?string $value): array
    {
        $words = preg_split('/[^a-z]+/', mb_strtolower((string) $value), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($words ?: [], fn (string $word) => mb_strlen($word) > 1));
    }

    /**
     * Whether two name words are the same word, allowing for a misread letter.
     *
     * The tolerance scales with length rather than being fixed: one edit in
     * "jonn" is a plausible OCR slip, while one edit in a three-letter word
     * would let "ana" match "ann" and "any", which is most of a given name
     * column.
     */
    private function tokensAlike(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        // A short word has to be exact; there is not enough of it to be sure
        // an edit is a misreading rather than a different name.
        $shortest = min(mb_strlen($a), mb_strlen($b));

        if ($shortest < 4) {
            return false;
        }

        return levenshtein($a, $b) <= ($shortest >= 7 ? 2 : 1);
    }

    private function client(): Client
    {
        return $this->client ?? new Client(apiKey: config('scanner.api_key'));
    }

    /**
     * Validated DL restriction codes from a driver's licence.
     *
     * Philippine LTO licences print vehicle-category codes on the card.
     * The old system used numbers 1–8; the current one follows the Vienna
     * Convention: A, A1, B, B1, B2, BE, C, CE, D, DE. Both are accepted
     * because older cards still circulate.
     *
     * Codes that are not on the known list are dropped — a hallucinated
     * code must never decide whether a driver is qualified for a vehicle.
     *
     * @return array<int, string>|null null when nothing valid was read
     */
    private function parseDlCodes(mixed $value): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        // Known Philippine DL codes — both old restriction numbers and
        // current Vienna Convention categories.
        $known = [
            'a', 'a1', 'b', 'b1', 'b2', 'be', 'c', 'ce', 'd', 'de',
            '1', '2', '3', '4', '5', '6', '7', '8',
        ];

        $codes = array_filter(
            array_map(
                fn (string $code) => strtoupper(trim($code)),
                preg_split('/[,;\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            ),
            fn (string $code) => in_array(strtolower($code), $known, true),
        );

        return $codes !== [] ? array_values($codes) : null;
    }
}
