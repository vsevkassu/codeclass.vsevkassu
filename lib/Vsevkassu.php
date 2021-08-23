<?php
/**
 * Модуль печати кассовых чеков для Битрикс Управление Сайтом
 * сервис Все в кассу
 * https://vsevkassu.ru
 */

namespace Codeclass\Vsevkassu;

use Bitrix\Catalog\VatTable;
use Bitrix\Main\Loader;
use Bitrix\Sale\Cashbox\CheckManager;
use Bitrix\Sale\Cashbox\Internals\CashboxTable;
use Bitrix\Sale\Result;
use Bitrix\Main\Error;
use Bitrix\Sale\Cashbox\AbstractCheck;
use Bitrix\Sale\Cashbox\AdvancePaymentCheck;
use Bitrix\Sale\Cashbox\AdvanceReturnCashCheck;
use Bitrix\Sale\Cashbox\AdvanceReturnCheck;
use Bitrix\Sale\Cashbox\Cashbox;
use Bitrix\Sale\Cashbox\CreditCheck;
use Bitrix\Sale\Cashbox\CreditPaymentCheck;
use Bitrix\Sale\Cashbox\CreditReturnCheck;
use Bitrix\Sale\Cashbox\FullPrepaymentCheck;
use Bitrix\Sale\Cashbox\FullPrepaymentReturnCashCheck;
use Bitrix\Sale\Cashbox\FullPrepaymentReturnCheck;
use Bitrix\Sale\Cashbox\IPrintImmediately;
use Bitrix\Sale\Cashbox\ICheckable;
use Bitrix\Sale\Cashbox\Check;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\Cashbox\PrepaymentCheck;
use Bitrix\Sale\Cashbox\PrepaymentReturnCashCheck;
use Bitrix\Sale\Cashbox\PrepaymentReturnCheck;
use Bitrix\Sale\Cashbox\SellCheck;
use Bitrix\Sale\Cashbox\SellReturnCashCheck;
use Bitrix\Sale\Cashbox\SellReturnCheck;
use vsevkassu\sdk\Item;
use vsevkassu\sdk\Receipt;
use vsevkassu\sdk\ReceiptFilter;
use vsevkassu\sdk\VsevkassuAPI;

class Vsevkassu extends Cashbox implements IPrintImmediately, ICheckable
{
    const DEBUG = false;
    const DEBUG_API = false;

    private $_api;

    /**
     * @return string
     */
    public static function getName()
    {
        // название обработчика
        return Loc::getMessage('CC_VSEVKASSU_CASHBOX_CUSTOM_TITLE');
    }

    public static function getSettings($modelId = 0)
    {
        $settings = [];

        $settings['CASHBOX'] = [
            'LABEL' => Loc::getMessage('CC_VSEVKASSU_CASHBOX_SETTINGS'),
            'REQUIRED' => 'Y',
            'ITEMS' => [
                'TOKEN' => [
                    'TYPE' => 'STRING',
                    'LABEL' => Loc::getMessage('CC_VSEVKASSU_CASHBOX_FIELD_TOKEN'),
                    'VALUE' => '',
                ],
                'IS_PRINT' => [
                    'TYPE' => 'Y/N',
                    'LABEL' => Loc::getMessage('CC_VSEVKASSU_CASHBOX_FIELD_IS_PRINT'),
                    'VALUE' => true,
                ],
                'PREFIX' => [
                    'TYPE' => 'STRING',
                    'LABEL' => Loc::getMessage('CC_VSEVKASSU_CASHBOX_FIELD_PREFIX'),
                    'VALUE' => 'bx_',
                ],
            ]
        ];


        $vatValues = [
            Item::NDS_TYPE_WO => Loc::getMessage('CC_VSEVKASSU_CASHBOX_NDS_TYPE_WO'),
            Item::NDS_TYPE_20 => Loc::getMessage('CC_VSEVKASSU_CASHBOX_NDS_TYPE_20'),
            Item::NDS_TYPE_10 => Loc::getMessage('CC_VSEVKASSU_CASHBOX_NDS_TYPE_10'),
            Item::NDS_TYPE_0 => Loc::getMessage('CC_VSEVKASSU_CASHBOX_NDS_TYPE_0'),
            Item::NDS_TYPE_110 => Loc::getMessage('CC_VSEVKASSU_CASHBOX_NDS_TYPE_110'),
            Item::NDS_TYPE_120 => Loc::getMessage('CC_VSEVKASSU_CASHBOX_NDS_TYPE_120'),
        ];

        $settings['VAT'] = [
            'LABEL' => Loc::getMessage('CC_VSEVKASSU_CASHBOX_NDS_SETTINGS'),
            'REQUIRED' => 'Y',
            'ITEMS' => [
                'NOT_VAT' => [
                    'TYPE' => 'ENUM',
                    'LABEL' => Loc::getMessage('CC_VSEVKASSU_CASHBOX_NDS_WO_DEFAULT'),
                    'VALUE' => Item::NDS_TYPE_WO,
                    'OPTIONS' => $vatValues
                ]
            ]
        ];

        if (Loader::includeModule('catalog')) {
            $dbRes = VatTable::getList(array('filter' => array('ACTIVE' => 'Y')));
            $vatList = $dbRes->fetchAll();
            if ($vatList) {
                foreach ($vatList as $vat) {
                    $value = '';

                    //if (isset($defaultSettings['VAT'][(int)$vat['RATE']]))
                    //    $value = $defaultSettings['VAT'][(int)$vat['RATE']];

                    $settings['VAT']['ITEMS'][(int)$vat['ID']] = array(
                        'TYPE' => 'ENUM',
                        'LABEL' => $vat['NAME'] . ' [' . (int)$vat['RATE'] . '%]',
                        'VALUE' => $value,
                        'OPTIONS' => $vatValues
                    );
                }
            }
        }


        return $settings;
    }

