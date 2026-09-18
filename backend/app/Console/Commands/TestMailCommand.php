<?php

namespace App\Console\Commands;

use App\Notifications\LoginOtp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class TestMailCommand extends Command
{
    protected $signature = 'mail:test {recipient? : The email address to send the test message to}';

    protected $description = 'Send a test OTP email using the configured mailer (e.g. Resend SMTP)';

    public function handle(): int
    {
        $recipient = $this->argument('recipient') ?? config('mail.from.address');

        if (! $recipient) {
            $recipient = $this->ask('Enter the recipient email address');
        }

        $host = config('mail.mailers.smtp.host');
        $port = config('mail.mailers.smtp.port');
        $from = config('mail.from.address');
        $fromName = config('mail.from.name');

        $this->info('Mail Transport: '.config('mail.default'));
        $this->line("SMTP Host: {$host}:{$port}");
        $this->line("From: {$fromName} <{$from}>");
        $this->line("Sending test OTP email to: {$recipient}...");

        $testOtp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        try {
            Notification::route('mail', $recipient)->notify(new LoginOtp($testOtp, 120));

            $this->newLine();
            $this->info("✓ Success! Test OTP ({$testOtp}) was sent to {$recipient}.");
            $this->line('Please check your email inbox (and Spam folder).');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('✗ Failed to send email: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
