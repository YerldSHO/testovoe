<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;

class AvailableCarsComponent extends CBitrixComponent
{
    protected $userId;
    protected $start;
    protected $end;
    private const HLBL_CODE = 'Schedule';

    /*
        📘 Предполагаемая структура данных:

        🧩 Инфоблоки (IBLOCKS):
        1. Должности (Positions)
           - ID (int) — идентификатор должности
           - NAME (string) — название должности
           - COMFORT_CATEGORIES (multiple link) — привязка к "Категории комфорта"

        2. Автомобили (Cars)
           - ID (int) — идентификатор автомобиля
           - NAME (string) — модель автомобиля
           - COMFORT_CATEGORY (link) — привязка к категории комфорта
           - DRIVER (link to user) — водитель, закреплённый за машиной

        3. Категории комфорта (ComfortCategories)
           - ID (int) — идентификатор категории
           - NAME (string) — название категории (Первая, Вторая и т.д.)

        🚀 Highload-блок (HLBL):
        Schedule
           - UF_CAR_ID (string/int) — ID автомобиля
           - UF_DRIVER_ID (int) — ID водителя
           - UF_PASSENGER_ID (int) — ID сотрудника, который поедет
           - UF_START_TIME (datetime) — время начала поездки
           - UF_END_TIME (datetime) — время окончания поездки

        👤 Пользователи (TABLE: b_user)
           - ID (int)
           - UF_JOB (link to IBLOCK Positions)

        ⚙️ Логика связей:
        - Каждый пользователь имеет должность (UF_JOB) (Стандарт Битрикс24)
        - Должность определяет доступные категории комфорта
        - Каждая машина относится к одной категории комфорта
        - Каждая машина имеет только одного водителя
        - Один водитель может быть закреплён только за одной машиной
        - В расписании (Schedule) фиксируются занятые машины на определённый интервал времени

        📅 Условия работы компонента:
        - Пользователь передаёт GET-параметры ?start=HH:MM:SS&end=HH:MM:SS
        - Компонент определяет должность пользователя → категории комфорта → машины этих категорий
        - Из списка машин исключаются те, что уже заняты в указанный интервал времени
        - Результат — список доступных для бронирования автомобилей
    */


    public function executeComponent(): void
    {
        try {
            $this->initModules();
            $this->getUser();
            $this->parseTimes();

            $positionId = $this->getUserPositionId();
            if (!$positionId) {
                throw new \DomainException('Для пользователя не задана должность (UF_JOB).');
            }

            $comfortIds = $this->getCategoryIds($positionId);
            if (empty($comfortIds)) {
                $this->viewResult(
                    []
                );
                return;
            }

            $cars = $this->getCarsByComfortIds($comfortIds);
            if (empty($cars)) {
                $this->viewResult(
                    []
                );
                return;
            }

            $busyCars = $this->getBusyCarsAndDrivers($this->start, $this->end);

            // Финальный вывод
            $this->viewResult(
                $this->filterAvailableCars($cars, $busyCars)
            );

        } catch (\Exception $e) {
            print_r($e->getMessage());
        }
    }

    protected function getUserPositionId(): ?int
    {
        $user = \CUser::GetByID($this->userId)->fetch();

        if (!$user) {
            return null;
        }

        return $user['UF_JOB'] ?? null;
    }

    protected function getCategoryIds(int $positionId): array
    {
        $categories = [];

        $categoryQuery = \Bitrix\Iblock\Elements\ElementPositionsTable::getList([
            'filter' => [
                'ACTIVE' => 'Y',
                '=ID' => $positionId
            ],
            'select' => [
                'CATEGORY_ID' => 'COMFORT_CATEGORIES.VALUE'
            ],
        ])->fetchAll();

        foreach ($categoryQuery as $category) {
            if (!in_array($category['CATEGORY_ID'], $categories)) {
                $categories[] = (int)$category['CATEGORY_ID'];
            }
        }

        return $categories;
    }

    protected function getCarsByComfortIds(array $comfortIds): array
    {
        return \Bitrix\Iblock\Elements\ElementCarsTable::getList([
            'filter' => [
                'ACTIVE' => 'Y',
                '=CATEGORY_ID' => $comfortIds
            ],
            'select' => [
                'ID',
                'NAME',
                'DRIVE_ID' => 'DRIVER.VALUE',
                'CATEGORY_ID' => 'COMFORT_CATEGORY.VALUE'
            ],
        ])->fetchAll();
    }

    protected function getBusyCarsAndDrivers(DateTime $start, DateTime $end): array
    {
        $hlblId = $this->getHLBLIdByCode();
        $hlblock = \Bitrix\Highloadblock\HighloadBlockTable::getById($hlblId)->fetch();
        $entity = \Bitrix\Highloadblock\HighloadBlockTable::compileEntity($hlblock);
        $entityDataClass = $entity->getDataClass();

        return $entityDataClass::getList([
            'select' => ['UF_CAR_ID', 'UF_DRIVER_ID'],
            'filter' => [
                [
                    'LOGIC' => 'AND',
                    ['<UF_START_TIME' => $end],
                    ['>UF_END_TIME' => $start],
                ],
            ],
        ])->fetchAll();
    }

    protected function getHLBLIdByCode(): string
    {
        $result = \Bitrix\Highloadblock\HighloadBlockTable::getList([
            'filter' => [
                '=NAME' => $this::HLBL_CODE
            ],
            'select' => [
                'ID'
            ]
        ]);

        return $result->fetch()['ID'];
    }

    protected function filterAvailableCars(array $cars, array $busyCars): array
    {
        if (empty($busyCars)) {
            return $cars;
        }

        $busyCarIds = array_column($busyCars, 'UF_CAR_ID');

        return array_values(array_filter($cars, function ($car) use ($busyCarIds) {
            return !in_array($car['ID'], $busyCarIds);
        }));
    }

    private function initModules(): void
    {
        if (!Loader::includeModule('iblock')) {
            throw new SystemException('Модуль iblock не подключён');
        }
        if (!Loader::includeModule('highloadblock')) {
            throw new SystemException('Модуль highloadblock не подключён');
        }
    }

    private function getUser(): void
    {
        $this->userId = \Bitrix\Main\Engine\CurrentUser::get()->getId();
        if (!$this->userId) {
            throw new SystemException('Пользователь не авторизован');
        }
    }

    private function parseTimes(): void
    {
        $startTime = trim($_GET['start'] ?? '');
        $endTime = trim($_GET['end'] ?? '');

        $currentDate = (new DateTime())->format("Y-m-d");

        if (!$startTime || !$endTime) {
            throw new ArgumentException('Не заданы параметры start или end в GET запросе');
        }

        $this->start = new DateTime($currentDate . ' ' . $startTime, 'Y-m-d H:i:s');
        $this->end = new DateTime($currentDate . ' ' . $endTime, 'Y-m-d H:i:s');

        if ($this->end->getTimestamp() <= $this->start->getTimestamp()) {
            throw new ArgumentException('Параметр end должен быть позже start.');
        }
    }

    private function viewResult(mixed $text): void
    {
        echo "<pre>";
        print_r($text);
        echo "</pre>";
    }
}