    public static function getGeneralRequiredFields()
    {
        $generalRequiredFields = parent::getGeneralRequiredFields();

        $map = CashboxTable::getMap();

        $generalRequiredFields['NUMBER_KKM'] = $map['NUMBER_KKM']['title'];

        return $generalRequiredFields;
    }

    /**
     * @return bool
     */
    public static function isSupportedFFD105()
    {
        return true;
    }

    /**
     * @param array $data
     * @return array
     */
    protected static function extractZReportData(array $data)
    {
        return array();
    }

    /**
     * @param $id
     * @return array
     */
    public function buildZReportQuery($id)
    {
        // построение запроса на печать z-отчета
        // если печать z-отчета не требуется, возвращается пустой массив
        return array();
    }

    public function printImmediately(Check $check)
    {
        // алгоритм отправки чека на печать
        $data = $this->buildCheckQuery($check);
        $Receipt = Receipt::fromData($data);

        $printResult = new Result();

        try {
            $api = $this->_getApi();
            $Receipt = $api->saveReceipt($Receipt);

            $printResult->setData(array('UUID' => $Receipt->id));

        } catch (\Exception $e) {
            $printResult->addError(new Error($e->getMessage()));
        }

        return $printResult;
    }

    /**
     * @param Check $check
     * @return array
     */
    public function buildCheckQuery(Check $check)
    {
        // построение запроса с информацией по чеку

        $data = $check->getDataForCheck();

        $result = [
            'external_id' => $this->getValueFromSettings('CASHBOX', 'PREFIX') . $data['unique_id'],
            'is_print' => ($this->getValueFromSettings('CASHBOX', 'IS_PRINT') == 'Y'),
            'salepoint_id' => $this->getField('NUMBER_KKM'),
            'type' => self::getReceiptType($check::getType()),
            'created_at' => $data['date_create']->format("Y-m-d H:i:s"),
            'buyer' => [
                'name' => '', //@TODO get name from order
                'contact' => (empty($data['client_email'])) ? (empty($data['client_phone']) ? '' : $data['client_phone']) : $data['client_email']
            ],
            'items' => [],
            'payment' => [
                'sum_nal' => 0,
                'sum_bn' => 0,
                'sum_prepaid' => 0,
                'sum_postpaid' => 0,
                'full_sum' => $data['total_sum']
            ]
        ];

        foreach ($data['items'] as $item) {

            $vat = $this->getValueFromSettings('VAT', $item['vat']);
            if ($vat === null)
                $vat = $this->getValueFromSettings('VAT', 'NOT_VAT');

            $item_result = [
                'item_type' => self::getItemType($item['payment_object']),
                'pay_type' => self::getItemPayType($check::getType()),
                'name' => $item['name'],
                'price' => $item['price'],
                'quantity' => $item['quantity'],
                'nds_type' => $vat
            ];

            $result['items'][] = $item_result;
        }

        foreach ($data['payments'] as $payment) {
            switch ($payment['type']) {
                case Check::PAYMENT_TYPE_CASH :
                    $result['payment']['sum_nal'] += $payment['sum'];
                    break;
                case Check::PAYMENT_TYPE_CASHLESS :
                    $result['payment']['sum_bn'] += $payment['sum'];
                    break;
                case Check::PAYMENT_TYPE_ADVANCE :
                    $result['payment']['sum_prepaid'] += $payment['sum'];
                    break;
                case Check::PAYMENT_TYPE_CREDIT :
                    $result['payment']['sum_postpaid'] += $payment['sum'];
                    break;
            }
        }

        $this->Log(['message' => 'check query', 'data' => $result]);

        return $result;
    }

