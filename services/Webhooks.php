<?php

namespace SnipWire\Services;

/**
 * Webhooks - service class for SnipWire to provide webhooks for Snipcart.
 * (This file is part of the SnipWire package)
 *
 * Replaces the ProcessWire page rendering as a whole. It will only accept 
 * POST request from Snipcart. 
 * 
 * Licensed under MPL 2.0 (see LICENSE file provided with this package)
 * Copyright 2023 by Martin Gartner
 *
 * ProcessWire 3.x, Copyright Ryan Cramer
 * https://processwire.com
 *
 * ---
 *
 * Hookable event handler methods:
 *
 * All hookable event handler methods will return an array containing payload Snipcart sent to your endpoint.
 * In addition, the following class properties will be set:
 *
 * $this->payload (The payload Snipcart sent to your endpoint)
 * $this->responseStatus (The response status your endpoint sent to Snipcart)
 * $this->responseBody (The response body your endpoint sent to Snipcart)
 *
 * (Use the appropriate getter methods to receive these values!)
 *
 * How to use the hookable event handler methods (sample):
 * ~~~~~
 * $webhooks->addHookAfter('handleOrderCompleted', function($event) {
 *     $payload = $event->return;
 *     //... your code here ...
 * }); 
 * ~~~~~
 *
 * PLEASE NOTE: those hooks will currently only work when placed in init.php or init() or ready() module methods!
 *
 */

\ProcessWire\wire('classLoader')->addNamespace('SnipWire\Helpers', dirname(__DIR__) . '/helpers');

use SnipWire\Helpers\CurrencyFormat;
use SnipWire\Helpers\Taxes;
use SnipWire\Services\SnipREST;
use ProcessWire\WireData;
use ProcessWire\WireException;

class Webhooks extends WireData
{

    const snipWireWebhooksLogName = 'snipwire-webhooks';
    const snipcartRequestTokenServerVar = 'HTTP_X_SNIPCART_REQUESTTOKEN';

    // Snipcart webhook events
    const webhookOrderCompleted = 'order.completed';
    const webhookOrderStatusChanged = 'order.status.changed';
    const webhookOrderNotificationCreated = 'order.notification.created';
    const webhookOrderPaymentStatusChanged = 'order.paymentStatus.changed';
    const webhookOrderTrackingNumberChanged = 'order.trackingNumber.changed';
    const webhookOrderRefundCreated = 'order.refund.created';
    const webhookSubscriptionCreated = 'subscription.created';
    const webhookSubscriptionCancelled = 'subscription.cancelled';
    const webhookSubscriptionPaused = 'subscription.paused';
    const webhookSubscriptionResumed = 'subscription.resumed';
    const webhookSubscriptionInvoiceCreated = 'subscription.invoice.created';
    const webhookShippingratesFetch = 'shippingrates.fetch';
    const webhookTaxesCalculate = 'taxes.calculate';
    const webhookCustomerUpdated = 'customauth:customer_updated'; // not documented

    const webhookModeLive = 'Live';
    const webhookModeTest = 'Test';

    /** @var SnipWire $snipwireConfig The module config of SnipWire module */
    protected $snipwireConfig = null;

    /** @var boolean Turn on/off debug mode for Webhooks class */
    private $debug = false;

    /** @var boolean Indicates if the local development environment is being used */
    private $localDev = false;

    /** @var string $serverProtocol The server protocol (e.g. HTTP/1.1) */
    protected $serverProtocol = '';

    /** @var array $webhookEventsIndex All available webhook events */
    protected $webhookEventsIndex = [];

    /** @var string $event The current Snipcart event */
    protected $event = '';

    /** @var string $rawPayload The current raw POST input */
    protected $rawPayload = '';

    /** @var array $payload The current JSON decoded POST input */
    protected $payload = null;

    /** @var integer $responseStatus The response status code for SnipCart */
    private $responseStatus = null;

    /** @var string (JSON) $responseBody The JSON formatted response array for Snipcart */
    private $responseBody = '';

