<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die;
}

use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Context;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;

class AvailableCarsComponent extends CBitrixComponent
{
    /**
     * Для упрощения будем считать, что id HL известны
     *
     * @var array
     */
    private array $hlBlocks = [
        'CARS' => 1,
        'COMFORT_CATEGORIES' => 2,
        'POSITIONS' => 3,
        'DRIVERS' => 4,
        'TRIPS' => 5,
    ];

    /**
     * @param $arParams
     * @return array
     */
    public function onPrepareComponentParams($arParams): array
    {
        $arParams['START_DATE'] = $arParams['START_DATE'] ?? 'start_date';
        $arParams['END_DATE'] = $arParams['END_DATE'] ?? 'end_date';
        $arParams['CACHE_TIME'] = intval($arParams['CACHE_TIME'] ?? 3600); // Без уточнения по тз сложно понять, как часто добавляются поездки, пусть будет на час кеш

        return $arParams;
    }

    /**
     * @return void
     */
    public function executeComponent(): void
    {
        if ($this->startResultCache($this->arParams['CACHE_TIME'], $this->getAdditionalCacheId())) {
            try {
                if (!Loader::includeModule('highloadblock')) {
                    throw new \Exception('Ошибка загрузки модуля highloadblock');
                }

                $this->arResult = $this->getData();
            }
            catch (\Throwable $e) {
                $logger = new \Bitrix\Main\Diag\FileLogger('/var/log/php/error.log');
                $logger->error(__METHOD__, ['err' => $e->getMessage()]);

                $this->abortResultCache();

                $this->arResult['ERROR'] = 'Произошла ошибка, попробуйте позже';
            }

            $this->IncludeComponentTemplate();
        }
    }

