<?php
/**
 * Vonage Client Library for PHP
 *
 * @copyright Copyright (c) 2016 Vonage, Inc. (http://vonage.com)
 * @license   https://github.com/vonage/vonage-php/blob/master/LICENSE MIT License
 */

namespace Vonage\Message;

use Vonage\Entity\Hydrator\ArrayHydrateInterface;
use Vonage\Message\EncodingDetector;
use Vonage\Entity\JsonResponseTrait;
use Vonage\Entity\Psr7Trait;
use Vonage\Entity\RequestArrayTrait;

/**
 * Abstract Message
 *
 * Extended by concrete message types (text, binary, etc).
 */
class Message implements MessageInterface, \Countable, \ArrayAccess, \Iterator, ArrayHydrateInterface
{
    use Psr7Trait;
    use JsonResponseTrait;
    use RequestArrayTrait;
    use CollectionTrait;

    const TYPE = null;

    const CLASS_FLASH = 0;

    protected $responseParams = [
        'status',
        'message-id',
        'to',
        'remaining-balance',
        'message-price',
        'network'
    ];

    protected $current = 0;

    protected $id;

    protected $autodetectEncoding = false;

    /**
     * @param string $idOrTo Message ID or E.164 (international) formatted number to send the message
     * @param null|string $from Number or name the message is from
     * @param array  $additional Additional API Params
     */
    public function __construct($idOrTo, $from = null, $additional = [])
    {
        if (is_null($from)) {
            $this->id = $idOrTo;
            return;
        }

        $this->requestData['to'] = (string) $idOrTo;
        $this->requestData['from'] = (string) $from;
        if (static::TYPE) {
            $this->requestData['type'] = static::TYPE;
        }
        
        $this->requestData = array_merge($this->requestData, $additional);
    }

    /**
     * Boolean indicating if you would like to receive a Delivery Receipt
     *
     * @param bool $dlr
     */
    public function requestDLR($dlr = true)
    {
        return $this->setRequestData('status-report-req', $dlr ? 1 : 0);
    }

    /**
     * Webhook endpoint the delivery receipt is sent to for this message
     * This overrides the setting in the Dashboard, and should be a full URL
     *
     * @param str $callback
     */
    public function setCallback($callback)
    {
        return $this->setRequestData('callback', (string) $callback);
    }

    /**
     * Optional reference of up to 40 characters
     *
     * @param str $ref
     */
    public function setClientRef($ref)
    {
        return $this->setRequestData('client-ref', (string) $ref);
    }

    /**
     * The Mobile Country Code Mobile Network Code (MCCMNC) this number is registered with
     *
     * @param str $network
     */
    public function setNetwork($network)
    {
        return $this->setRequestData('network-code', (string) $network);
    }

    /**
     * The duration in milliseconds the delivery of an SMS will be attempted
     * By default this is set to 72 hours, but can be overridden if needed.
     * Vonage recommends no shorter than 30 minutes, and to keep at default
     * when possible.
     *
     * @param int $ttl
     */
    public function setTTL($ttl)
    {
        return $this->setRequestData('ttl', (int) $ttl);
    }

    /**
     * The Data Coding Sceheme value of this message
     * Should be 0, 1, 2, or 3
     *
     * @param int $class
     */
    public function setClass($class)
    {
        return $this->setRequestData('message-class', $class);
    }

    public function enableEncodingDetection()
    {
        $this->autodetectEncoding = true;
    }

    public function disableEncodingDetection()
    {
        $this->autodetectEncoding = false;
    }

    public function count()
    {
        $data = $this->getResponseData();
        if (!isset($data['messages'])) {
            return 0;
        }

        return count($data['messages']);
    }

    public function getMessageId($index = null)
    {
        if (isset($this->id)) {
            return $this->id;
        }

        return $this->getMessageData('message-id', $index);
    }

    public function getStatus($index = null)
    {
        return $this->getMessageData('status', $index);
    }
    
    public function getFinalStatus($index = null)
    {
        return $this->getMessageData('final-status', $index);
    }
    
    public function getTo($index = null)
    {
        $data = @$this->getResponseData();

        //check if this is data from a send request
        //(which also has a status, but it's not the same)
        if (isset($data['messages'])) {
            return $this->getMessageData('to', $index);
        }

        return $this->data['to'];
    }

    public function getRemainingBalance($index = null)
    {
        return $this->getMessageData('remaining-balance', $index);
    }

    public function getPrice($index = null)
    {
        $data = $this->getResponseData();

        //check if this is data from a send request
        //(which also has a status, but it's not the same)
        if (isset($data['messages'])) {
            return $this->getMessageData('message-price', $index);
        }

        return $this->data['price'];
    }