    /**
     * Set class properties.
     *
     * @throws WireException
     */
    public function __construct()
    {
        $this->webhookEventsIndex = [
            self::webhookOrderCompleted => 'handleOrderCompleted',
            self::webhookOrderStatusChanged => 'handleOrderStatusChanged',
            self::webhookOrderNotificationCreated => 'handleOrderNotificationCreated',
            self::webhookOrderPaymentStatusChanged => 'handleOrderPaymentStatusChanged',
            self::webhookOrderTrackingNumberChanged => 'handleOrderTrackingNumberChanged',
            self::webhookOrderRefundCreated => 'handleOrderRefundCreated',
            self::webhookSubscriptionCreated => 'handleSubscriptionCreated',
            self::webhookSubscriptionCancelled => 'handleSubscriptionCancelled',
            self::webhookSubscriptionPaused => 'handleSubscriptionPaused',
            self::webhookSubscriptionResumed => 'handleSubscriptionResumed',
            self::webhookSubscriptionInvoiceCreated => 'handleSubscriptionInvoiceCreated',
            self::webhookShippingratesFetch => 'handleShippingratesFetch',
            self::webhookTaxesCalculate => 'handleTaxesCalculate',
            self::webhookCustomerUpdated => 'handleCustomerUpdated',
        ];

        // Get SnipWire module config.
        // (Holds merged data from DB and default config. 
        // This works because of using the ModuleConfig class)
        $this->snipwireConfig = $this->wire('modules')->get('SnipWire');
        $this->debug = (bool) $this->snipwireConfig->snipwire_debug;
        $this->localDev = (bool) $this->snipwireConfig->local_dev;
    }

    /**
     * Process webhooks requests.
     *
     * @return void
     * @throws WireException
     */
    public function process()
    {
        /** @var SnipREST $sniprest  */
        $sniprest = $this->wire('sniprest');
        $log = $this->wire('log');

        // Set default header
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');

        $this->serverProtocol = $_SERVER['SERVER_PROTOCOL'];

        if (!$this->_isValidRequest()) {
            if ($this->debug) {
                $log->save(
                    self::snipWireWebhooksLogName,
                    '[DEBUG] Invalid request - responseStatus = 404'
                );
            }
            // 404 Not Found
            header($this->serverProtocol . ' ' . $sniprest->getHttpStatusCodeString(404));
            return;
        }
        if (!$this->_hasValidRequestData()) {
            if ($this->debug) {
                $log->save(
                    self::snipWireWebhooksLogName,
                    '[DEBUG] Bad request (no valid request data) - responseStatus = 400'
                );
            }
            // 400 Bad Request 
            header($this->serverProtocol . ' ' . $sniprest->getHttpStatusCodeString(400));
            return;
        }
        $this->_handleWebhookData();

        header($this->serverProtocol . ' ' . $sniprest->getHttpStatusCodeString($this->responseStatus));
        if (!empty($this->responseBody)) {
            header('Content-Type: application/json; charset=utf-8');
            echo $this->responseBody;
        }

        if ($this->debug) {
            $headers = headers_list();
            $log->save(
                self::snipWireWebhooksLogName,
                '[DEBUG] Response headers: ' . json_encode($headers)
            );
            $log->save(
                self::snipWireWebhooksLogName,
                '[DEBUG] Webhooks request success: responseStatus = ' . $this->responseStatus
            );
            if ($this->responseBody) {
                $log->save(
                    self::snipWireWebhooksLogName,
                    '[DEBUG] Webhooks request success: responseBody = ' . $this->responseBody
                );
            }
        }
    }

    /**
     * Getter for payload.
     *
     * @return array The current payload
     *
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * Getter for responseStatus.
     *
     * @return integer The current response status code
     *
     */
    public function getResponseStatus()
    {
        return $this->responseStatus;
    }

    /**
     * Setter for responseStatus.
     *
     * @param int $responseStatus
     * @return void
     *
     */
    public function setResponseStatus(int $responseStatus)
    {
        $this->responseStatus = $responseStatus;
    }



    /**
     * Getter for responseBody.
     *
     * @return string The current response body (JSON formatted)
     *
     */
    public function getResponseBody()
    {
        return $this->responseBody;
    }

    /**
     * Setter for responseBody.
     *
     * @param string $responseBody
     * @return void
     *
     */
    public function setResponseBody(string $responseBody)
    {
        $this->responseBody = $responseBody;
    }


