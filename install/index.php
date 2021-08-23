<?
/**
 * Модуль печати кассовых чеков для Битрикс Управление Сайтом
 * сервис Все в кассу
 * https://vsevkassu.ru
 */

IncludeModuleLangFile(__FILE__);

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\EventManager;

Class codeclass_vsevkassu extends CModule
{
    public $MODULE_ID = 'codeclass.vsevkassu';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $MODULE_GROUP_RIGHTS;

    function __construct()
    {
        $arModuleVersion = array();

        include __DIR__ . '/version.php';

        if (is_array($arModuleVersion) && array_key_exists('VERSION', $arModuleVersion)) {
            $this->MODULE_VERSION = $arModuleVersion['VERSION'];
            $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        }

        $this->MODULE_NAME = Loc::getMessage('CC_VSEVKASSU_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('CC_VSEVKASSU_MODULE_DESCRIPTION');
        $this->MODULE_GROUP_RIGHTS = 'N';
        $this->PARTNER_NAME = Loc::getMessage('CC_VSEVKASSU_MODULE_PARTNER_NAME');
        $this->PARTNER_URI = Loc::getMessage('CC_VSEVKASSU_MODULE_PARTNER_URI');
    }

    public function DoInstall()
    {
        $this->InstallFiles();
        $this->InstallEvents();
        RegisterModule($this->MODULE_ID);
    }

    public function InstallFiles()
    {

        return true;
    }

    public function InstallEvents()
    {
        EventManager::getInstance()->registerEventHandler("sale", "OnGetCustomCashboxHandlers", $this->MODULE_ID, "Codeclass\Vsevkassu\CVsevkassu", "registerMainClass");
        return true;
    }

    public function DoUninstall()
    {
        $this->UnInstallDB();
        $this->UnInstallFiles();
        $this->UnInstallEvents();
        UnRegisterModule($this->MODULE_ID);
    }

    public function UnInstallDB()
    {
        if (\Bitrix\Main\Loader::includeModule('sale')) {
            // Битрикс показывает ошибку вместо списка касс,
            // если касса активна и система не может найти ее обработчик.
            // Найдем все наши кассы и деактивируем их не удаляя данные.

            // Запрос на получение списка касс с обработчиком этого модуля

            $dbRes = \Bitrix\Sale\Cashbox\Internals\CashboxTable::getList(
                array(
                    'select' => array('ID', 'HANDLER'),
                    'filter' => array('%HANDLER' => 'Codeclass\Vsevkassu\Vsevkassu'),
                )
            );

            // Получаем кассы
            while ($cashbox = $dbRes->fetch()) {
                // Отключаем каждую кассу
                \Bitrix\Sale\Cashbox\Manager::update($cashbox['ID'], ['ACTIVE' => 'N']);
            }
        }

        return true;
    }

    public function UnInstallFiles()
    {
        return true;
    }

    public function UnInstallEvents()
    {
        EventManager::getInstance()->unRegisterEventHandler("sale", "OnGetCustomCashboxHandlers", $this->MODULE_ID, "Codeclass\Vsevkassu\CVsevkassu", "registerMainClass");
        return true;
    }
}