    public static function getReceiptType($check_type)
    {
        $receipt_type_map = [
            SellCheck::getType() => Receipt::TYPE_INCOME,
            SellReturnCashCheck::getType() => Receipt::TYPE_INCOME_RETURN,
            SellReturnCheck::getType() => Receipt::TYPE_INCOME_RETURN,
            AdvancePaymentCheck::getType() => Receipt::TYPE_INCOME,
            AdvanceReturnCashCheck::getType() => Receipt::TYPE_INCOME_RETURN,
            AdvanceReturnCheck::getType() => Receipt::TYPE_INCOME_RETURN,
            PrepaymentCheck::getType() => Receipt::TYPE_INCOME,
            PrepaymentReturnCheck::getType() => Receipt::TYPE_INCOME_RETURN,
            PrepaymentReturnCashCheck::getType() => Receipt::TYPE_INCOME_RETURN,
            FullPrepaymentCheck::getType() => Receipt::TYPE_INCOME,
            FullPrepaymentReturnCheck::getType() => Receipt::TYPE_INCOME_RETURN,
            FullPrepaymentReturnCashCheck::getType() => Receipt::TYPE_INCOME_RETURN,
            CreditCheck::getType() => Receipt::TYPE_INCOME,
            CreditReturnCheck::getType() => Receipt::TYPE_INCOME_RETURN,
            CreditPaymentCheck::getType() => Receipt::TYPE_INCOME_RETURN,
        ];
        return $receipt_type_map[$check_type] ?? false;
    }

    public static function getItemType($check_item_type)
    {
        $item_type_map = [
            Check::PAYMENT_OBJECT_COMMODITY => Item::ITEM_TYPE_PROD,
            Check::PAYMENT_OBJECT_EXCISE => Item::ITEM_TYPE_EXCIZE_PROD,
            Check::PAYMENT_OBJECT_JOB => Item::ITEM_TYPE_WORK,
            Check::PAYMENT_OBJECT_SERVICE => Item::ITEM_TYPE_SERVICE,
            Check::PAYMENT_OBJECT_PAYMENT => Item::ITEM_TYPE_PAYMENT,
            //Check::PAYMENT_OBJECT_GAMBLING_BET => ,
            //Check::PAYMENT_OBJECT_GAMBLING_PRIZE => ,
            //Check::PAYMENT_OBJECT_LOTTERY => ,
            //Check::PAYMENT_OBJECT_LOTTERY_PRIZE => ,
            Check::PAYMENT_OBJECT_INTELLECTUAL_ACTIVITY => Item::ITEM_TYPE_RID,
            //Check::PAYMENT_OBJECT_AGENT_COMMISSION => ,
            //Check::PAYMENT_OBJECT_COMPOSITE => ,
            Check::PAYMENT_OBJECT_ANOTHER => Item::ITEM_TYPE_OTHER,
            //Check::PAYMENT_OBJECT_PROPERTY_RIGHT => ,
            //Check::PAYMENT_OBJECT_NON_OPERATING_GAIN => ,
            //Check::PAYMENT_OBJECT_SALES_TAX => ,
            //Check::PAYMENT_OBJECT_RESORT_FEE => ,
        ];

        return $item_type_map[$check_item_type] ?? false;
    }

    public static function getItemPayType($check_type)
    {
        $item_pay_type_map = [
            SellCheck::getType() => Item::PAY_TYPE_FULL,
            SellReturnCashCheck::getType() => Item::PAY_TYPE_FULL,
            SellReturnCheck::getType() => Item::PAY_TYPE_FULL,
            AdvancePaymentCheck::getType() => Item::PAY_TYPE_ADVANCE,
            AdvanceReturnCashCheck::getType() => Item::PAY_TYPE_ADVANCE,
            AdvanceReturnCheck::getType() => Item::PAY_TYPE_ADVANCE,
            PrepaymentCheck::getType() => Item::PAY_TYPE_PREPAID,
            PrepaymentReturnCheck::getType() => Item::PAY_TYPE_PREPAID,
            PrepaymentReturnCashCheck::getType() => Item::PAY_TYPE_PREPAID,
            FullPrepaymentCheck::getType() => Item::PAY_TYPE_PREPAID100,
            FullPrepaymentReturnCheck::getType() => Item::PAY_TYPE_PREPAID100,
            FullPrepaymentReturnCashCheck::getType() => Item::PAY_TYPE_PREPAID100,
            CreditCheck::getType() => Item::PAY_TYPE_CREDIT,
            CreditReturnCheck::getType() => Item::PAY_TYPE_CREDIT,
            CreditPaymentCheck::getType() => Item::PAY_TYPE_CREDIT_REPAYMENT,
        ];

        return $item_pay_type_map[$check_type] ?? false;
    }

