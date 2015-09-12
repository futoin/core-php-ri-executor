<?php

/**
 * Executor Reference Implementation
 *
 * @package FutoIn\Core\PHP\RI\Executor
 * @copyright 2014 FutoIn Project (http://futoin.org)
 * @author Andrey Galkin
 */

namespace FutoIn\RI\Executor;

class RequestInfo
    implements \FutoIn\Executor\RequestInfo
{
    protected $ctx;
    protected $rawreq;
    protected $rawrsp;
    protected $info;
    
    protected $f_out = null;
    protected $f_in = null;
    
    public function __construct( \FutoIn\RI\Executor\Executor $ctx, $rawreq )
    {
        $this->ctx = $ctx;
        
        if ( is_string( $rawreq ) )
        {
            $rawreq = json_decode( $rawreq );
        }
            
        $this->rawreq = $rawreq;
        
        $rawrsp = new \StdClass;
        $rawrsp->r = new \StdClass;
        $this->rawrsp = $rawrsp;
        
        $this->info = (object)[
            self::INFO_X509_CN => null,
            self::INFO_PUBKEY => null,
            self::INFO_CLIENT_ADDR => null,
            self::INFO_SECURE_CHANNEL => false,
            self::INFO_REQUEST_TIME_FLOAT => microtime(true),
            self::INFO_SECURITY_LEVEL => self::SL_ANONYMOUS,
            self::INFO_USER_INFO => null,
            self::INFO_RAW_REQUEST => &$this->rawreq,
            self::INFO_RAW_RESPONSE => &$this->rawrsp,
            self::INFO_DERIVED_KEY => null,
            self::INFO_HAVE_RAW_UPLOAD => false,
            self::INFO_HAVE_RAW_RESULT => false,
            self::INFO_CHANNEL_CONTEXT => null,
        ];
        
        if ( isset( $rawreq->rid ) )
        {
            $rawrsp->rid = $rawreq->rid;
        }
    }
    
    public function __destruct()
    {
        if ( !is_null( $this->f_out ) ) fclose( $this->f_out );
        if ( !is_null( $this->f_in ) ) fclose( $this->f_in );
    }

    /**
     * Get request object
     * @return arguments (object)
     */
    public function params()
    {
        return $this->rawreq->p;
    }
    
    /**
     * Get response object
     * @return result data (object)
     */
    public function result()
    {
        return $this->rawrsp->r;
    }
    
    /**
     * Get info object
     * @return info data (object)
     */
    public function info()
    {
        return $this->info;
    }
    
    /**
     * @return return raw input stream or null, if FutoIn request comes in that stream
     */
    public function rawInput()
    {
        if ( is_null( $this->f_in ) &&
             !is_null( $this->{self::INFO_CHANNEL_CONTEXT} ) )
        {
            $this->f_in = $this->{self::INFO_CHANNEL_CONTEXT}->_openRawInput();
        }
        
        return $this->f_in;
    }
    
    /**
     * @return return raw output stream (no result variables are expected)
     */
    public function rawOutput()
    {
        if ( is_null( $this->f_out ) &&
             !is_null( $this->{self::INFO_CHANNEL_CONTEXT} ) )
        {
            $this->f_out = $this->{self::INFO_CHANNEL_CONTEXT}->_openRawOutput();
        }
        
        return $this->f_out;
    }
    
    /**
     * Get reference to Executor
     * @return \FutoIn\Executor
     */
    public function executor()
    {
        return $this->ctx;
    }
    
    /**
     * Get reference to Executor
     * @return \FutoIn\Executor\ChannelContext
     */
    public function channel()
    {
        return $this->{self::INFO_CHANNEL_CONTEXT};
    }
    
    /**
     * Set to abort request after specified timeout_ms from the moment of call.
     * It must override any previous cancelAfter() call.
     *
     * @note it is different from as.setTimeout() as inner step timeout does 
     * not override outer step timeout.
     *
     * @param integer timeout_ms - timeout in miliseconds to cancel after. 0 - disable timeout
     */
    public function cancelAfter( $timeout_ms )
    {
        new \FutoIn\Error( \FutoIn\Error::NotImplemented );
    }

    
    /**
     * info() access through RequestInfo interface / get value
     * @param $name State variable name
     */
    public function &__get( $name )
    {
        return $this->info->$name;
    }
    
    /**
     * info() access through RequestInfo interface / check value
     * @param $name State variable name
     */
    public function __isset( $name )
    {
        return isset( $this->info->$name );
    }
    
    
    /** @internal */
    public function __clone()
    {
        throw new \FutoIn\Error( \FutoIn\Error::InternalError );
    }
}
