<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
    namespace Zenoph\Notify\Enums;
    
    abstract class ContentType {
        const XML = 0;
        const JSON = 1;
        const WWW_URL_ENCODED = 2;
        const MULTIPART_FORM_DATA = 3;
        const GZBIN_XML = 4;
        const GZBIN_JSON = 5;
        const GZBIN_WWW_URL_ENCODED = 6;
    }