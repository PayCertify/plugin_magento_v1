<?php
/**
 * PayCertify's extension for Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE_AFL.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category   PayCertify
 * @package    PayCertify_Gateway
 * @copyright  Copyright (c) 2018 PayCertify (https://www.paycertify.com/)
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @author     Valentin Sushkov <me@vsushkov.com>
 */

/**
 * PayCertify Gateway payment method
 *
 * @category   PayCertify
 * @package    PayCertify_Gateway
 * @author     Valentin Sushkov <me@vsushkov.com>
 */
class PayCertify_Gateway_Model_Method extends Mage_Payment_Model_Method_Cc
{
    protected $_code = 'paycertify_gateway';

    protected $_isGateway                  = true;
    protected $_canSaveCc                  = false;
    protected $_canOrder                   = false;
    protected $_canAuthorize               = false;
    protected $_canCapture                 = true;
    protected $_canCapturePartial          = false;
    protected $_canRefund                  = true;
    protected $_canRefundInvoicePartial    = true;
    protected $_canVoid                    = false;
    protected $_canUseInternal             = false;
    protected $_canUseCheckout             = true;
    protected $_canUseForMultishipping     = true;
    protected $_isInitializeNeeded         = false;
    protected $_canFetchTransactionInfo    = false;
    protected $_canReviewPayment           = false;
    protected $_canCreateBillingAgreement  = false;
    protected $_canManageRecurringProfiles = false;

    /**
     * Check whether payment method can be used
     *
     * It cannot be used if base currency is not USD
     *
     * @param Mage_Sales_Model_Quote|null $quote
     *
     * @return bool
     */
    public function isAvailable($quote = null)
    {
        $isBaseCurrencyUsd = in_array('USD', Mage::getSingleton('directory/currency')->getConfigBaseCurrencies());
        return $isBaseCurrencyUsd && parent::isAvailable($quote);
    }

    /**
     * Capture payment
     *
     * @param Varien_Object $payment
     * @param float         $amount
     *
     * @return PayCertify_Gateway_Model_Method
     */
    public function capture(Varien_Object $payment, $amount)
    {
        if (!$this->canCapture()) {
            Mage::throwException(Mage::helper('paycertify_gateway')->__('Capture action is not available.'));
        }

        if ($amount <= 0) {
            Mage::throwException(Mage::helper('paycertify_gateway')->__('Invalid amount for capture.'));
        }

        $this->_captureOnGateway($payment, $amount);

        return $this;
    }

