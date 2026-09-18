<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcomes a newly provisioned user with their initial credentials.
 *
 * Sent directly to the person's personal inbox (e.g. Gmail) registered
 * by the Administrator upon account creation.
 *
 * Deliberately synchronous (not queued) so the email is dispatched
 * immediately without relying on a background queue worker.
 */
class AccountProvisioned extends Notification
{
    public function __construct(
        public readonly string $temporaryPassword,
        public readonly string $role,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $roleLabel = match ($this->role) {
            User::ROLE_SUPER_ADMIN => 'Super Administrator',
            User::ROLE_ADMIN => 'Administrator',
            User::ROLE_HR_STAFF => 'HR Staff',
            User::ROLE_SUPERVISOR => 'Supervisor',
            User::ROLE_EMPLOYEE => 'Employee',
            default => ucfirst($this->role),
        };

        $fromAddress = config('mail.from.address') ?: 'gavegavebenavidez@gmail.com';
        $fromName = config('mail.from.name') ?: 'PrimePower Manpower HRIS';
        $mailer = config('mail.default', 'smtp');

        return (new MailMessage)
            ->mailer($mailer)
            ->from($fromAddress, $fromName)
            ->subject('Your PrimePower HRIS Account Credentials')
            ->greeting('PrimePower Account Credentials')

            ->line('Your employee account has been created by the Administrator.')
            ->line('Your sign-in company username and email are:')
            ->line('Username: **'.$notifiable->username.'**')
            ->line('Email: **'.($notifiable->otp_email ?: $notifiable->email).'**')
            ->line('Your temporary password is:')
            ->line('**'.$this->temporaryPassword.'**')
            ->action('Sign In to PrimePower', url('/login'))
            ->line('This password is temporary. You will be asked to set your own password upon your first sign-in.')
            ->line('If you were not expecting this account, please contact your PrimePower HR administrator.');
    }
}
