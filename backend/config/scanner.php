<?php

/*
|--------------------------------------------------------------------------
| AI document scanner
|--------------------------------------------------------------------------
| Reads an uploaded 201-file document and proposes the fields HR would
| otherwise type by hand. Config-driven like the rest of the system, so a new
| document type is a config edit rather than a code change.
|
| The scanner never writes to the database — it fills a form the human
| confirms. See App\Services\DocumentScanner.
*/

return [

    /*
    | Which model reads the document.
    |
    | `gemini` is the default, and every driver here is hosted. There used to
    | be a local one — Ollama, running glm-ocr on the machine — and it was the
    | default precisely because the image never left the host: a 201-file scan
    | is a photograph of somebody's PhilSys ID, NBI clearance, or licence, and
    | sending that to a third-party API is a cross-border transfer of personal
    | data under RA 10173.
    |
    | It was removed because it could not run where the system runs. Ollama has
    | to be installed on whatever serves the app and a small VPS cannot hold
    | even a 2.2 GB vision model, so the *default* driver was one that goes
    | dark on every deployment — a fresh clone shipped pointing at a feature
    | that could not work. A local option nobody can deploy is not a privacy
    | control; it is a privacy control that is switched off in production,
    | which is the only place it would have mattered.
    |
    | **So the RA 10173 obligation is now unavoidable rather than avoided, and
    | it has to be met rather than designed around.** Every scan leaves the
    | country. That needs disclosure to the employee and consent on file
    | before a real deployment reads a real 201 file — it is not something a
    | config value can satisfy.
    */
    'driver' => env('SCANNER_DRIVER', 'gemini'),

    /*
    | The default, and the one to prefer.
    |
    | Free at the tier this project runs on, reachable from anywhere, and — the
    | part that matters once every driver is hosted — a **single named
    | processor**. OpenRouter is a broker, so the same image reaches two.
    |
    | The free tier is about 20 scans a day per model, measured from Google's
    | own 429 body rather than read off a page. Enough to demonstrate the
    | feature; not enough to run an HR department on. Past it a scan returns
    | nothing and HR types the fields, which is what every other failure here
    | collapses to as well.
    */
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),

        /*
        | The API *base*. DocumentScanner::geminiEndpoint() appends
        | `/models/{model}:generateContent`, because Gemini names the model in
        | the URL rather than in the request body.
        */
        'endpoint' => env(
            'GEMINI_ENDPOINT',
            'https://generativelanguage.googleapis.com/v1beta',
        ),

        /*
        | Measured rather than chosen: on the day this was set,
        | `gemini-3.5-flash` answered 503 UNAVAILABLE on every attempt while
        | this one answered in 1.8s on the same key. Both exist and both take
        | images — the difference was load, and load is not something a
        | default can be right about for long, which is what the chain below
        | is for.
        */
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),

        /*
        | Tried in order when the model above is busy.
        |
        | **This is the fix for the failure the retries could not fix.** The
        | free tier shares one pool per model, so a congested model answers
        | 503 to every attempt — retrying it four times is four ways of
        | hearing the same no, and that is exactly how the scanner went dark:
        | "attempts_exhausted" in the log, a dead Scan button on the form, and
        | a key and model that were both perfectly valid.
        |
        | A *different* model is a different pool. Only tried on 429/5xx and a
        | connection failure: a 400 (rejected schema) or a 401 (bad key) is an
        | answer, and asking a second model the same rejected question wastes
        | the person's time arriving at the same place.
        |
        | Every entry must take an image and honour `responseJsonSchema`, or
        | the fallback is a slower way to fail. `php artisan scanner:check`
        | reports which one answered.
        |
        | **The chain also raises the daily ceiling, which is the larger half
        | of what it buys.** The free tier is 20 requests a day and 5 a minute
        | counted *per model* — Google's 429 names both quotas and carries the
        | model in `quotaDimensions` — and a 429 is treated as "move on" here
        | exactly like a 503. Six separate pools is therefore up to ~120 scans
        | a day rather than 20.
        |
        | **Every entry was tested against the request this driver really
        | sends** — a real image plus `responseJsonSchema` — and read a test
        | licence correctly. Being in `ListModels` is not enough and has
        | already misled twice: `gemini-2.5-flash` answers 404 "no longer
        | available to new users", and `gemini-3.5-flash-lite` and
        | `gemini-flash-lite-latest` answer 400 even for a one-word text
        | request.
        |
        | **Two models are left out because they are aliases of entries
        | already here**, and an alias shares the pool rather than adding one:
        | `gemini-3.8-flash` is what `gemini-flash-latest` resolves to, and
        | `gemini-3.1-flash-lite-preview` resolves to `gemini-3.1-flash-lite`.
        | Both were confirmed by reading `modelVersion` off a real answer.
        | `geminiModels()` de-duplicates by *name*, so it cannot see either —
        | listing one would quietly spend a slot on nothing.
        |
        | **`gemini-3.1-flash-lite` sits last, deliberately.** It answers on a
        | separate quota from the full "flash" line above, so it is genuine
        | extra headroom rather than a fourth attempt at the same congested
        | pool — but "lite" is a smaller model, so it is the *last* resort
        | rather than a peer: a full flash model reads a photographed ID
        | better than a lite one, and this chain should only reach for the
        | smaller reading after every full model has already said no. Its own
        | daily cap is deliberately unmeasured, because reading it means
        | spending the one model kept in reserve.
        */
        'fallback_models' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'GEMINI_FALLBACK_MODELS',
                'gemini-3.7-flash,gemini-3.5-flash,gemini-flash-latest,gemini-3-flash-preview,gemini-3.1-flash-lite',
            )),
        ))),

        // Nothing to load into VRAM here, so the long cold-start allowance
        // Ollama needs does not apply — but a large scan still has to upload.
        /*
        | How many times to wait out a busy model.
        |
        | The free tier shares one pool, and a heavy request — an image plus a
        | schema — comes back `UNAVAILABLE` under load often enough that a
        | single attempt is a coin toss. Only 429 and 5xx are retried; a wrong
        | key fails on the first try, as it should.
        */
        /*
        | Two attempts per model, not four.
        |
        | It was three retries against one model, which spent 22 seconds
        | discovering that a busy model is busy. The recovery is the fallback
        | list above — a second pool rather than a fourth knock on the same
        | door — so each model gets one retry and the chain moves on.
        */
        'retries' => (int) env('GEMINI_RETRIES', 2),
        'retry_delay_ms' => (int) env('GEMINI_RETRY_DELAY_MS', 1500),

        'timeout' => (int) env('GEMINI_TIMEOUT', 60),
    ],

    /*
    | The broker driver.
    |
    | OpenRouter is OpenAI-shaped, which makes it the cheapest of the four to
    | speak to — and it is the only one where a single key reaches many
    | models, so switching model is an env edit rather than a new driver.
    |
    | **It is a broker, not a provider, and that is the thing to understand
    | before choosing it.** OpenRouter does not run the model. The image goes
    | to OpenRouter, and OpenRouter forwards it to whichever upstream is
    | serving that model at that moment. A 201-file scan is a photograph of
    | somebody's PhilSys ID or NBI clearance, so under RA 10173 this is a
    | cross-border transfer to **two** processors rather than one — and which
    | the second is can change without anything here changing. Gemini is a
    | single named processor; Ollama is none at all. That is the axis this
    | driver is worst on, and it is not the axis it is chosen for.
    |
    | `data_collection: deny` asks OpenRouter to route only to upstreams that
    | do not train on or retain the prompt. It is sent on every request rather
    | than left to the dashboard, so the guarantee travels with the code that
    | relies on it.
    */
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),

        'endpoint' => env(
            'OPENROUTER_ENDPOINT',
            'https://openrouter.ai/api/v1/chat/completions',
        ),

        /*
        | The model has to do two things, and a model that does neither fails
        | *quietly*: it answers prose, normalise() finds nothing, and the form
        | is simply blank.
        |
        |   1. read an image — this driver sends one
        |   2. honour `response_format: json_schema`
        |
        | Not every model on OpenRouter does both, and the catalogue moves. Run
        | `php artisan scanner:check` after changing this: one real image
        | through the real driver is the only thing that answers the question.
        */
        'model' => env('OPENROUTER_MODEL', 'google/gemini-2.5-flash'),

        /*
        | OpenRouter's own conventions. They identify the calling app on the
        | dashboard and in rankings; neither is required, and a wrong value is
        | not an error — but a key with no attribution is a key nobody can
        | trace back to this system when it starts costing money.
        */
        'referer' => env('OPENROUTER_REFERER', env('APP_URL')),
        'title' => env('OPENROUTER_TITLE', 'PrimePower HRIS'),

        // Same shape as the Gemini driver: a broker sitting in front of a
        // shared pool answers 429 for the same reason, so it is waited out
        // rather than given up on.
        'retries' => (int) env('OPENROUTER_RETRIES', 3),
        'retry_delay_ms' => (int) env('OPENROUTER_RETRY_DELAY_MS', 1500),

        'timeout' => (int) env('OPENROUTER_TIMEOUT', 60),
    ],

    /*
    | Only read when driver = anthropic. Without a key that driver stays dark:
    | the Scan button is not rendered and the endpoint 404s. Uploading by hand
    | keeps working exactly as before either way.
    */
    'api_key' => env('ANTHROPIC_API_KEY'),

    'model' => env('SCANNER_MODEL', 'claude-opus-5'),

    /*
    | Images and PDFs. A DOCX upload still skips the scanner — the document
    | uploads, HR just types the fields.
    |
    | Gemini accepts `application/pdf` natively, so a PDF goes straight
    | through without conversion. For the Anthropic and OpenRouter drivers
    | (image-only endpoints), the first page is rendered to a JPEG via
    | Imagick before being sent — see DocumentScanner::pdfToImage().
    */
    'accepts' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'],

    /*
    | 5 MB of raw image is roughly 6.7 MB base64-encoded, well inside the
    | request limit. A multi-page PDF can be larger, but is capped at
    | `max_pages` pages and the converted image stays under this size.
    */
    'max_bytes' => 10 * 1024 * 1024,

    /*
    | PDF handling. Only the first page is read — a 201-file document is
    | one page, and a multi-page employment contract carries the fields
    | (name, dates, position) on its first. Sending more pages costs more
    | tokens for no extra signal on the kinds of paper this system files.
    */
    'pdf' => [
        'max_pages' => (int) env('SCANNER_PDF_MAX_PAGES', 2),
    ],

    /*
    | What each type looks like, in the model's own terms. Keys must match
    | EmployeeDocument::TYPES exactly — the scanner's answer is validated
    | against that list before it reaches the form, so a hallucinated type
    | is rejected rather than shown.
    */
    /*
    | The default title for each type, used in preference to the one the model
    | writes.
    |
    | The type is validated against EmployeeDocument::TYPES before it is
    | trusted; the free-text title is validated against nothing, so between
    | the two the derived label is the sounder source. It also holds the
    | naming steady across a 201 file — twenty licences all titled "Driver's
    | Licence" sort and read better than twenty variations. HR can still type
    | over it, which is where any real specificity belongs.
    */
    /*
    | What a document's own printed title implies about its type, checked in
    | PHP against the free text the model transcribed.
    |
    | This exists because the small model is measurably better at *reading* a
    | heading than at picking the matching key out of a list. On an NBI
    | clearance it wrote title "NBI Clearance" — correct — while answering
    | type "drivers_license". The heading is evidence from the document; the
    | enum is the model's judgement about it, and the two are not equally
    | trustworthy.
    |
    | Matched in order, first hit wins, so put the specific before the
    | general — "driver's licence" has to be tested before a bare "licence".
    */
    /*
    | Matched against the whole heading, in this order, first hit winning.
    |
    | **The issuing authority is the strongest thing printed on a Philippine
    | document, and it is printed in full.** The list began as abbreviations —
    | "nbi", "lto" — and missed a real NBI clearance whose letterhead reads
    | "REPUBLIC OF THE PHILIPPINES / Department of Justice / National Bureau of
    | Investigation". The word "NBI" appears nowhere on it. Agencies write
    | themselves out; the shorthand is what people say, not what they print.
    |
    | Order still matters for the overlaps: "certificate" would swallow a PSA
    | birth certificate, so `psa` is tested first.
    */
    'title_keywords' => [
        'clearance' => [
            'nbi', 'clearance', 'police', 'barangay',
            // Spelled out, which is how the letterhead actually reads. "bureau
            // of investigation" rather than the full name so a dropped or
            // misread "National" does not lose the match — OCR mangles the
            // long line often enough to matter.
            'bureau of investigation', 'department of justice',
            'philippine national police', 'punong barangay',
            'nbi seal', 'dry seal', 'official seal', 'security seal', 'national bureau of investigation seal',
        ],
        'drivers_license' => [
            'driver', 'licence', 'license', 'lto',
            'land transportation office', 'land transportation',
        ],
        // "patient" is a genuine signal, not a fitting to one sample: nothing
        // else in a 201 file addresses the holder as a patient.
        'medical' => [
            'medical', 'fit to work', 'fit-to-work', 'health', 'patient',
            'department of health', 'diagnostic', 'laboratory',
        ],
        'contract' => ['contract', 'appointment', 'job offer'],
        /*
         * Before `certificate` on purpose — first hit wins, and "PSA Birth
         * Certificate" contains the word "certificate". Reversed, every PSA
         * document would be filed as a training certificate.
         */
        'psa' => [
            'psa', 'philippine statistics authority', 'birth certificate',
            'certificate of live birth', 'marriage certificate',
            'certificate of marriage', 'cenomar', 'no marriage record',
            'civil registry', 'nso',
            'philippine statistics authority', 'national statistics office',
            'civil registrar', 'office of the civil registrar',
        ],
        /*
         * Ahead of `certificate`, which used to swallow both — a diploma
         * headed "Diploma" was filed as a training card and put into a
         * renewal queue for a qualification that does not lapse.
         */
        'diploma' => ['diploma', 'katibayan', 'has satisfactorily completed the requirements'],
        'transcript' => [
            'transcript of records', 'transcript', 'official transcript', 'scholastic record',
        ],
        'certificate' => ['certificate', 'tesda', 'training'],
        'resume' => ['resume', 'résumé', 'curriculum vitae', 'biodata'],
        // Filipino field labels are the tell: PhilID and the passport caption
        // their fields in Filipino, and no other 201-file document does.
        'government_id' => [
            'philsys', 'philid', 'umid', 'passport', 'postal id', 'voter',
            // The PhilID prints its captions in Filipino, and no other 201-file
            // document does — which is what makes these safe as a heading test.
            'apelyido', 'pangalan', 'republika',
            // Issuing bodies, as they appear across the top of the card. A
            // heading is the one place these words are unambiguous: "Social
            // Security System" printed as a title is an SSS card, while the
            // same words inside a field are just a label.
            'philippine identification', 'pambansang', 'national id',
            'social security system', 'unified multi-purpose',
            'professional regulation', 'integrated bar',
            'philippine health insurance', 'philhealth',
            'home development mutual fund', 'pag-ibig',
            'bureau of internal revenue', 'department of foreign affairs',
            'commission on elections', 'philippine postal',
            // TIN ID — the card carries the BIR seal prominently at the upper
            // right, and the heading reads "BUREAU OF INTERNAL REVENUE" in full.
            // "taxpayer identification" appears as a label beside the number;
            // "tin id" is what people call it, though the card itself says
            // "TAXPAYER IDENTIFICATION NUMBER".
            'taxpayer identification', 'tin id', 'tin card',
            'bir seal', 'bir official seal', 'department of finance',
            'digital tin id', 'tin id control number',
        ],
    ],

    /*
    | What an ID number for each type should look like, once punctuation is
    | stripped and it is lower-cased.
    |
    | This is the check that reads the *document* rather than the name on it:
    | an LTO licence number is a letter and ten digits, so "ABC123" is either a
    | misread or not a licence at all. Several patterns per type because one
    | type can cover several cards — `government_id` is PhilSys, SSS,
    | PhilHealth, Pag-IBIG and the TIN at once.
    |
    | **Reported, never blocking.** Agencies change their formats, an employee
    | may hold an older card issued under a previous one, and OCR drops a digit
    | often enough that refusing on shape alone would reject real documents.
    | It is a reason to look, not a reason to stop — the number *matching the
    | 201 file* is what carries weight, and that has its own check.
    */
    'number_formats' => [
        // N01-23-456789 -> n0123456789
        'drivers_license' => ['/^[a-z]\d{10}$/'],

        'government_id' => [
            '/^\d{16}$/',   // PhilSys PSN
            '/^\d{10}$/',   // SSS
            '/^\d{12}$/',   // PhilHealth, Pag-IBIG
            '/^\d{9}$/',    // TIN, without the branch code
            '/^\d{12}$/',   // TIN with it
            '/^[a-z]{1,2}\d{6,9}$/', // passport
        ],
    ],

    /*
    | How long each kind of document stays valid, in months, as a range.
    |
    | The gap between a document's issue date and its expiry is evidence about
    | what the document *is*, and it is evidence the model cannot fake: it
    | comes from two dates it transcribed separately. An LTO licence runs five
    | or ten years and nothing else in a 201 file does, so a sixty-month gap
    | identifies a licence on its own — which matters, because a licence and a
    | clearance are the two types this model confuses most.
    |
    | The ranges deliberately overlap where reality overlaps: an NBI clearance
    | is a year, a police or barangay clearance six months, a medical six to
    | twelve. Between those three the gap decides nothing, and the code only
    | uses this signal when exactly one type fits. A range that had to be made
    | artificially narrow to force an answer would be inventing certainty.
    |
    | Wide bounds on purpose. A document issued a fortnight before its stated
    | start, or renewed early, must not stop being recognisable.
    */
    'validity_months' => [
        // Five years, or ten for a clean record. Nothing else runs this long.
        'drivers_license' => [48, 132],

        // NBI is a year; police and barangay are commonly six months.
        'clearance' => [5, 15],

        // Pre-employment and fit-to-work results, six months to a year.
        'medical' => [3, 14],
    ],

    /*
    | The number formats that identify a type on their own.
    |
    | A deliberately shorter list than `number_formats`, and the split is the
    | point: a pattern can be good enough to *check* a number once the type is
    | known and nowhere near good enough to *decide* the type.
    |
    | The passport shape `[a-z]{1,2}\d{6,9}` is the case that made this
    | necessary. It is a fine check on a document already filed as a government
    | ID, and as a type rule it swallowed a medical certificate numbered
    | "MC-2026-4471" — two letters and eight digits, which is exactly what it
    | asks for. Bare digit counts fail the same way: a certificate number can
    | be ten digits and mean nothing of the sort.
    |
    | What is left is what is genuinely unmistakable in a 201 file: an LTO
    | licence is one letter and exactly ten digits, and a PhilSys PSN is
    | exactly sixteen. Nothing else here is either.
    */
    'type_defining_formats' => [
        'drivers_license' => ['/^[a-z]\d{10}$/'],
        'government_id' => ['/^\d{16}$/'],
    ],
    /*
    | What a document of each type cannot carry.
    |
    | Negative evidence, and it is the signal that catches the model's guess
    | being wrong when every positive signal has already declined. A résumé
    | does not have an ID number and does not expire; a PSA civil registry
    | document does not expire either. So a reading that says "resume" while
    | reporting a printed number has contradicted itself, and the honest answer
    | is that the type is unknown — not some other guess.
    |
    | Deliberately only the two types where the claim is absolute. An
    | employment contract *does* carry an end date, and a training certificate
    | may or may not carry a number, so neither can be ruled out this way. A
    | rule that is right most of the time is not usable here: it would discard
    | correct readings to catch incorrect ones.
    */
    /*
    | Government IDs that carry no expiry date, named one by one.
    |
    | `type_cannot_have` below states the fact per *type*, and that is the one
    | place it cannot reach: `government_id` is a mixed bag. A TIN ID, a UMID,
    | an adult PhilID and a voter ID are issued for life and print no expiry
    | anywhere on the card; a **passport and a postal ID do expire**, and a
    | passport's expiry is the kind of thing somebody is turned back at an
    | airport over. So `'government_id' => ['expires_at']` would be wrong in
    | the expensive direction — it would silently throw away a correctly read
    | passport expiry — and leaving it out is what let a TIN ID be given one.
    |
    | Matched against the **heading the model transcribed**, which is evidence
    | printed on the paper rather than the model's own judgement. That is the
    | same source `title_keywords` ranks second of five, and the reason this
    | list can be trusted to clear a field: a card that says BUREAU OF INTERNAL
    | REVENUE across the top is a TIN ID, and a TIN is permanent.
    |
    | This **clears the date; it never rejects the type.** A hallucinated
    | expiry on a TIN ID is the model answering a question the card does not
    | have — exactly the PSA failure — and it is not a reason to doubt that the
    | paper is a government ID. Evidence from the document is not second-
    | guessed by the model having added something to it.
    |
    | The cost of a wrong entry here is an expiry quietly dropped, so a card is
    | listed only where the "no expiry" claim is absolute. Anything that
    | renews — passport, postal ID, PRC licence, driver's licence — stays off
    | it deliberately.
    */
    'non_expiring_ids' => [
        /*
         * Keyed by the type the rule applies to, so the config says where it
         * is allowed to act rather than the code naming one type inline. Only
         * `government_id` is here, because it is the only type whose members
         * disagree with each other about expiring.
         */
        'government_id' => [
            // The card itself says TAXPAYER IDENTIFICATION NUMBER; the heading
            // reads BUREAU OF INTERNAL REVENUE in full.
            'tin' => [
                'bureau of internal revenue', 'taxpayer identification',
                'tin id', 'tin card', 'digital tin id',
            ],
            // UMID — the SSS/GSIS/PhilHealth/Pag-IBIG common card.
            'umid' => ['unified multi-purpose', 'umid', 'social security system'],
            /*
             * The adult PhilID prints no expiry. The child versions do — under
             * 5 is valid a year, 5–14 until the holder turns 15 — and the card
             * says so when it applies, which is the one case this list would
             * wrongly swallow. Kept anyway: a 201 file holds the employee's
             * own ID, and an employee is an adult.
             */
            /*
             * "national id" is here because the model **condenses the
             * heading** rather than transcribing the letterhead: a real scan
             * came back with `heading` of just "TIN ID" where the card prints
             * three lines of agency name. So the short name people actually
             * use has to be on the list beside the formal one, or the rule
             * misses the card it was written for. Safe to include — nothing
             * that expires is called a national ID.
             */
            'philsys' => [
                'philippine identification', 'philsys', 'national id',
                'pambansang pagkakakilanlan',
            ],
            'voter' => ['commission on elections', 'comelec', 'voter'],
        ],
    ],

    'type_cannot_have' => [
        'resume' => ['document_number', 'expires_at'],
        'psa' => ['expires_at'],
        // A degree is not withdrawn after five years, and neither is a
        // transcript. Kept here so a misread date is cleared rather than
        // filed, which is what would otherwise put a diploma into
        // CredentialExpiryScanner's renewal queue for good.
        'diploma' => ['expires_at'],
        'transcript' => ['expires_at'],
    ],

    /*
    | Types where the name on the paper is *expected* to be somebody else.
    |
    | The name check refuses an upload whose document names a different person,
    | because filing under the wrong employee is an error nobody afterwards
    | goes looking for. A PSA birth certificate breaks that rule honestly: the
    | one filed in a 201 file is usually the employee's **child's**, kept for
    | BIR and PhilHealth dependant claims, and it names the child.
    |
    | So for these the check still runs and still reports — HR is told whose
    | name is on the paper — but it does not refuse. The alternative was the
    | PDF escape hatch, which works and which nobody would ever discover.
    */
    'names_may_differ' => ['psa'],

    /*
    |--------------------------------------------------------------------------
    | Filing without a person
    |--------------------------------------------------------------------------
    |
    | The batch filer may file a document nobody looked at — but only when
    | every check it has agrees, and every gate below is a real failure it has
    | already seen rather than a number picked to make the demo work.
    |
    | The rule the whole scanner is built on does not change: a wrong reading
    | must never become a fact quietly. What changes is where the person is
    | spent. Today they retype forty documents to catch the two that are
    | wrong; here the system files the thirty-eight it can defend and hands
    | back the two, which is the same safety with the effort put where the
    | risk is.
    |
    | Every one of these is *held*, not refused: a held document goes to the
    | review table the batch filer has always had, and is filed by hand
    | exactly as before. Nothing is ever discarded for failing a gate.
    |
    | Switch `enabled` off and the batch filer behaves exactly as it did —
    | propose everything, file nothing until somebody confirms.
    */
    /*
    |--------------------------------------------------------------------------
    | Learning from HR's corrections
    |--------------------------------------------------------------------------
    |
    | The scanner keeps every proposal and what the document was actually
    | filed as, and `ScannerCorrectionMemory` turns those into heading -> type
    | rules the classifier reads on the next scan. No model is retrained and
    | nothing extra is sent to the provider: the rules are applied in PHP.
    |
    | `min_confirmations` is why two people filing the same paper differently
    | cannot teach the system anything — a heading needs the same answer this
    | many times, and one disagreement removes it entirely.
    |
    */
    'learning' => [
        'enabled' => (bool) env('SCANNER_LEARNING', true),
        'min_confirmations' => 2,
        'lookback_days' => 365,
        'max_rules' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Measuring the scanner
    |--------------------------------------------------------------------------
    |
    | The window Scanner Accuracy reports over when nobody has picked one, and
    | the same window the dashboard's summary card reads.
    |
    | It lives here rather than in either of them because **two copies of it
    | would disagree the first time one was tuned** — and disagree silently, in
    | the worst way: a card reading 87% beside a screen reading 81% for the
    | same scanner, with nothing on either saying they were measured over
    | different months. It is the same reasoning `idle.timeout` is shared from
    | one config value rather than restated in the component that counts down.
    |
    | Ninety days rather than the month the other screens default to, because
    | this is a measurement and a measurement wants a sample: a month of a
    | small agency's uploads is a handful of scans, and a rate over a handful
    | is noise being reported as a finding.
    |
    */
    'accuracy' => [
        'default_days' => 90,
    ],

    'autofile' => [
        'enabled' => (bool) env('SCANNER_AUTOFILE', true),

        /*
         * How the owner was found. `number` is a value already on this
         * employee's 201 file — the only evidence in the whole reading with a
         * person behind it rather than a model. `name` is one exact match
         * against the scoped roster and no other: the filer already refuses
         * two, because taking the first would file the document under a coin
         * toss.
         *
         * Dropping `name` here is the one-line way to make this stricter, and
         * it is the first thing to try if a real batch ever files something
         * wrong.
         */
        'match_strengths' => ['number', 'name'],

        /*
         * Hold a row whose reading argues with itself — a future issue date,
         * an expiry before the issue, an underage clearance, a number already
         * filed under another document.
         *
         * These are warnings on the upload form, where somebody reads them and
         * decides. Unattended there is nobody to read them, and filing a
         * reading that contradicts itself stores the contradiction as a fact.
         */
        'hold_anomalies' => (bool) env('SCANNER_AUTOFILE_HOLD_ANOMALIES', true),

        /*
         * Hold a government ID whose number is not the shape its agency
         * prints. Same reasoning: on the form it is a warning a person
         * overrides for real numbers that fail a format rule, and with nobody
         * looking it is as likely to be a misread digit.
         */
        'hold_failed_id_check' => (bool) env('SCANNER_AUTOFILE_HOLD_FAILED_ID', true),

        /*
         * The type must be *certain* — settled by a number already on the 201
         * file, or by the document's own printed heading. The three weaker
         * sources are exactly the ones measured wrong: a number's shape is
         * shared between cards (a medical certificate numbered "MC-2026-4471"
         * fits the passport pattern), a validity period overlaps between
         * types, and the model's own key was wrong 4/4 on an NBI clearance
         * whose heading it had transcribed correctly every time.
         */
        'require_certain_type' => true,

        /*
         * A document whose type expires must have brought a date with it.
         * `CredentialExpiryScanner` reads `expires_at`, so a licence filed
         * with a null date is a licence that never appears in a renewal queue
         * — invisible rather than wrong, which is worse.
         */
        'require_expiry_for_expiring_types' => true,

        /*
         * Already lapsed is held. Filing an expired document is legitimate and
         * happens often — for the record, or mid-renewal — which is why the
         * upload form reports it rather than refusing it. But it is never the
         * thing to do silently: somebody should see that what just went into
         * the file cannot be used.
         */
        'hold_expired' => true,
    ],

    'labels' => [
        'drivers_license' => "Driver's Licence",
        'government_id' => 'Government ID',
        'clearance' => 'Clearance',
        'medical' => 'Medical Certificate',
        'contract' => 'Employment Contract',
        'psa' => 'PSA Certificate',
        'certificate' => 'Certificate',
        'diploma' => 'Diploma',
        'transcript' => 'Transcript of Records',
        'resume' => 'Résumé',
        'other' => 'Document',
    ],

    'document_types' => [
        'drivers_license' => "LTO driver's licence (professional or non-professional). Has an Expiration Date and a License No.",
        'government_id' => 'A government-issued ID: PhilSys National ID (PhilID), UMID, passport, postal ID, voter ID, or TIN ID. An authentic BIR TIN ID card carries: (1) the official circular BIR seal at the upper-right corner showing the Bureau of Internal Revenue / Department of Finance emblem, (2) the heading "Republic of the Philippines / Department of Finance / BUREAU OF INTERNAL REVENUE" printed vertically, (3) a 9-digit TAXPAYER IDENTIFICATION NUMBER in large text (format XXX-XXX-XXX), (4) green-patterned security paper background, and (5) a QR code with a DIGITAL TIN ID CONTROL NUMBER at the bottom. When the BIR seal is visible, classify as government_id. A TIN ID, UMID, PhilID and voter ID carry NO expiry date at all — the number is issued for life — so return null for expires_at on those, and never copy an issue date, a control number, or a date printed elsewhere on the card into it. A passport and a postal ID DO expire and their expiry should be read.',
        'clearance' => 'NBI clearance, police clearance, or barangay clearance. An authentic NBI clearance carries an official circular NBI dry seal / security seal / watermark stamped over the photo and security paper (featuring the Philippine sun rays and scales of justice), an "NBI ID NO.", and a "VALID UNTIL" date.',
        'medical' => 'Medical certificate, fit-to-work certificate, or pre-employment medical result.',
        'contract' => 'Employment contract, job offer, or appointment letter.',
        'psa' => 'A PSA (formerly NSO) civil registry document: birth certificate, marriage certificate, or CENOMAR. Printed on security paper and headed "Philippine Statistics Authority".',
        'certificate' => 'Training certificate, TESDA certificate, or seminar certificate.',
        'diploma' => 'A school diploma awarding a degree or a level completed. Names a school and a course; carries no expiry.',
        'transcript' => 'A Transcript of Records (TOR) or scholastic record listing subjects and grades. Carries no expiry.',
        'resume' => 'Résumé, CV, or biodata.',
        'other' => 'A document that does not clearly fit any category above.',
    ],
];
