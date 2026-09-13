<?php

namespace Tests\Feature\HR;

use App\Services\DocumentScanner;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * What each driver actually puts on the wire.
 *
 * Every other scanner test stubs `read()` so the rules around it can be
 * exercised without a network call — which is right, and is also precisely
 * why the Gemini driver shipped sending a request Google has no endpoint for.
 * It was posting an OpenAI-shaped body (`input`, `response_format`) to
 * `/v1beta/interactions`, and nothing failed — because at the time the only
 * driver anyone ran locally was a local one, and the driver that existed *for
 * deployment* was the one with no test. Every driver is hosted now, so every
 * one of them needs a leg here.
 *
 * These assert the envelope, not the answer. A model's output is its own
 * business; the request shape is ours, and it is the part that silently rots.
 */
class ScannerDriverRequestTest extends TestCase
{
    /**
     * Gemini names the model in the URL and takes the image as a `part`
     * inside `contents`. Getting any of this wrong is a 400 in production and
     * a silent empty form for HR.
     */
    public function test_the_gemini_driver_posts_a_generate_content_request(): void
    {
        config([
            'scanner.driver' => 'gemini',
            'scanner.gemini.api_key' => 'test-key',
            'scanner.gemini.endpoint' => 'https://generativelanguage.googleapis.com/v1beta',
            'scanner.gemini.model' => 'gemini-3.7-flash',
        ]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"type":"drivers_license"}']]],
                ]],
            ]),
        ]);

        $this->scan();

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            $this->assertSame(
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.7-flash:generateContent',
                $request->url(),
                'The model belongs in the URL, not the body.',
            );
            $this->assertSame('test-key', $request->header('x-goog-api-key')[0]);

            // The prompt is a first-class field, not a message.
            $this->assertArrayHasKey('systemInstruction', $body);
            $this->assertNotEmpty($body['systemInstruction']['parts'][0]['text']);

            // The image rides as inline_data beside the text.
            $parts = $body['contents'][0]['parts'];
            $this->assertSame('Read this document.', $parts[0]['text']);
            $this->assertArrayHasKey('inline_data', $parts[1]);
            $this->assertNotEmpty($parts[1]['inline_data']['data']);

            // Structured output, or the reply comes back as prose.
            $this->assertSame(
                'application/json',
                $body['generationConfig']['responseMimeType'],
            );
            /*
             * `responseJsonSchema`, and **not** `responseSchema` beside it.
             *
             * The two fields take different dialects: `responseSchema` is an
             * OpenAPI 3.0 subset whose `type` is a single value, so the
             * `["string", "null"]` unions this schema is full of are rejected
             * with "Proto field is not repeating" — and `additionalProperties`
             * is not a field it knows at all. Every request 400'd on that, and
             * because Gemini validates the body *before* the API key, a wrong
             * schema and a wrong key looked identical from outside. Only one of
             * the two fields may be sent.
             */
            $this->assertArrayHasKey('responseJsonSchema', $body['generationConfig']);
            $this->assertArrayNotHasKey('responseSchema', $body['generationConfig']);

            // The union types that forced the switch, still going out intact.
            $properties = $body['generationConfig']['responseJsonSchema']['properties'];
            $this->assertSame(['string', 'null'], $properties['title']['type']);

            /*
             * Thinking off, and this one is load-bearing rather than tidy.
             *
             * `thoughtsTokenCount` is charged against `maxOutputTokens`, so a
             * thinking model spends the answer's budget on a decision this
             * code does not use — every judgement about a document is made
             * afterwards in PHP. Measured at 458–574 thinking tokens of 1024
             * on one synthetic card, and the failure it produces is silent:
             * the JSON is cut mid-string, `json_decode` refuses it, and a
             * document the model read *correctly* reaches the form as
             * "nothing found". A real TIN ID failed exactly this way.
             */
            $this->assertSame(
                0,
                $body['generationConfig']['thinkingConfig']['thinkingBudget'],
                'Thinking is charged against maxOutputTokens and truncates the answer.',
            );

            // The shapes the old code sent, which Gemini has no endpoint for.
            $this->assertArrayNotHasKey('input', $body);
            $this->assertArrayNotHasKey('response_format', $body);

            return true;
        });
    }

    /**
     * A truncated answer is logged as a truncation, not as an empty one.
     *
     * The envelope looks healthy when the reply is cut off — `candidates` and
     * `usageMetadata` are both present — so the log used to report the one
     * thing identical in the working and the broken case, and the cause was
     * invisible for two days. `MAX_TOKENS` is recoverable by widening the
     * budget; `SAFETY` is the model declining. The form degrades to empty
     * either way, which is right; what must not degrade is the record of why.
     */
    public function test_a_truncated_answer_names_its_cause_in_the_log(): void
    {
        config([
            'scanner.driver' => 'gemini',
            'scanner.gemini.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    // Cut mid-string, exactly as a spent budget leaves it.
                    'content' => ['parts' => [['text' => '{"document_type": "DIGITAL TIN ID",']]],
                    'finishReason' => 'MAX_TOKENS',
                ]],
                'usageMetadata' => [
                    'thoughtsTokenCount' => 574,
                    'candidatesTokenCount' => 12,
                ],
            ]),
        ]);

        Log::spy();

        $this->scan();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) {
                if ($message !== 'Gemini returned no parseable JSON') {
                    return false;
                }

                $this->assertSame('MAX_TOKENS', $context['finish_reason']);
                $this->assertSame(574, $context['thoughts_tokens']);

                /*
                 * The head, not the whole reading. This is a transcription of
                 * somebody's government ID and the log is not where it
                 * belongs; thirty characters is enough to see that JSON
                 * started and stopped.
                 */
                $this->assertSame(30, strlen($context['text_head']));
                $this->assertStringStartsWith('{"document_type"', $context['text_head']);

                return true;
            })
            ->once();
    }

    /** The reply is dug out of Gemini's envelope, not read off the top level. */
    public function test_the_gemini_driver_reads_the_answer_out_of_candidates(): void
    {
        config([
            'scanner.driver' => 'gemini',
            'scanner.gemini.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => '{"type":"clearance","title":"NBI Clearance"}',
                    ]]],
                ]],
            ]),
        ]);

        $fields = app(DocumentScanner::class)
            ->scan(UploadedFile::fake()->image('x.jpg'));

        $this->assertSame('clearance', $fields['type']);
    }

    /**
     * OpenRouter is OpenAI-shaped: the model in the body, the image as an
     * `image_url` data URI, and the schema one level deeper than the other
     * drivers take it — under a *named* `json_schema` object rather than as
     * the bare schema.
     */
    public function test_the_openrouter_driver_posts_an_openai_shaped_request(): void
    {
        config([
            'scanner.driver' => 'openrouter',
            'scanner.openrouter.api_key' => 'test-key',
            'scanner.openrouter.endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
            'scanner.openrouter.model' => 'google/gemini-2.5-flash',
        ]);

        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"type":"drivers_license"}'],
                ]],
            ]),
        ]);

        $this->scan();

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            $this->assertSame(
                'https://openrouter.ai/api/v1/chat/completions',
                $request->url(),
            );
            $this->assertSame('Bearer test-key', $request->header('Authorization')[0]);
            $this->assertSame('google/gemini-2.5-flash', $body['model']);

            // The prompt is a system *message* here, unlike Gemini's
            // first-class systemInstruction field.
            $this->assertSame('system', $body['messages'][0]['role']);

            $parts = collect($body['messages'][1]['content']);
            $image = $parts->firstWhere('type', 'image_url');

            $this->assertNotNull($image, 'The image has to be sent as an image_url part.');
            $this->assertStringStartsWith('data:image/', $image['image_url']['url']);

            /*
             * The whole point of the leg. `strict` is what makes the schema
             * binding rather than advisory — without it a model may answer
             * prose that merely resembles the shape, and the failure is
             * silent all the way to a blank form.
             */
            $this->assertSame('json_schema', $body['response_format']['type']);
            $this->assertTrue($body['response_format']['json_schema']['strict']);
            $this->assertSame(
                'object',
                $body['response_format']['json_schema']['schema']['type'],
                'The schema goes under json_schema.schema, not at the top level.',
            );

            // Two processors rather than one is the cost of a broker; this is
            // the request-level half of managing it, so it is asserted rather
            // than left to a dashboard.
            $this->assertSame('deny', $body['provider']['data_collection']);

            return true;
        });
    }

    /**
     * A model that ignores `response_format` answers prose.
     *
     * That is not an exception — it is a model that cannot do the job — so it
     * comes back as a reading that found nothing rather than as a throw on
     * somebody's upload form. Every proposed field is null, which is exactly
     * what the form does with a scan it cannot use: it leaves the boxes empty
     * and HR types them.
     *
     * Asserted because this is the *likely* misconfiguration on OpenRouter,
     * where the catalogue is large and only some models honour a schema. The
     * log line names the model, which is the only way to tell this apart from
     * a document the scanner genuinely could not read.
     */
    public function test_prose_from_an_unsuitable_model_is_not_an_error(): void
    {
        config([
            'scanner.driver' => 'openrouter',
            'scanner.openrouter.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => "This appears to be a driver's licence."],
                ]],
            ]),
        ]);

        $result = app(DocumentScanner::class)->scan(UploadedFile::fake()->image('licence.jpg'));

        $this->assertNotNull($result, 'A useless answer is still an answer, not a crash.');
        $this->assertNull($result['type']);
        $this->assertNull($result['title']);
        $this->assertNull($result['expires_at']);
        $this->assertFalse($result['type_certain']);
    }

    /** A driver that cannot be reached leaves the form empty; it never throws. */
    public function test_an_unreachable_driver_returns_null(): void
    {
        config(['scanner.driver' => 'gemini', 'scanner.gemini.api_key' => 'test-key']);

        Http::fake(['*' => Http::response('quota exceeded', 429)]);

        $this->assertNull(app(DocumentScanner::class)->scan(UploadedFile::fake()->image('x.jpg')));
    }

    private function scan(): void
    {
        app(DocumentScanner::class)->scan(UploadedFile::fake()->image('licence.jpg'));
    }
}
