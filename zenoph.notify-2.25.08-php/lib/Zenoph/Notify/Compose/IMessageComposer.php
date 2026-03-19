<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Compose;
    
    interface IMessageComposer {
        function setSender(string $sender): void ;
        function getSender(): string | null;
        function notifyDeliveries(): bool;
        function getDeliveryCallback(): array;
        function setDeliveryCallback(string $url, int $contentType): void;
        function validateDestinationSenderName(string $phoneNumber): void;
        function setMessage(string $message, mixed $info = null): void;
        function getMessage(): string | null;
        function getMessageId(string $phoneNumber): string;
        function messageIdExists(string $messageId): bool;
    }