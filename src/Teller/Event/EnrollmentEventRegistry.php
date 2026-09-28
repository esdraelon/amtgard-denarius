<?php

declare(strict_types=1);

namespace Amtgard\Denarius\Teller\Event;

final class EnrollmentEventRegistry
{
    /** @var array<string, EnrollmentEvent> */
    private array $events;

    /**
     * @param list<EnrollmentEvent> $events
     */
    public function __construct(array $events, private readonly IgnoredEnrollmentEvent $ignored = new IgnoredEnrollmentEvent())
    {
        $indexed = [];
        foreach ($events as $event) {
            $indexed[$event->type()] = $event;
        }
        $this->events = $indexed;
    }

    public function find(string $type): EnrollmentEvent
    {
        return $this->events[$type] ?? $this->ignored;
    }
}
