<?php

namespace App\Notifications\Application;

use App\Models\Application;
use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The primary server of an application went down. Coolify made a standby server primary, or could not ($newServer
 * is null): no standby server runs the application, or the swap failed ($error).
 */
class Failover extends CustomEmailNotification
{
    public string $applicationName;

    public ?string $applicationUrl;

    /**
     * @param  array{repointed?: list<string>, failed?: array<string, string>, unmanaged?: list<string>}  $dns  from SwapApplicationPrimary
     */
    public function __construct(
        public Application $application,
        public Server $failedServer,
        public ?Server $newServer,
        public array $dns = [],
        public ?string $error = null,
    ) {
        $this->onQueue('high');
        $this->afterCommit();
        $this->applicationName = (string) $application->name;
        $this->applicationUrl = $application->link();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('application_failover');
    }

    public function succeeded(): bool
    {
        return $this->newServer !== null;
    }

    public function title(): string
    {
        return $this->succeeded() ? 'Application failed over' : 'Application failover failed';
    }

    public function summary(): string
    {
        if ($this->error !== null) {
            return "The primary server '{$this->failedServer->name}' of {$this->applicationName} is down, and Coolify could not make another server primary: {$this->error}";
        }
        if (! $this->succeeded()) {
            return "The primary server '{$this->failedServer->name}' of {$this->applicationName} is down, and no additional server runs the application. The application is down until the server is back.";
        }

        return "The primary server '{$this->failedServer->name}' of {$this->applicationName} is down. '{$this->newServer->name}' is the primary server now.";
    }

    /**
     * What the user must still do about DNS, one line per item. Empty when nothing is left.
     *
     * @return list<string>
     */
    public function dnsLines(): array
    {
        if (! $this->succeeded()) {
            return [];
        }

        $lines = [];
        if (filled($this->dns['repointed'] ?? [])) {
            $lines[] = 'DNS updated: '.implode(', ', $this->dns['repointed']).'.';
        }
        foreach ($this->dns['failed'] ?? [] as $hostname => $error) {
            $lines[] = "DNS of {$hostname} could not be updated: {$error}";
        }
        if (filled($this->dns['unmanaged'] ?? [])) {
            $lines[] = "Point these domains to '{$this->newServer->name}' in your DNS provider: ".implode(', ', $this->dns['unmanaged']).'.';
        }

        return $lines;
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: {$this->title()} ({$this->applicationName})");
        $mail->view('emails.application-failover', [
            'summary' => $this->summary(),
            'dnsLines' => $this->dnsLines(),
            'applicationUrl' => $this->applicationUrl,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $description = $this->summary();
        foreach ($this->dnsLines() as $line) {
            $description .= "\n\n{$line}";
        }
        if ($this->applicationUrl) {
            $description .= "\n\n[Open Application in Coolify]({$this->applicationUrl})";
        }

        return new DiscordMessage(
            title: ($this->succeeded() ? ':warning: ' : ':cross_mark: ').$this->title(),
            description: $description,
            color: $this->succeeded() ? DiscordMessage::warningColor() : DiscordMessage::errorColor(),
            isCritical: true,
        );
    }

    public function toTelegram(): array
    {
        $message = 'Coolify: '.$this->summary();
        foreach ($this->dnsLines() as $line) {
            $message .= "\n\n{$line}";
        }

        return [
            'message' => $message,
            'buttons' => $this->applicationUrl ? [['text' => 'Open Application in Coolify', 'url' => $this->applicationUrl]] : [],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: $this->title(),
            level: $this->succeeded() ? 'warning' : 'error',
            message: implode('<br/><br/>', [e($this->summary()), ...array_map('e', $this->dnsLines())]),
            buttons: $this->applicationUrl ? [['text' => 'Open Application in Coolify', 'url' => $this->applicationUrl]] : [],
        );
    }

    public function toSlack(): SlackMessage
    {
        $description = $this->summary();
        foreach ($this->dnsLines() as $line) {
            $description .= "\n\n{$line}";
        }
        if ($this->applicationUrl) {
            $description .= "\n\n*Application URL:* {$this->applicationUrl}";
        }

        return new SlackMessage(
            title: $this->title(),
            description: $description,
            color: $this->succeeded() ? SlackMessage::warningColor() : SlackMessage::errorColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => $this->succeeded(),
            'message' => $this->title(),
            'event' => 'application_failover',
            'application_name' => $this->applicationName,
            'application_uuid' => $this->application->uuid,
            'failed_server_name' => $this->failedServer->name,
            'failed_server_uuid' => $this->failedServer->uuid,
            'new_server_name' => $this->newServer?->name,
            'new_server_uuid' => $this->newServer?->uuid,
            'dns_repointed' => $this->dns['repointed'] ?? [],
            'dns_failed' => array_keys($this->dns['failed'] ?? []),
            'dns_unmanaged' => $this->dns['unmanaged'] ?? [],
            'error' => $this->error,
            'url' => $this->applicationUrl,
        ];
    }
}