    /**
     * Refund the amount with transaction id
     *
     * @param Varien_Object $payment
     * @param float         $amount
     *
     * @return PayCertify_Gateway_Model_Method
     */
    public function refund(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper('paycertify_gateway')->__('Invalid amount for refund.'));
        }

        if (!$transactionId = $payment->getParentTransactionId()) {
            Mage::throwException(Mage::helper('paycertify_gateway')->__('Invalid transaction ID.'));
        }

        $transactionRawData = $payment->getTransaction($transactionId)
            ->getAdditionalInformation(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS)
        ;

        if (!isset($transactionRawData['transaction.id'])
            || !$transactionId = $transactionRawData['transaction.id']
        ) {
            Mage::throwException(Mage::helper('paycertify_gateway')->__('Invalid transaction ID.'));
        }

        $client = $this->_getClient($this->getConfigData('gateway_refund_url') . "/$transactionId/refund");

        $client->setParameterPost(array(
            'amount' => $amount,
        ));

        $response = $client->request();
        $responseData = Zend_Json::decode($response->getBody());

        $payment->setTransactionAdditionalInfo(
            Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, $this->_formatTransactionInfo($responseData)
        );

        $lastEvent = $responseData['transaction']['events'][0];

        if (!$lastEvent['success']) {
            Mage::throwException($lastEvent['processor_message']);
        }

        $payment->setTransactionId($lastEvent['id']);

        return $this;
    }

    private function _captureOnGateway($payment, $amount)
    {
        $params = $this->_getCaptureParams($payment, $amount);

        $client = $this->_getClient($this->getConfigData('gateway_sale_url'));
        $client->setParameterPost($params);

        $response = $client->request();
        $responseData = Zend_Json::decode($response->getBody());

        $payment->setTransactionAdditionalInfo(
            Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, $this->_formatTransactionInfo($responseData)
        );

        if (isset($responseData['error'])) {
            Mage::throwException(Mage::helper('paycertify_gateway')->__(
                "We weren't able to process this card. Please contact your bank for more information."
            ));
        }

        if (!isset($responseData['transaction']['events'][0])) {
            return;
        }

        $lastEvent = $responseData['transaction']['events'][0];
        if (!$lastEvent['success']) {
            Mage::throwException(Mage::helper('paycertify_gateway')->__(
                "We weren't able to process this card. Please contact your bank for more information."
            ));
        }

        $payment->setTransactionId($lastEvent['id']);
    }

    private function _getCaptureParams($payment, $amount)
    {
        $params = array(
            'amount'                    => $amount,
            'card_number'               => $payment->getCcNumber(),
            'card_expiry_month'         => str_pad($payment->getCcExpMonth(), 2, '0', STR_PAD_LEFT),
            'card_expiry_year'          => $payment->getCcExpYear(),
            'card_cvv'                  => $payment->getCcCid(),
            'merchant_transaction_id'   => $payment->getOrder()->getIncrementId(),
            'first_name'                => $payment->getOrder()->getBillingAddress()->getFirstname(),
            'last_name'                 => $payment->getOrder()->getBillingAddress()->getLastname(),
            'email'                     => $payment->getOrder()->getBillingAddress()->getEmail(),
            'street_address_1'          => $payment->getOrder()->getBillingAddress()->getStreet(1),
            'street_address_2'          => $payment->getOrder()->getBillingAddress()->getStreet(2),
            'city'                      => $payment->getOrder()->getBillingAddress()->getCity(),
            'state'                     => $this->_getState($payment->getOrder()->getBillingAddress()),
            'country'                   => $payment->getOrder()->getBillingAddress()->getCountryModel()->getIso2Code(),
            'zip'                       => $payment->getOrder()->getBillingAddress()->getPostcode(),
            'shipping_street_address_1' => $payment->getOrder()->getShippingAddress()->getStreet(1),
            'shipping_street_address_2' => $payment->getOrder()->getShippingAddress()->getStreet(2),
            'shipping_city'             => $payment->getOrder()->getShippingAddress()->getCity(),
            'shipping_state'            => $this->_getState($payment->getOrder()->getShippingAddress()),
            'shipping_country'          => $payment->getOrder()->getShippingAddress()->getCountryModel()->getIso2Code(),
            'shipping_zip'              => $payment->getOrder()->getShippingAddress()->getPostcode(),
        );

        if ($this->getConfigData('use_avs')) {
            $params['avs_enabled'] = true;
        }

        if ($this->getConfigData('dynamic_descriptor')) {
            $params['dynamic_descriptor'] = $this->getConfigData('dynamic_descriptor');
        }

        return $params;
    }

    private function _getClient($url)
    {
        $client = new Zend_Http_Client($url);
        $client->setMethod(Zend_Http_Client::POST)
            ->setEncType(Zend_Http_Client::ENC_FORMDATA)
            ->setHeaders('Authorization', 'Bearer ' . $this->getConfigData('api_token'))
        ;
        return $client;
    }

    private function _getState($address)
    {
        if ($address->getCountryModel()->getIso2Code() == 'US') {
            return $address->getRegionCode();
        } else {
            return '';
        }
    }

    protected function _formatTransactionInfo($data, $prefix = '')
    {
        $result = array();
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result = $result + $this->_formatTransactionInfo($value, $prefix . $key . '.');
            } else {
                $result[$prefix . $key] = $value;
            }
        }
        return $result;
    }

}
