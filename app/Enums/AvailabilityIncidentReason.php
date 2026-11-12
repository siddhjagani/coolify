<?php

namespace App\Enums;

enum AvailabilityIncidentReason: string
{
    /** Coolify cannot connect to the server over SSH. */
    case UNREACHABLE = 'unreachable';

    /** The server is reachable, but Docker does not answer. */
    case DOCKER_UNAVAILABLE = 'docker_unavailable';

    /** The primary server of the application is down. */
    case SERVER_DOWN = 'server_down';

    /** The application does not run on its primary server. */
    case NOT_RUNNING = 'not_running';

    public function label(): string
    {
        return match ($this) {
            self::UNREACHABLE => 'Server unreachable',
            self::DOCKER_UNAVAILABLE => 'Docker unavailable',
            self::SERVER_DOWN => 'Primary server down',
            self::NOT_RUNNING => 'Not running',
        };
    }
}
