<?php
namespace giusepperoccazzella\exceptions;

class CheckoutException extends \Exception
{
    public function __construct($exmsg, $val = 0, Exception $old = null)
    {
        parent::__construct($exmsg, $val, $old);
    }
    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
