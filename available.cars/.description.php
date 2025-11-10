<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Localization\Loc;

$arComponentDescription = [
    'NAME' => Loc::getMessage('AVAILABLE_CARS_NAME'),
    'DESCRIPTION' => Loc::getMessage('AVAILABLE_CARS_DESCRIPTION'),
    'PATH' => [
        'ID' => 'test',
        'NAME' => Loc::getMessage('AVAILABLE_CARS_PATH_NAME'),
    ],
];
