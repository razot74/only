<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Localization\Loc;

$arComponentParameters = [
    'PARAMETERS' => [
        'START_DATE' => [
            'NAME' => Loc::getMessage('AVAILABLE_CARS_START_DATE'),
            'TYPE' => 'STRING',
            'PARENT' => 'BASE',
            'DEFAULT' => 'start_date',
        ],
        'END_DATE' => [
            'NAME' => Loc::getMessage('AVAILABLE_CARS_END_DATE'),
            'TYPE' => 'STRING',
            'PARENT' => 'BASE',
            'DEFAULT' => 'end_date'
        ],
        'CACHE_TIME' => [
            'DEFAULT' => 3600
        ],
        'CACHE_TYPE' => [
            'DEFAULT' => 'A'
        ],
    ],
];
