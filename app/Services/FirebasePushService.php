<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\MulticastSendReport;

class FirebasePushService
{
    protected $messaging;

    public function __construct()
    {
        $factory = (new Factory)->withServiceAccount(config('services.firebase.credentials.file'));
        $this->messaging = $factory->createMessaging();
    }

    /**
     * Send to a single device token.
     */
    public function sendToToken(string $token, string $title, string $body, array $data = []): bool
    {
        $message = CloudMessage::withTarget('token', $token)
            ->withNotification(Notification::create($title, $body))
            ->withData(array_merge(['type' => 'affirmation'], $data));

        $this->messaging->send($message);
        return true;
    }

    /**
     * Send the same message to many tokens at once (FCM handles batching).
     * Returns [success => int, failure => int].
     */
    public function sendToMany(array $tokens, string $title, string $body, array $data = []): array
    {
        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData(array_merge(['type' => 'affirmation'], $data));

        /** @var MulticastSendReport $report */
        $report = $this->messaging->sendMulticast($message, $tokens);
        return [
            'success' => $report->successes()->count(),
            'failure' => $report->failures()->count(),
        ];
    }

    /**
     * Publish to a topic (optional – if you decide to use topics).
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): bool
    {
        $message = CloudMessage::withTarget('topic', $topic)
            ->withNotification(Notification::create($title, $body))
            ->withData($data);

        $this->messaging->send($message);
        return true;
    }
}
