<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The sign-in code, in an email.
 *
 * **Deliberately not queued.** Everything else about a notification is better
 * on a queue, and this one is the exception: the person is sitting on the code
 * screen waiting for it, and there is no queue worker on the deployment — so a
 * queued code would leave them looking at a field no code ever arrives for,
 * with nothing on screen saying why. The cost is a second or two on the
 * request that sends it.
 */
class LoginOtp extends Notification
{
    public function __construct(
        private readonly string $code,
        private readonly int $ttlSeconds,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = max(1, (int) ceil($this->ttlSeconds / 60));
        $timeLabel = $minutes === 1 ? '1 minute (60 seconds)' : "{$minutes} minutes ({$this->ttlSeconds} seconds)";

        $fromAddress = config('mail.from.address') ?: 'gavegavebenavidez@gmail.com';
        $fromName = config('mail.from.name') ?: 'PrimePower Manpower HRIS';
        $mailer = config('mail.default', 'smtp');

        return (new MailMessage)
            ->mailer($mailer)
            ->from($fromAddress, $fromName)
            ->subject("Your PrimePower sign-in code is {$this->code}")
            ->greeting('PrimePower Sign-in Verification')
            ->line('Your one-time 6-digit sign-in code is:')
            ->line('**'.$this->code.'**')
            ->line("This code expires in {$timeLabel} and can only be used once.")
            ->line('If you did not try to sign in, your password is no longer private — tell your administrator so it can be reset.');
    }
}
