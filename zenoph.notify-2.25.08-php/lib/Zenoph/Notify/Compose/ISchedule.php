<?php

    namespace Zenoph\Notify\Compose;

    use Zenoph\Notify\Collections\MessageDestinationsList;
    
    interface ISchedule {
        function getBatchId(): string;
        function schedule();
        function isScheduled(): bool;
        function getScheduleInfo(): array;
        function setScheduleDateTime($dateTime, $val1 = null, $val2 = null): void;
        function refreshScheduledDestinationsUpdate(MessageDestinationsList $destsList): void;
    }