    public static function Log($var)
    {
        if (self::DEBUG) {
            $fo = fopen(__DIR__ . '/vsevkassu.log', 'a');
            fwrite($fo, date('d.m.Y H:i:s') . ' : ');
            foreach ($var as $k => $v) {
                if (is_string($v)) {
                    fwrite($fo, " ($k)  $v" . PHP_EOL);
                } else {
                    fwrite($fo, "======= $k ========" . PHP_EOL);
                    fwrite($fo, print_r($v, true) . PHP_EOL);
                }
            }
            fclose($fo);
        }
    }

    private function _getApi()
    {
        if (!isset($this->_api)) {
            $this->_api = new VsevkassuAPI();

            if (self::DEBUG_API) {
                $this->_api->setHost('http://dev.vsevkassu.ru/api/v1');
            }

            $this->_api->token = $this->getValueFromSettings('CASHBOX', 'TOKEN');
        }

        return $this->_api;
    }

    public function check(Check $check)
    {
        // алгоритм запроса состояния чека
        $EXTERNAL_UUID = $check->getField('EXTERNAL_UUID');
        $checkId = $this->getValueFromSettings('CASHBOX', 'PREFIX') . $check->getField('ID');

        $api = $this->_getApi();

        $Receipt = false;

        if ($EXTERNAL_UUID) {

            $Receipt = $api->findReceipt($EXTERNAL_UUID);

        } else {
            $Receipts = $api->findReceipts(ReceiptFilter::fromData([
                'external_id' => $checkId
            ]));

            if (count($Receipts) == 1) {
                $Receipt = array_shift($Receipts);
            }
        }

        $result = new Result();

        if (!$Receipt) {
            $result->addError(new Error(Loc::getMessage('CC_VSEVKASSU_CASHBOX_RECEIPT_NOT_FOUND')));
            return $result;
        }


        if (in_array($Receipt->status, [Receipt::STATUS_WAIT, Receipt::STATUS_SEND])) {
            $result->addError(new Error(Loc::getMessage('CC_VSEVKASSU_CASHBOX_RECEIPT_WAITING')));
            return $result;
        }

        //var_dump($Receipt);

        $data = self::extractCheckData(get_object_vars($Receipt));

        return CheckManager::savePrintResult($data['ID'], $data);
    }

    /**
     * @param array $data
     * @throws Main\NotImplementedException
     * @return array
     */
    protected static function extractCheckData(array $data)
    {
        // извлечение данных по чеку дальнейшего сохранения
        //self::Log(['message' => 'extractCheckData called', 'data' => $data]);
        $checkInfo = CheckManager::getCheckInfoByExternalUuid($data['id']);

        if (!$checkInfo)
            throw new \Exception(Loc::getMessage('CC_VSEVKASSU_CASHBOX_RECEIPT_NOT_FOUND'));

        if ($data['status'] == Receipt::STATUS_ERROR) {
            $errorMsg = $data['error'];

            $result['ERROR'] = array(
                'CODE' => $data['code'],
                'MESSAGE' => $errorMsg,
                'TYPE' => \Bitrix\Sale\Cashbox\Errors\Error::TYPE
            );
        }

        $result['ID'] = $checkInfo['ID'];
        $result['CHECK_TYPE'] = $checkInfo['TYPE'];

        $result['LINK_PARAMS'] = array(
            Check::PARAM_REG_NUMBER_KKT => $data['kkt_regnum'],
            Check::PARAM_FISCAL_DOC_ATTR => $data['sign'],
            Check::PARAM_FISCAL_DOC_NUMBER => $data['fd_number'],
            Check::PARAM_FISCAL_RECEIPT_NUMBER => $data['doc_number'],
            Check::PARAM_FN_NUMBER => $data['kkt_fs'],
            Check::PARAM_SHIFT_NUMBER => $data['shift'],
            Check::PARAM_DOC_SUM => $data['full_sum'],
            Check::PARAM_DOC_TIME => strtotime($data['fiscal_date'])
        );

        return $result;
    }

    public function getCheckLink(array $linkParams)
    {
        $queryParams = [
            'fp=' . $linkParams[Check::PARAM_FISCAL_DOC_ATTR],
            'fd=' . $linkParams[Check::PARAM_FISCAL_DOC_NUMBER],
        ];

        $url = (self::DEBUG_API) ? 'http://dev.vsevkassu.ru/receipt?' : 'https://vsevkassu.ru/receipt?';

        return $url . implode('&', $queryParams);
    }

}