    /**
     * Вернёт итоговую инфу по доступным авто
     *
     * @return array
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private function getData(): array
    {
        // Получим должность юзера с доступными категориями комфорта
        $userAllowedCategories = $this->getUserAllowedCategories();
        if (empty($userAllowedCategories['UF_ALLOWED_CATEGORIES'])) {
            return ['ITEMS' => [], 'ERROR' => 'Нет доступных категорий авто для пользователя'];
        }

        // Получим фильтр из request
        $timeRange = $this->getRequestDateQuery();
        if (empty($timeRange)) {
            return ['ITEMS' => [], 'ERROR' => 'Не указан фильтр дат'];
        }

        // Получим ids авто, которые уже заняты для выбранных дат
        $busyCarIds = $this->getBusyCarIds($timeRange);

        // Получим список доступных авто со всеми данными
        return ['ITEMS' => $this->getAvailableCars($userAllowedCategories['UF_ALLOWED_CATEGORIES'], $busyCarIds), 'ERROR' => null];
    }

    /**
     * Вернёт доступные юзеру категории авто
     *
     * @return array|null
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private function getUserAllowedCategories(): ?array
    {
        global $USER;

        if (!$USER->IsAuthorized()) {
            return null;
        }

        $userId = $USER->GetID();
        $cache = Cache::createInstance();
        //маловероятно, что должность юзера и доступные ей категории авто часто меняется
        $cacheParams = [86400, md5(serialize(
            [
                'userId' => $userId,
            ]
        )), '/user/car_allowed_categories'];
        if ($cache->startDataCache(...$cacheParams)) {
            $userData = UserTable::getList([
                'select' => ['ID', 'UF_POSITION'],
                'filter' => ['=ID' => $userId],
            ])->fetch();

            if (empty($userData['UF_POSITION'])) {
                $cache->abortDataCache();
                return null;
            }

            $taggedCache = \Bitrix\Main\Application::getInstance()->getTaggedCache();
            $taggedCache->startTagCache('/user/car_allowed_categories');

            $positionHl = $this->getHLDataClass($this->hlBlocks['POSITIONS']);
            $position = $positionHl::getList([
                'select' => [
                    'ID',
                    'UF_ALLOWED_CATEGORIES'
                ],
                'filter' => [
                    '=UF_IS_ACTIVE' => 1,
                    '=ID' => $userData['UF_POSITION']
                ],
            ])->fetch();

            //можно будет скинуть кеш по тегу в случае изменений
            $taggedCache->registerTag('user_id_' . $userId);
            if (!empty($position)) {
                $taggedCache->registerTag('hl_position_id_' . $position['ID']);
            }

            $cache->endDataCache($position);
        }
        else {
            $position = $cache->getVars();
        }

        return $position ?: null;
    }

    /**
     * Вернёт доступные для заказа авто
     *
     * @param array $categoryIds
     * @param array $busyCarIds
     *
     * @return array
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private function getAvailableCars(array $categoryIds, array $busyCarIds): array
    {
        $carsHl = $this->getHLDataClass($this->hlBlocks['CARS']);
        $filter = [
            '=UF_IS_ACTIVE' => 1,
            '=UF_COMFORT_CATEGORY_ID' => $categoryIds,
        ];
        if (!empty($busyCarIds)) {
            $filter['!=ID']= $busyCarIds;
        }

        $cars = $carsHl::getList([
            'select' => [
                'ID',
                'UF_NAME',
                'UF_STATE_NUMBER',
                'UF_COMFORT_CATEGORY_ID',
                'UF_DRIVER_ID'
            ],
            'filter' => $filter
        ])->fetchAll();

        return $this->fillCarsWithNameCategories($this->fillCarsWithDrivers($cars));
    }

    /**
     * Заполнит список авто названиями категорий комфорта
     *
     * @param array $cars
     *
     * @return array
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private function fillCarsWithNameCategories(array $cars): array
    {
        if (empty($cars)) {
            return [];
        }

        $categoryIds = array_unique(array_column($cars, 'UF_COMFORT_CATEGORY_ID'));
        $categoriesHl = $this->getHLDataClass($this->hlBlocks['COMFORT_CATEGORIES']);

        $categories = $categoriesHl::getList([
            'select' => ['ID', 'UF_NAME'],
            'filter' => ['=ID' => $categoryIds]
        ])->fetchAll();

        $categoryMap = array_column($categories, 'UF_NAME', 'ID');

        return array_map(static function($car) use ($categoryMap) {
            $car['CATEGORY_NAME'] = $categoryMap[$car['UF_COMFORT_CATEGORY_ID']] ?? 'Категория не найдена';
            return $car;
        }, $cars);
    }

    /**
     * Вернёт список забронированных авто на выбранные даты
     *
     * @param array $timeRange
     *
     * @return array
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private function getBusyCarIds(array $timeRange): array
    {
        $tripsHl = $this->getHLDataClass($this->hlBlocks['TRIPS']);

        $busyTrips = $tripsHl::getList([
            'select' => ['UF_CAR_ID'],
            'filter' => [
                '=UF_STATUS' => 'active',
                'LOGIC' => 'OR',
                [
                    '>=UF_START_DATE' => $timeRange['START'],
                    '<=UF_START_DATE' => $timeRange['END'],
                ],
                [
                    '>=UF_END_DATE' => $timeRange['START'],
                    '<=UF_END_DATE' => $timeRange['END'],
                ],
                [
                    '<=UF_START_DATE' => $timeRange['START'],
                    '>=UF_END_DATE' => $timeRange['END'],
                ]
            ]
        ])->fetchAll();

        return array_column($busyTrips, 'UF_CAR_ID');
    }

    /**
     * Заполнит список авто данными водителей
     *
     * @param array $cars
     *
     * @return array
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private function fillCarsWithDrivers(array $cars): array
    {
        if (empty($cars)) {
            return [];
        }

        $driverIds = array_unique(array_filter(array_column($cars, 'UF_DRIVER_ID')));
        if (empty($driverIds)) {
            return $cars;
        }

        $driversHl = $this->getHLDataClass($this->hlBlocks['DRIVERS']);

        $drivers = $driversHl::getList([
            'select' => ['ID', 'UF_NAME', 'UF_SURNAME', 'UF_PATRONYMIC', 'UF_PHONE'],
            'filter' => [
                '=ID' => $driverIds,
                '=UF_IS_ACTIVE' => 1
            ]
        ])->fetchAll();

        $driverMap = array_column($drivers, null, 'ID');

        return array_map(static function($car) use ($driverMap) {
            if (isset($driverMap[$car['UF_DRIVER_ID']])) {
                $driver = $driverMap[$car['UF_DRIVER_ID']];
                $car['DRIVER_NAME'] = implode(' ', [$driver['UF_NAME'], $driver['UF_SURNAME'], $driver['UF_PATRONYMIC']]);
                $car['DRIVER_PHONE'] = $driver['UF_PHONE'];
            } else {
                $car['DRIVER_NAME'] = 'Не назначен';
                $car['DRIVER_PHONE'] = '';
            }
            return $car;
        }, $cars);
    }

    /**
     * Вернёт DataManager для работы с HL
     *
     * @param int $hlId
     *
     * @return \Bitrix\Main\ORM\Data\DataManager|string
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private function getHLDataClass(int $hlId): \Bitrix\Main\ORM\Data\DataManager|string
    {
        $hlBlock = HighloadBlockTable::getById($hlId)->fetch();

        if (!$hlBlock) {
            throw new \Exception("HL Block #{$hlId} не найден");
        }

        $entity = HighloadBlockTable::compileEntity($hlBlock);

        return $entity->getDataClass();
    }

    /**
     * Вернёт datetime для фильтра
     *
     * @return \Bitrix\Main\Type\DateTime[]|null
     */
    private function getRequestDateQuery(): ?array
    {
        $request = Context::getCurrent()->getRequest();

        $startDateQuery = $request->getQuery($this->arParams['START_DATE']);
        $endDateQuery = $request->getQuery($this->arParams['END_DATE']);

        if (empty($startDateQuery) || empty($endDateQuery)) {
            return null;
        }

        try {
            $startDate = new \Bitrix\Main\Type\DateTime($startDateQuery);
            $endDate = new \Bitrix\Main\Type\DateTime($endDateQuery);

            if ($startDate >= $endDate) {
                return null;
            }

            return [
                'START' => $startDate,
                'END' => $endDate
            ];

        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Вернёт id для кеша
     *
     * @return string
     */
    private function getAdditionalCacheId(): string
    {
        $request = Context::getCurrent()->getRequest();
        $startDateQuery = $request->getQuery($this->arParams['START_DATE']);
        $endDateQuery = $request->getQuery($this->arParams['END_DATE']);

        global $USER;
        return md5(serialize([
            'startDate' => $startDateQuery,
            'endDate' => $endDateQuery,
            'userId' => $USER->GetID()
        ]));
    }
}
