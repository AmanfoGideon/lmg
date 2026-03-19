<?php

    namespace Zenoph\Notify\Compose;

    use Zenoph\Notify\Collections\PersonalisedValuesList;
    use Zenoph\Notify\Store\PersonalisedValues;
    
    interface ISMSComposer {
        function setSMSType(int | string $type): void ;
        function getSMSType();
        function getDefaultSMSType();
        function personalise();
        function getPersonalisedDestinationMessageId($phoneNumber, $values);
        function getPersonalisedDestinationWriteMode($phoneNumber, $values);
        function addPersonalisedDestination(string $phoneNumber, bool $throwEx, array $values, ?string $messageId = null): int;
        function personalisedValuesExists(string $phoneNumber, array $values): bool;
        function removePersonalisedValues(string $phoneNumber, array $values);
        function removePersonalisedDestination(string $phoneNumber, array $values);
        function updatePersonalisedValuesById(string $messageId, array $newValues): bool;
        function updatePersonalisedValues(string $phoneNumber, array $newValues, ?array $prevValues = null): bool;
        function updatePersonalisedValuesWithId(string $phoneNumber, array $newValues, string $newMessageId): bool;
        function getPersonalisedValues(string $phoneNumber): PersonalisedValuesList;
        function getPersonalisedValuesById(string $messageId): PersonalisedValues;
        function getRegisteredSenderIds();
        
        // static functions
        static function getMessageCount(string $message, int $type): int;
        static function getMessageVariablesCount(string $messageText): int;
        static function getMessageVariables(string $messageText, bool $trim = false): array;
    }
