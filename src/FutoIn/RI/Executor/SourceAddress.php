<?php

/**
 * Source Address - Reference Implementation
 *
 * @package FutoIn\Core\PHP\RI\Executor
 * @copyright 2014 FutoIn Project (http://futoin.org)
 * @author Andrey Galkin
 */

namespace FutoIn\RI\Executor;

class SourceAddress
    implements \FutoIn\Executor\SourceAddress
{
    private $host;
    private $port;
    private $type;
    
    /**
     * Construct SourceAddress object
     * @param $type - any of TYPE_* constants or NULL for auto-detection
     * @param $host - 
     * @param $port
     */
    public function __construct( $type, $host, $port )
    {
        if ( is_null( $type ) )
        {
            if ( !is_numeric( $port ) )
            {
                $type = self::TYPE_LOCAL;
            }
            elseif ( strpos( $type, ':' ) !== false )
            {
                $type = self::TYPE_IPv6;
            }
            else
            {
                $type = self::TYPE_IPv4;
            }
        }
    
        $this->type = $type;
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * @return numeric address, no name lookup
     */
    public function host()
    {
        return $this->host;
    }
    
    /**
     * @return port or local path/identifier
     */
    public function port()
    {
        return $this->port;
    }
    
    /**
     * @return Type of address
     */
    public function type()
    {
        return $this->type;
    }
    
    /**
     * @return "Type:Host:Port"
     */
    public function asString()
    {
        $host = $this->host;
        
        if ( $this->type === self::TYPE_IPv6 )
        {
            $host = "[$host]";
        }
        
        return "{$this->type}:$host:{$this->port}";
    }
}
