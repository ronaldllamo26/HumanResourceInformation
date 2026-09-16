<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\DocumentScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The privacy notice every signed-in person reads, and their acknowledgement.
 *
 * Readable at any time from Settings > Security, not only at the first
 * sign-in: a notice somebody can only see once is a notice they cannot check.
 */
class PrivacyNoticeController extends Controller
{
    private const PROCESSORS = [
        'gemini' => 'Google (Gemini)',
        'openrouter' => 'OpenRouter, which forwards it to the AI provider serving the model',
        'anthropic' => 'Anthropic (Claude)',
    ];

    public function show(Request $request, DocumentScanner $scanner): Response
    {
        $user = $request->user();
        $driver = (string) config('scanner.driver');

        return Inertia::render('Auth/PrivacyNotice', [
            'version' => config('privacy.notice_version'),
            'company' => Setting::get('company.name'),
            'contact' => [
                'name' => config('privacy.dpo_name'),
                'email' => config('privacy.dpo_email') ?: Setting::get('company.email') ?: null,
                'phone' => Setting::get('company.phone') ?: null,
            ],
            'retention' => config('privacy.retention'),

            // Named only when the scanner is actually switched on: every scan is
            // a transfer of an ID photo abroad, and the people in those photos
            // are owed the name of whoever receives it.
            'scannerProcessor' => $scanner->isEnabled() ? (self::PROCESSORS[$driver] ?? $driver) : null,

            'acknowledgedAt' => $user->hasAcknowledgedPrivacyNotice()
                ? $user->privacy_acknowledged_at?->toIso8601String()
                : null,
        ]);
    }

    public function acknowledge(Request $request): RedirectResponse
    {
        $request->validate(['understood' => ['accepted']], [
            'understood.accepted' => 'Tick the box to confirm you have read the notice.',
        ]);

        $user = $request->user();
        $version = config('privacy.notice_version');

        $user->forceFill([
            'privacy_notice_version' => $version,
            'privacy_acknowledged_at' => now(),
        ])->save();

        // The record that the notice was given is what an NPC complaint asks
        // for, so it goes in the audit log beside sign-ins, not only on the row.
        AuditLog::create([
            'user_id' => $user->id,
            'auditable_type' => $user::class,
            'auditable_id' => $user->id,
            'event' => 'privacy_acknowledged',
            'new_values' => ['notice_version' => $version],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->intended(route('dashboard'))
            ->with('success', 'Thank you — your acknowledgement of the privacy notice was recorded.');
    }
}