    /**
     * Getter for snipwireConfig.
     *
     * @return object The snipwireConfig object
     *
     */
    public function getSnipwireConfig()
    {
        return $this->snipwireConfig;
    }


    /**
     * Getter for debug.
     *
     * @return bool The current debug status
     *
     */
    public function getDebug(): bool
    {
        return (bool) $this->debug;
    }


    /**
     * Getter for localDev.
     *
     * @return bool The current local development environment status
     *
     */
    public function getLocalDev(): bool
    {
        return (bool) $this->localDev;
    }



    /**
     * Validate a Snipcart webhook endpoint request.
     * - check request method and content type
     * - check the request token (= handshake)
     *
     * @return boolean
     * @throws WireException
     */
    private function _isValidRequest()
    {
        $log = $this->wire('log');
        $this->rawPayload = file_get_contents('php://input');


        if ($this->debug) {
            $log->save(
                self::snipWireWebhooksLogName,
                '[DEBUG] $_SERVER: ' . json_encode($_SERVER)
            );
            $log->save(
                self::snipWireWebhooksLogName,
                '[DEBUG] payload: ' . json_encode($this->rawPayload)
            );
        }

        // Perform multiple checks for valid request
        if (
            $this->_isPostRequest() === false ||
            stripos($this->getServerVar('CONTENT_TYPE'), 'application/json') === false
        ) {
            $log->save(
                self::snipWireWebhooksLogName,
                $this->_('Invalid webhooks request: no POST data or content not json')
            );
            return false;
        }
        if (($requestToken = $this->getServerVar(self::snipcartRequestTokenServerVar)) === false) {
            $log->save(
                self::snipWireWebhooksLogName,
                $this->_('Invalid webhooks request: no request token')
            );
            return false;
        } else {
            if ($this->debug) {
                $log->save(
                    self::snipWireWebhooksLogName,
                    '[DEBUG] request token: ' . $requestToken
                );
            }
        }

        // perform handshake only if not on localDev
        if (!$this->localDev) {
            /** @var Sniprest $sniprest */
            $sniprest = $this->wire('sniprest');
            $handshakeUrl = $sniprest::apiEndpoint . $sniprest::resPathRequestValidation . '/' . $requestToken;
            if ($this->debug) {
                $log->save(
                    self::snipWireWebhooksLogName,
                    '[DEBUG] handshakeUrl: ' . $handshakeUrl
                );
            }

            if (($handshake = $sniprest->get($handshakeUrl)) === false) {
                $log->save(
                    self::snipWireWebhooksLogName,
                    $this->_('Snipcart REST connection for checking request token failed:') . ' ' . $sniprest->getError()
                );
                return false;
            }
            if ($this->debug) {
                $log->save(
                    self::snipWireWebhooksLogName,
                    '[DEBUG] handshake: ' . $handshake
                );
            }
            if (empty($handshake) || $sniprest->getHttpCode(false) != 200) {
                $log->save(
                    self::snipWireWebhooksLogName,
                    $this->_('Invalid webhooks request: no response')
                );
                return false;
            }
            $json = json_decode($handshake, true);
            if ($this->debug) {
                $log->save(
                    self::snipWireWebhooksLogName,
                    '[DEBUG] json: ' . json_encode($json)
                );
            }
            if (!$json) {
                $log->save(
                    self::snipWireWebhooksLogName,
                    $this->_('Invalid webhooks request: response not json')
                );
                return false;
            }
            if (!isset($json['token']) || $json['token'] !== $requestToken) {
                $log->save(
                    self::snipWireWebhooksLogName,
                    $this->_('Invalid webhooks request: invalid token')
                );
                return false;
            }
        }
        return true;
    }

    /**
     * Check if request is POST.
     * in different environment: apache/nginx
     * @return boolean
     *
     */
    private function _isPostRequest()
    {
        $requestMethod = $this->getServerVar('REQUEST_METHOD');
        $override = $this->getServerVar('HTTP_X_HTTP_METHOD_OVERRIDE');

        return $requestMethod === 'POST' || $override === 'POST';
    }

