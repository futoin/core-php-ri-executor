<?php
/**
 * FutoIn Communication Channel Context - Reference Implementation
 *
 * @package FutoIn\Core\PHP\RI\Executor
 * @copyright 2014 FutoIn Project (http://futoin.org)
 * @author Andrey Galkin
 */

namespace FutoIn\RI\Executor;

/**
 * FutoIn Communication Channel Context
 *
 * @see http://specs.futoin.org/final/preview/ftn6_iface_executor_concept-1.html
 * @api
 */
abstract class ChannelContext implements \FutoIn\Executor\ChannelContext
{
    use \FutoIn\RI\Details\AsyncStepsStateAccessorTrait;

    private $state;
    
    public function __construct()
    {
        $this->state = new \StdClass;
    }

    /**
     * Get channel state variables
     * @note state is persistent only for stateful protocols
     * @return array of key-value pairs
     */
    public function state()
    {
        return $this->state;
    }
    
    /** @internal */
    public function __clone()
    {
        throw new \FutoIn\Error( \FutoIn\Error::InternalError );
    }
    
    /**
     * @ignore
     * @internal
     */
    abstract public function _openRawInput();
    
    /**
     * @ignore
     * @internal
     */
    abstract public function _openRawOutput();
}
