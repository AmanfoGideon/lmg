<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Compose;

    interface IComposer {
        function addDestination(string $phoneNumber, bool $throwEx = true, ?string $messageId = null): int;
        function addDestinationsFromTextStream(string &$str): int;
        function addDestinationsFromCollection(array &$phoneNumbers, bool $throwEx = false);
        function getDestinationCountry(string $phoneNumber): string | null;
        function getDefaultDestinationCountry();
        function getDestinations();
        function getDestinationsCount();
        function getDestinationWriteMode(string $phoneNumber): int;
        function getDestinationWriteModeById(string $messageId): int;
        function destinationExists(string $phoneNumber): bool;
        function clearDestinations();
        function removeDestination(string $phoneNumber): bool;
        function removeDestinationById(string $messageId): bool;
        function updateDestination(string $prePhoneNumber, string $newPhoneNumber): bool;
        function updateDestinationById(string $messageId, string $newPhoneNumber);
        function getCategory(): int;
        function getDefaultTimeZone();
        function getRouteCountries();
        function setDefaultNumberPrefix($dialCode);
        function getDefaultNumberPrefix();
    }