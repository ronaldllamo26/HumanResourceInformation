<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who is signed in right now, and how to sign them out.
 *
 * `SESSION_DRIVER=database`, which is what makes this answerable at all: a
 * file or cookie driver leaves no list anybody can read, and a system holding
 * salary and government identifiers needs one. During an incident the
 * question is not "what is the session lifetime" but "who is in there now,
 * from which address, and how do I get them out this minute".
 *
 * The five-minute idle window already closes an abandoned screen. This is for
 * the case that window cannot answer: a password known to have leaked, a
 * laptop that walked out of the building, a contractor whose engagement ended
 * an hour ago. Waiting five minutes is not a response to any of those.
 */
class SessionRegistry
{
    public const EVENT_TERMINATED = 'sessions_terminated';

    /**
     * Every session with a signed-in account behind it, newest activity first.
     *
     * Guest sessions are left out rather than listed as blanks: a session with
     * no `user_id` is somebody sitting on the login screen, which is not a
     * thing anybody needs to terminate.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function active(?string $currentSessionId = null): Collection
    {
        $users = User::query()
            ->whereIn('id', DB::table('sessions')->whereNotNull('user_id')->distinct()->pluck('user_id'))
            ->get(['id', 'name', 'username', 'role'])
            ->keyBy('id');

        return DB::table('sessions')
            ->whereNotNull('user_id')
            ->orderByDesc('last_activity')
            ->get(['id', 'user_id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(function ($row) use ($users, $currentSessionId) {
                $user = $users->get($row->user_id);

                return [
                    'id' => $row->id,
                    'user_id' => (int) $row->user_id,
                    // A session whose account was deleted outright still holds
                    // a payload and still deserves terminating, so it is
                    // listed rather than dropped for having no name.
                    'name' => $user->name ?? 'Deleted account',
                    'username' => $user->username ?? null,
                    'role' => $user->role ?? null,
                    'ip_address' => $row->ip_address,
                    'user_agent' => $row->user_agent,
                    'last_activity' => (int) $row->last_activity,
                    // So the screen can refuse to offer "terminate" on the
                    // row belonging to the person reading it.
                    'is_current' => $currentSessionId !== null && $row->id === $currentSessionId,
                ];
            });
    }

    /** How many sessions that account has open, across devices. */
    public function countFor(User $user): int
    {
        return DB::table('sessions')->where('user_id', $user->id)->count();
    }

    /**
     * Signs one account out everywhere.
     *
     * Deleting the row is the whole mechanism: Laravel reads the session from
     * this table on the next request, finds nothing, and the person lands on
     * the login screen. It needs no cooperation from the browser, which is the
     * point — a response to a leaked password cannot depend on the device
     * holding it agreeing to anything.
     *
     * **API tokens are deliberately left alone.** They are unattended
     * credentials on biometric devices with nobody at the other end to sign in
     * again, so revoking them here would take the timeclock down as a side
     * effect of ending somebody's browser session. Revoking a token is its own
     * decision, on the integrations screen, the same line
     * `RequirePasswordChange` already draws.
     */
    public function terminateFor(Request $request, User $target, ?User $actor = null): int
    {
        $count = DB::table('sessions')->where('user_id', $target->id)->delete();

        $this->record($request, $actor, $target, $count, 'user');

        return $count;
    }

    /**
     * Signs everybody out but the person doing it.
     *
     * Their own session is spared on purpose: this is pressed during an
     * incident, and an administrator who is thrown out by their own first
     * action has to stop and sign back in — through a second factor, on a
     * system they were in the middle of securing. The one session excluded is
     * the one whose owner is demonstrably present.
     */
    public function terminateAll(Request $request, ?User $actor = null): int
    {
        $query = DB::table('sessions')->whereNotNull('user_id');

        if ($id = $request->session()->getId()) {
            $query->where('id', '!=', $id);
        }

        $count = $query->delete();

        $this->record($request, $actor, null, $count, 'all');

        return $count;
    }

    /** Ends one specific session, leaving that account's other devices alone. */
    public function terminateSession(Request $request, string $sessionId, ?User $actor = null): int
    {
        $row = DB::table('sessions')->where('id', $sessionId)->first(['user_id']);

        $count = DB::table('sessions')->where('id', $sessionId)->delete();

        $this->record(
            $request,
            $actor,
            $row?->user_id ? User::find($row->user_id) : null,
            $count,
            'session',
        );

        return $count;
    }

    /**
     * One audit row per act, naming the scope and the count.
     *
     * Written even when the count is zero, because "somebody pressed sign out
     * everywhere and nothing was open" is a fact about the response to an
     * incident, and a trail that only records the successful half of a
     * response cannot be used to reconstruct one.
     */
    private function record(Request $request, ?User $actor, ?User $target, int $count, string $scope): void
    {
        AuditLog::create([
            'user_id' => $actor?->id,
            'auditable_type' => User::class,
            'auditable_id' => $target?->id,
            'event' => self::EVENT_TERMINATED,
            'new_values' => array_filter([
                'scope' => $scope,
                'sessions_ended' => $count,
                'target_username' => $target?->username,
            ], fn ($value) => $value !== null),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