    public function getNetwork($index = null)
    {
        return $this->getMessageData('network', $index);
    }

    public function getDeliveryStatus()
    {
        @$data = $this->getResponseData();

        //check if this is data from a send request
        //(which also has a status, but it's not the same)
        if (isset($data['messages'])) {
            return;
        }

        return $this->data['status'];
    }

    public function getFrom()
    {
        return $this->data['from'];
    }

    public function getBody()
    {
        return $this->data['body'];
    }

    public function getDateReceived()
    {
        return new \DateTime($this->data['date-received']);
    }

    public function getDeliveryError()
    {
        return $this->data['error-code'];
    }

    public function getDeliveryLabel()
    {
        return $this->data['error-code-label'];
    }

    public function isEncodingDetectionEnabled()
    {
        return $this->autodetectEncoding;
    }

    protected function getMessageData($name, $index = null)
    {
        if (!isset($this->response)) {
            return null;
        }

        $data = $this->getResponseData();
        if (is_null($index)) {
            $index = $this->count() -1;
        }

        if (isset($data['messages'])) {
            return $data['messages'][$index][$name];
        }

        return isset($data[$name]) ? $data[$name] : null;
    }

    protected function preGetRequestDataHook()
    {
        // If $autodetectEncoding is true, we want to set the `type`
        // field in our payload
        if ($this->isEncodingDetectionEnabled()) {
            $this->requestData['type'] = $this->detectEncoding();
        }
    }

    protected function detectEncoding()
    {
        if (!isset($this->requestData['text'])) {
            return static::TYPE;
        }

        // Auto detect unicode messages
        $detector = new EncodingDetector;
        if ($detector->requiresUnicodeEncoding($this->requestData['text'])) {
            return Unicode::TYPE;
        }

        return static::TYPE;
    }

    public function offsetExists($offset)
    {
        trigger_error(
            "Array access for " . get_class($this) . " is deprecated, please use getter methods",
            E_USER_DEPRECATED
        );
        $response = @$this->getResponseData();

        if (isset($this->index)) {
            $response = $response['items'][$this->index];
        }

        $request  = @$this->getRequestData();
        $dirty    = @$this->getRequestData(false);
        if (isset($response[$offset]) || isset($request[$offset]) || isset($dirty[$offset])) {
            return true;
        }

        //provide access to split messages by virtual index
        if (is_int($offset) && $offset < $this->count()) {
            return true;
        }

        return false;
    }

    public function offsetGet($offset)
    {
        trigger_error(
            "Array access for " . get_class($this) . " is deprecated, please use getter methods",
            E_USER_DEPRECATED
        );
        $response = @$this->getResponseData();

        if (isset($this->index)) {
            $response = $response['items'][$this->index];
        }

        $request  = @$this->getRequestData();
        $dirty    = @$this->getRequestData(false);

        if (isset($response[$offset])) {
            return $response[$offset];
        }

        //provide access to split messages by virtual index, if there is data
        if (isset($response['messages'])) {
            if (is_int($offset) && isset($response['messages'][$offset])) {
                return $response['messages'][$offset];
            }

            $index = $this->count() -1;

            if (isset($response['messages'][$index]) && isset($response['messages'][$index][$offset])) {
                return $response['messages'][$index][$offset];
            }
        }

        if (isset($request[$offset])) {
            return $request[$offset];
        }

        if (isset($dirty[$offset])) {
            return $dirty[$offset];
        }
    }

    public function offsetSet($offset, $value)
    {
        throw $this->getReadOnlyException($offset);
    }

    public function offsetUnset($offset)
    {
        throw $this->getReadOnlyException($offset);
    }

    protected function getReadOnlyException($offset)
    {
        return new \RuntimeException(sprintf(
            'can not modify `%s` using array access',
            $offset
        ));
    }

    public function current()
    {
        if (!isset($this->response)) {
            return null;
        }

        $data = $this->getResponseData();
        return $data['messages'][$this->current];
    }

    public function next()
    {
        $this->current++;
    }

    public function key()
    {
        if (!isset($this->response)) {
            return null;
        }

        return $this->current;
    }

    public function valid()
    {
        if (!isset($this->response)) {
            return null;
        }

        $data = $this->getResponseData();
        return isset($data['messages'][$this->current]);
    }

    public function rewind()
    {
        $this->current = 0;
    }

    public function fromArray(array $data)
    {
        $this->data = $data;
    }

    public function toArray() : array
    {
        return $this->data;
    }
}
