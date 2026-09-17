<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestMailCommand extends Command
{
    protected $signature = 'mail:test {recipient? : The email address to send the test message to}';

    protected $description = 'Send a test email via the configured mailer (e.g. Gmail SMTP) and report connection status';

    public function handle(): int
    {
        $recipient = $this->argument('recipient') ?? config('mail.from.address') ?? config('mail.mailers.smtp.username');

        if (! $recipient) {
            $recipient = $this->ask('Enter the recipient email address');
        }

        $mailer = config('mail.default');
        $host = config("mail.mailers.{$mailer}.host", 'N/A');
        $port = config("mail.mailers.{$mailer}.port", 'N/A');
        $from = config('mail.from.address');
        $fromName = config('mail.from.name');

        $this->info("Mail Transport: {$mailer}");
        $this->line("Host: {$host}:{$port}");
        $this->line("From: {$fromName} <{$from}>");
        $this->line("Sending test email to: {$recipient}...");

        try {
            Mail::raw(
                "Hello!\n\nThis is a test message from your PrimePower HRIS system.\nYour email service (Gmail SMTP) is working properly.\nTimestamp: ".now()->toDateTimeString(),
                function ($message) use ($recipient, $from, $fromName) {
                    $message->to($recipient)
                        ->subject('PrimePower HRIS - Email System Test');

                    if ($from) {
                        $message->from($from, $fromName);
                    }
                }
            );

            $this->newLine();
            $this->info("✓ Test email successfully sent to {$recipient}!");
            $this->line("Please check your inbox (and Spam folder).");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error("✗ Failed to send email: ".$e->getMessage());

            if (str_contains($e->getMessage(), '535') || str_contains(strtolower($e->getMessage()), 'authentication')) {
                $this->newLine();
                $this->warn('Authentication Failure Tips:');
                $this->line('1. Make sure 2-Step Verification is turned ON for your Google Account.');
                $this->line('2. Generate a 16-character App Password at https://myaccount.google.com/apppasswords.');
                $this->line('3. Ensure MAIL_PASSWORD in backend/.env is set to the 16-character App Password without quotes or spaces.');
                $this->line('4. Ensure MAIL_USERNAME matches your exact Gmail address.');
            }

            return Command::FAILURE;
        }
    }
}