    /**
     * Check if request has valid data and set $payload and $event class properties if OK.
     *
     * @return boolean
     * @throws WireException
     */
    private function _hasValidRequestData()
    {
        $log = $this->wire('log');
        $payload = json_decode($this->rawPayload, true);

        if ($this->debug) $log->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request payload: ' . $this->rawPayload
        );

        // Perform multiple checks for valid request data
        $check = false;
        if (is_null($payload) || !is_array($payload)) {
            $log->save(
                self::snipWireWebhooksLogName,
                $this->_('Webhooks request: invalid request data - not an array')
            );
        } elseif (!isset($payload['eventName'])) {
            $log->save(
                self::snipWireWebhooksLogName,
                $this->_('Webhooks request: invalid request data - key eventName missing')
            );
        } elseif (!array_key_exists($payload['eventName'], $this->webhookEventsIndex)) {
            $log->save(
                self::snipWireWebhooksLogName,
                $this->_('Webhooks request: invalid request data - unknown event')
            );
        } elseif (!isset($payload['mode']) || !in_array($payload['mode'], array(self::webhookModeLive, self::webhookModeTest))) {
            $log->save(
                self::snipWireWebhooksLogName,
                $this->_('Webhooks request: invalid request data - wrong or missing mode')
            );
        } elseif (!isset($payload['content'])) {
            $log->save(
                self::snipWireWebhooksLogName,
                $this->_('Webhooks request: invalid request data - missing content')
            );
        } else {
            $this->event = $payload['eventName'];
            $this->payload = $payload;
            $check = true;
        }
        return $check;
    }

    /**
     * Route the request to the appropriate handler method.
     *
     * @throws WireException
     */
    private function _handleWebhookData()
    {
        $log = $this->wire('log');

        if (empty($this->event)) {
            $log->save(
                self::snipWireWebhooksLogName,
                $this->_('_handleWebhookData: $this->event not set')
            );
            $this->responseStatus = 500; // Internal Server Error
            return;
        }
        $methodName = $this->webhookEventsIndex[$this->event];
        if (!method_exists($this, '___' . $methodName)) {
            $log->save(
                self::snipWireWebhooksLogName,
                $this->_('_handleWebhookData: method does not exist') . ' ' . $methodName
            );
            $this->responseStatus = 500; // Internal Server Error
            return;
        }

        // Call the appropriate handler
        $this->{$methodName}();
    }

    //
    // Hookable event handler methods
    //

    /**
     * Webhook handler for order completed.
     * This event is triggered when a new order has been completed successfully.
     * It will contain the whole order details.
     *
     * @return array The payload sent by Snipcart
     * @throws WireException
     */
    public function ___handleOrderCompleted()
    {
        if ($this->debug) $this->wire('log')->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request: handleOrderCompleted'
        );
        $this->responseStatus = 202; // Accepted
        return $this->payload;
    }

    /**
     * Webhook handler for order status changed.
     * This event is triggered when the status of an order is changed from the dashboard or the API.
     * The payload will contain the original status along with the new status.
     * It will also contain the whole order details.
     *
     * @return array The payload sent by Snipcart
     * @throws WireException
     */
    public function ___handleOrderStatusChanged()
    {
        if ($this->debug) $this->wire('log')->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request: handleOrderStatusChanged'
        );
        $this->responseStatus = 202; // Accepted
        return $this->payload;
    }

    /**
     * Webhook handler for order notification created.
     * This event is triggered whenever a notification is added to an order.
     *
     * @return array The payload sent by Snipcart
     * @throws WireException
     */
    public function ___handleOrderNotificationCreated()
    {
        if ($this->debug) $this->wire('log')->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request: handleOrderNotificationCreated'
        );
        $this->responseStatus = 202; // Accepted
        return $this->payload;
    }

    /**
     * Webhook handler for payment status changed.
     * This event is triggered when the payment status of an order is changed from the dashboard or the API.
     * The payload will contain the original status along with the new status.
     * It will also contain the whole order details.
     *
     * @return array The payload sent by Snipcart
     * @throws WireException
     */
    public function ___handleOrderPaymentStatusChanged()
    {
        if ($this->debug) $this->wire('log')->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request: handleOrderPaymentStatusChanged'
        );
        $this->responseStatus = 202; // Accepted
        return $this->payload;
    }

    /**
     * Webhook handler for tracking number changed.
     * This event is triggered when the tracking number of an order is changed from the dashboard or the API.
     * The event will contain the new tracking number and will also contain the order details.
     *
     * @return array The payload sent by Snipcart
     * @throws WireException
     */
    public function ___handleOrderTrackingNumberChanged()
    {
        if ($this->debug) $this->wire('log')->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request: handleOrderTrackingNumberChanged'
        );
        $this->responseStatus = 202; // Accepted
        return $this->payload;
    }

    /**
     * Webhook handler for refund created.
     * This event is triggered when a refund for an order is created from the dashboard or the API. * 
     * The event will contain the order token, amount and currency
     *
     * @return array The payload sent by Snipcart
     * @throws WireException
     */
    public function ___handleOrderRefundCreated()
    {
        if ($this->debug) $this->wire('log')->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request: handleOrderRefundCreated'
        );
        $this->responseStatus = 202; // Accepted
        return $this->payload;
    }

    /**
     * Webhook handler for subscription created.
     * This event is triggered whenever a new subscription is created.
     *
     * @return array The payload sent by Snipcart
     * @throws WireException
     */
    public function ___handleSubscriptionCreated()
    {
        if ($this->debug) $this->wire('log')->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request: handleSubscriptionCreated'
        );
        $this->responseStatus = 202; // Accepted
        return $this->payload;
    }

    /**
     * Webhook handler for subscription cancelled.
     * This event is triggered when a subscription is cancelled, either by an admin or by the customer.
     *
     * @return array The payload sent by Snipcart
     * @throws WireException
     */
    public function ___handleSubscriptionCancelled()
    {
        if ($this->debug) $this->wire('log')->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request: handleSubscriptionCancelled'
        );
        $this->responseStatus = 202; // Accepted
        return $this->payload;
    }

    /**
     * Webhook handler for subscription paused.
     * This event is triggered when a subscription is paused by the customer.
     *
     * @return array The payload sent by Snipcart
     * @throws WireException
     */
    public function ___handleSubscriptionPaused()
    {
        if ($this->debug) $this->wire('log')->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request: handleSubscriptionPaused'
        );
        $this->responseStatus = 202; // Accepted
        return $this->payload;
    }

    /**
     * Webhook handler for subscription resumed.
     * This event is triggered when a subscription is resumed by the customer.
     *
     * @return array The payload sent by Snipcart
     * @throws WireException
     */
    public function ___handleSubscriptionResumed()
    {
        if ($this->debug) $this->wire('log')->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request: handleSubscriptionResumed'
        );
        $this->responseStatus = 202; // Accepted
        return $this->payload;
    }

    /**
     * Webhook handler for subscription invoice created.
     * This event is triggered whenever a new invoice is added to an existing subscription.
     * This event will not trigger when a subscription is created, it will only trigger for upcoming invoices.
     *
     * @return array The payload sent by Snipcart
     * @throws WireException
     */
    public function ___handleSubscriptionInvoiceCreated()
    {
        if ($this->debug) $this->wire('log')->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request: handleSubscriptionInvoiceCreated'
        );
        $this->responseStatus = 202; // Accepted
        return $this->payload;
    }

    /**
     * Webhook handler for custom shipping rates fetching.
     * Snipcart expects to receive a JSON object containing an array of shipping rates.
     *
     * @return array The payload sent by Snipcart
     * @throws WireException
     */
    public function ___handleShippingratesFetch()
    {
        if ($this->debug) $this->wire('log')->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request: handleShippingratesFetch'
        );


        // @todo: implement custom shipping rates


        $this->responseStatus = 202; // Accepted
        return $this->payload;
    }

    /**
     * Webhook handler for custom taxes calculation.
     * Snipcart expects to receive a JSON object containing an array of tax rates.
     *
     * @return array The payload sent by Snipcart
     * @throws WireException
     */
    public function ___handleTaxesCalculate()
    {
        $log = $this->wire('log');

        if ($this->debug) $log->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request: handleTaxesCalculate'
        );

        // No taxes handling if taxes provider is other than "integrated"
        if ($this->snipwireConfig->taxes_provider != 'integrated') {
            $log->save(
                self::snipWireWebhooksLogName,
                $this->_('Webhooks request: handleTaxesCalculate - the integrated taxes provider is disabled in module settings')
            );
            $this->responseStatus = 204; // No Content
            return;
        }

        // Sample payload array: https://docs.snipcart.com/webhooks/taxes

        $payload = $this->payload;
        $content = isset($payload['content']) ? $payload['content'] : null;
        if ($content) {
            $items = isset($content['items']) ? $content['items'] : null; // array
            $shippingInformation = isset($content['shippingInformation']) ? $content['shippingInformation'] : null; // array
            $itemsTotal = isset($content['itemsTotal']) ? $content['itemsTotal'] : null; // decimal
            $currency = isset($content['currency']) ? $content['currency'] : null; // string
        }
        if ($itemsTotal === 0) {
            // handle case when all items are removed from cart: https://support.snipcart.com/t/solved-webhook-taxes-response-when-no-items/1764
            $taxesResponse = array();
        } elseif (!is_array($items) || !$shippingInformation || !$currency) {
            $log->save(
                self::snipWireWebhooksLogName,
                $this->_('Webhooks request: handleTaxesCalculate - invalid request data for taxes calculation')
            );
            $this->responseStatus = 400; // Bad Request
            return;
        } else {
            $hasTaxesIncluded = Taxes::getTaxesIncludedConfig();
            $shippingTaxesType = Taxes::getShippingTaxesTypeConfig();
            $currencyPrecision = CurrencyFormat::getCurrencyDefinition($currency, 'precision');
            if (!$currencyPrecision) $currencyPrecision = 2;

            $taxNamePrefix = $hasTaxesIncluded
                ? $this->_('incl.') // Tax name prefix if taxes included in price (keep it short)
                : '+';
            $taxNamePrefix .= ' ';

            // Collect and group all tax names and total prices (before taxes) from items in payload
            $itemTaxes = [];
            foreach ($items as $item) {
                if (!$item['taxable']) continue;
                $taxName = $item['taxes'][0]; // we currently only support a single tax per product!
                if (!isset($itemTaxes[$taxName])) {
                    // add new array entry
                    $itemTaxes[$taxName] = [
                        'sumPrices' => $item['totalPriceWithoutTaxes'],
                        'splitRatio' => 0, // is calculated later
                    ];
                } else {
                    // add price to existing sumPrices
                    $itemTaxes[$taxName]['sumPrices'] += $item['totalPriceWithoutTaxes'];
                }
            }

            // Calculate and add proportional ratio (for splittet shipping tax calculation)
            foreach ($itemTaxes as $name => $values) {
                $itemTaxes[$name]['splitRatio'] = round(($values['sumPrices'] / $itemsTotal), 2); // e.g. 0.35 = 35%
                // @todo: what if $itemsTotal = 0? (division by 0 error!)
            }
            unset($name, $values);

            /*
            Results in $itemTaxes (sample) array:
            
        [
            '20% VAT' => [
                    "sumPrices' => 300
                    'splitRatio' => 0.67
            ]
            '10% VAT' => [
                    'sumPrices' => 150
                    'splitRatio' => 0.33
            ]
        ]
            
            Sample splitRatio calculation: 300 / (300 + 150) = 0.67 = 67%
            */

            //
            // Prepare item & shipping taxes response
            //

            $taxesResponse = [];
            $taxConfigMax = [];
            $maxRate = 0;

            foreach ($itemTaxes as $name => $values) {
                $taxConfig = Taxes::getTaxesConfig(false, Taxes::taxesTypeProducts, $name);
                if (!empty($taxConfig)) {
                    $taxesResponse[] = [
                        'name' => $taxNamePrefix . $name,
                        'amount' => Taxes::calculateTax($values['sumPrices'], $taxConfig['rate'], $hasTaxesIncluded, $currencyPrecision),
                        'rate' => $taxConfig['rate'],
                        'numberForInvoice' => $taxConfig['numberForInvoice'],
                        'includedInPrice' => $hasTaxesIncluded,
                        //'appliesOnShipping' // not needed,
                    ];

                    // Get tax config with the highest rate (for shipping tax calculation)
                    if ($shippingTaxesType == Taxes::shippingTaxesHighestRate) {
                        if ($taxConfig['rate'] > $maxRate) {
                            $maxRate = $taxConfig['rate'];
                            $taxConfigMax = $taxConfig;
                        }
                    }
                }
            }
            unset($name, $values);

            if ($shippingTaxesType != Taxes::shippingTaxesNone) {
                $shippingFees = isset($shippingInformation['fees'])
                    ? $shippingInformation['fees']
                    : 0;
                $shippingMethod = isset($shippingInformation['method'])
                    ? ' (' . $shippingInformation['method'] . ')'
                    : '';

                if ($shippingFees > 0) {
                    switch ($shippingTaxesType) {
                        case Taxes::shippingTaxesFixedRate:
                            $taxConfig = Taxes::getFirstTax(false, Taxes::taxesTypeShipping);
                            if (!empty($taxConfig)) {
                                $taxesResponse[] = [
                                    'name' => $taxNamePrefix . $taxConfig['name'] . $shippingMethod,
                                    'amount' => Taxes::calculateTax($shippingFees, $taxConfig['rate'], $hasTaxesIncluded, $currencyPrecision),
                                    'rate' => $taxConfig['rate'],
                                    'numberForInvoice' => $taxConfig['numberForInvoice'],
                                    'includedInPrice' => $hasTaxesIncluded,
                                    //'appliesOnShipping' // not needed,
                                ];
                            }
                            break;

                        case Taxes::shippingTaxesHighestRate:
                            if (!empty($taxConfigMax)) {
                                $taxesResponse[] = [
                                    'name' => $taxNamePrefix . $taxConfigMax['name'] . $shippingMethod,
                                    'amount' => Taxes::calculateTax($shippingFees, $taxConfigMax['rate'], $hasTaxesIncluded, $currencyPrecision),
                                    'rate' => $taxConfigMax['rate'],
                                    'numberForInvoice' => $taxConfigMax['numberForInvoice'],
                                    'includedInPrice' => $hasTaxesIncluded,
                                    //'appliesOnShipping' // not needed,
                                ];
                            }
                            break;

                        case Taxes::shippingTaxesSplittedRate:
                            foreach ($itemTaxes as $name => $values) {
                                $shippingFeesSplit = round(($shippingFees * $values['splitRatio']), 2);
                                $taxConfig = Taxes::getTaxesConfig(false, Taxes::taxesTypeProducts, $name);
                                if (!empty($taxConfig)) {
                                    $taxesResponse[] = [
                                        'name' => $taxNamePrefix . $taxConfig['name'] . $shippingMethod,
                                        'amount' => Taxes::calculateTax($shippingFeesSplit, $taxConfig['rate'], $hasTaxesIncluded, $currencyPrecision),
                                        'rate' => $taxConfig['rate'],
                                        'numberForInvoice' => $taxConfig['numberForInvoice'],
                                        'includedInPrice' => $hasTaxesIncluded,
                                        //'appliesOnShipping' // not needed,
                                    ];
                                }
                            }
                            break;
                    }
                }
            }
        }

        $taxes = ['taxes' => $taxesResponse];

        $this->responseStatus = 202; // Accepted
        $this->responseBody = \ProcessWire\wireEncodeJSON($taxes, true);
        return $this->payload;
    }

    /**
     * Webhook handler for customer updated.
     * This event is triggered whenever a customer object is updated from the dashboard or the API.
     *
     * (This is an undocumented event!)
     *
     * @return array The payload sent by Snipcart
     * @throws WireException
     */
    public function ___handleCustomerUpdated()
    {
        if ($this->debug) $this->wire('log')->save(
            self::snipWireWebhooksLogName,
            '[DEBUG] Webhooks request: handleCustomerUpdated'
        );
        $this->responseStatus = 202; // Accepted
        return $this->payload;
    }

    /**
     * Get PHP server and execution environment information from superglobal $_SERVER
     *
     * @param string $var The required key
     * @return string|boolean Returns value of $_SEREVER key or false if not exists
     *
     * (This could return an empty string so needs to checked with === false)
     *
     */
    public function getServerVar($var)
    {
        return isset($_SERVER[$var]) ? $_SERVER[$var] : false;
    }
}
