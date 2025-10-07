<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Loader;
use Bitrix\Main\Context;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Web\Json;

class CompanyFreeCarsComponent extends CBitrixComponent
{
    /** @var CurrentUser */
    private CurrentUser $currentUser;

    /** @var \Bitrix\Main\HttpRequest */
    protected $request;

    // Константы инфоблоков и HL-блоков
    private const IBLOCK_CODE_JOBS = 'job_positions';
    private const IBLOCK_CODE_CARS = 'cars';
    private const IBLOCK_CODE_COMFORT_CATEGORIES = 'comfort_categories';
    private const HL_SCHEDULE_TABLE_NAME = 'b_schedule';

    public function __construct($component = null)
    {
        parent::__construct($component);

        if (!Loader::includeModule('iblock') || !Loader::includeModule('highloadblock')) {
            throw new \Bitrix\Main\LoaderException('Не удалось подключить модули iblock или highloadblock');
        }

        $this->currentUser = CurrentUser::get();
        $this->request = Context::getCurrent()->getRequest();
    }

    public function executeComponent()
    {
        $startTimeStr = $this->request->get('start_time');
        $endTimeStr = $this->request->get('end_time');

        if (!$startTimeStr || !$endTimeStr) {
            ShowError('Укажите GET-параметры: start_time и end_time');
            return;
        }

        try {
            $startTime = new DateTime($startTimeStr);
            $endTime = new DateTime($endTimeStr);
            if ($startTime >= $endTime) {
                ShowError('Время начала должно быть раньше окончания');
                return;
            }
        } catch (\Exception $e) {
            ShowError('Неверный формат времени');
            return;
        }

        $userId = (int)$this->currentUser->getId();
        if (!$userId) {
            ShowError('Пользователь не авторизован');
            return;
        }

        $this->arResult = $this->getFreeCars($userId, $startTime, $endTime);

        echo Json::encode($this->arResult, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Основная логика поиска свободных машин
     */
    private function getFreeCars(int $userId, DateTime $startTime, DateTime $endTime): array
    {
        $jobId = UserTable::getList([
            'filter' => ['=ID' => $userId],
            'select' => ['UF_JOB']
        ])->fetch()['UF_JOB'];

        if (!$jobId) {
            return ['error' => 'У пользователя не указана должность'];
        }

        $comfortCategoryIds = $this->getComfortCategoryIdsByJob($jobId);

        if (empty($comfortCategoryIds)) {
            return ['error' => 'Для должности не заданы категории комфорта'];
        }

        $cars = $this->getCarsByComfortCategories($comfortCategoryIds);
        if (empty($cars)) {
            return ['error' => 'Нет автомобилей с подходящей категорией'];
        }

        $freeCars = $this->filterFreeCars($cars, $startTime, $endTime);
        return array_values($freeCars);
    }

    /**
     * Получить ID категорий комфорта по должности (из множественного свойства PROPERTY_COMFORT_CATEGORIES)
     */
    private function getComfortCategoriesByJob(int $jobId): array
    {
        $iblockId = $this->getIblockId(self::IB_JOBS);
        if (!$iblockId) {
            return [];
        }

        $rows = \Bitrix\Iblock\ElementPropertyTable::getList([
            'filter' => [
                'IBLOCK_ELEMENT_ID' => $jobId,
                '=IBLOCK_PROPERTY.CODE' => 'COMFORT_CATEGORIES',
                '=IBLOCK_ELEMENT.IBLOCK_ID' => $iblockId
            ],
            'select' => ['VALUE'],
        ])->fetchAll();

        return array_column($rows, 'VALUE');
    }

    /**
     * Получить автомобили по категориям комфорта с моделью, категорией и водителем
     */
    private function getCars(array $categoryIds): array
    {
        $iblockId = $this->getIblockId(self::IB_CARS);
        if (!$iblockId) {
            return [];
        }

        $cars = [];
        $props = \Bitrix\Iblock\ElementPropertyTable::getList([
            'filter' => [
                '=IBLOCK_PROPERTY.CODE' => ['MODEL', 'COMFORT_CATEGORY', 'DRIVER'],
                '=IBLOCK_ELEMENT.IBLOCK_ID' => $iblockId,
            ],
            'select' => ['IBLOCK_ELEMENT_ID', 'VALUE', 'IBLOCK_PROPERTY.CODE']
        ]);

        while ($prop = $props->fetch()) {
            $id = (int)$prop['IBLOCK_ELEMENT_ID'];
            $cars[$id]['ID'] = $id;
            $cars[$id][$prop['IBLOCK_PROPERTY_CODE']] = $prop['VALUE'];
        }

        // фильтруем по категории
        return array_filter($cars, fn($car) =>
            isset($car['COMFORT_CATEGORY']) &&
            in_array($car['COMFORT_CATEGORY'], $categoryIds)
        );
    }

    /**
     * Фильтрация свободных машин по расписанию (проверка машины и водителя)
     */
    private function filterFreeCars(array $cars, DateTime $start, DateTime $end): array
    {
        $entityClass = $this->getHlEntity(self::HL_SCHEDULE);
        if (!$entityClass) {
            return $cars;
        }

        $carIds = array_column($cars, 'ID');
        $driverIds = array_column($cars, 'DRIVER');

        $booked = $entityClass::getList([
            'filter' => [
                [
                    'LOGIC' => 'OR',
                    ['UF_CAR_ID' => $carIds],
                    ['UF_DRIVER_ID' => $driverIds],
                ],
                [
                    'LOGIC' => 'AND',
                    ['<=UF_START_TIME' => $end],
                    ['>=UF_END_TIME' => $start],
                ],
            ],
            'select' => ['UF_CAR_ID', 'UF_DRIVER_ID']
        ])->fetchAll();

        $busyCars = array_column($booked, 'UF_CAR_ID');
        $busyDrivers = array_column($booked, 'UF_DRIVER_ID');

        $free = [];
        foreach ($cars as $id => $car) {
            if (!in_array($id, $busyCars) && !in_array($car['DRIVER'], $busyDrivers)) {
                $driver = $car['DRIVER']
                    ? UserTable::getById($car['DRIVER'])->fetch()
                    : null;

                $free[$id] = [
                    'MODEL' => $car['MODEL'] ?? '—',
                    'CATEGORY_ID' => $car['COMFORT_CATEGORY'],
                    'DRIVER' => $driver ? trim($driver['NAME'] . ' ' . $driver['LAST_NAME']) : '—',
                ];
            }
        }

        return $free;
    }

    /**
     * Получить ID инфоблока по коду
     */
    private function getIblockIdByCode(string $code): ?int
    {
        $iblock = IblockTable::getList([
            'filter' => ['=CODE' => $code],
            'select' => ['ID'],
        ])->fetch();

        return $iblock ? (int)$iblock['ID'] : null;
    }
    /**
     * Получить HLBL
     */
    private function getHlEntity(string $code): ?string
    {
        $hl = HighloadBlockTable::getList([
            'filter' => ['=NAME' => $code],
            'select' => ['*']
        ])->fetch();

        return $hl ? HighloadBlockTable::compileEntity($hl)->getDataClass() : null;
    }
}