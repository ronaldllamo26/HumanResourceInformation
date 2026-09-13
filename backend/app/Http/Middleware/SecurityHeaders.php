<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers that close off attacks the application code cannot.
 *
 * Deliberately *not* a full Content-Security-Policy. A real script-src policy
 * needs a nonce threaded through the Vite tags and the Inertia root, and a
 * half-written one either breaks the payslip print view or is loose enough to
 * be decorative. The three CSP directives set here constrain framing, plugins,
 * and `<base>` — none of which can break a script or a stylesheet — so they
 * are safe to apply unconditionally. A scripted policy is a separate job.
 */
class SecurityHeaders
{
    /**
     * Static headers applied to every response.
     *
     * @var array<string, string>
     */
    private const HEADERS = [
        // The HRIS has no reason to be framed by anything, and being framed is
        // how a clickjack gets an HR user to approve a payroll run they cannot
        // see. X-Frame-Options for older browsers, frame-ancestors for current
        // ones — they mean the same thing to the two generations.
        'X-Frame-Options' => 'DENY',

        // Stops a browser second-guessing the Content-Type on a 201-file
        // download and running an uploaded file as script.
        'X-Content-Type-Options' => 'nosniff',

        // An employee ID is in the path of most screens here; a full referrer
        // would leak it to any external link a user follows.
        'Referrer-Policy' => 'strict-origin-when-cross-origin',

        // Nothing in this system uses a camera, a microphone, or location.
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',

        'Content-Security-Policy' => "frame-ancestors 'none'; object-src 'none'; base-uri 'self'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $header => $value) {
            // Allow embedding in IDE webviews (Antigravity/VS Code) during local development
            if (app()->isLocal() && in_array($header, ['X-Frame-Options', 'Content-Security-Policy'], true)) {
                continue;
            }

            $response->headers->set($header, $value);
        }

        /*
         * Only over TLS, and never in local development.
         *
         * The scheme check alone used to be the whole guard, on the reasoning
         * that development is served over plain http so the branch could not
         * fire. That reasoning was an assumption about the environment rather
         * than a rule, and it stopped being true the moment somebody pressed
         * "Secure" in Herd: the site began answering on https with a
         * self-signed certificate, and one click through the browser's warning
         * would have pinned `core2.test` to HTTPS for a year — `includeSubDomains`
         * taking every `*.core2.test` with it.
         *
         * That is not a warning that can be undone by turning TLS back off.
         * HSTS lives in the browser, so the host stays unreachable over http
         * until the max-age expires or the developer digs it out of the
         * browser's internal settings — which is exactly the lockout the old
         * comment set out to avoid.
         *
         * HSTS is a production control. A local environment has no business
         * issuing a year-long promise about a hostname that only resolves on
         * one machine.
         */
        if ($request->secure() && ! app()->isLocal()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
