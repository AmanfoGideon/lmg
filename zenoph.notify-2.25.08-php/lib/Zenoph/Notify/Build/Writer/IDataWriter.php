<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

    namespace Zenoph\Notify\Build\Writer;
    
    use Zenoph\Notify\Compose\Composer;
    use Zenoph\Notify\Compose\SMSComposer;
    use Zenoph\Notify\Compose\VoiceComposer;
    use Zenoph\Notify\Compose\MessageComposer;
    
    interface IDataWriter {
        function &writeScheduledMessageUpdateRequest(MessageComposer $composer);
        function &writeScheduledMessagesLoadRequest(array $filter);
        function &writeDestinationsData(Composer $composer);
        function &writeSMSRequest(SMSComposer $composer);
        function &writeVoiceRequest(VoiceComposer $composer);
        function &writeUSSDRequest($ucArr);
        function &writeDestinationsDeliveryRequest(array $messageIdsArr);
    }