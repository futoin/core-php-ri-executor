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
    
    public function __construct( \FutoIn\RI\Executor\Executor $ctx, $reqjson )
    {
        $this->ctx = $ctx;
        $rawreq = json_decode( $reqjson );
        $this->rawreq = $rawreq;
        
        $info = new \StdClass;
        $info->{self::INFO_RAW_REQUEST} = &$this->rawreq;
        $this->info = $info;
        
        $rawrsp = new \StdClass;
        $rawrsp->r = new \StdClass;
        $this->rawrsp = $rawrsp;
        $info->{self::INFO_RAW_RESPONSE} = &$this->rawrsp;
        
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
        if ( is_null( $this->f_in ) )
        {
            $this->f_in = fopen( 'php://input', 'r' );
        }
        
        return $this->f_in;
    }
    
    /**
     * @return return raw output stream (no result variables are expected)
     */
    public function rawOutput()
    {
        if ( is_null( $this->f_out ) )
        {
            $this->f_out = fopen( 'php://output', 'w' );
        }
        
        return $this->f_out;
    }
    
    /**
     * Get reference to Executor
     * @return \FutoIn\Executor
     */
    public function context()
    {
        return $this->ctx;
    }
    
    /**
     * [un]mark request as ready to be canceled on Invoker abort (disconnect)
     * @param boolean $ignore Ignore user abort (yes/no)
     */
    public function ignoreInvokerAbort( $ignore = true )
    {
        ignore_user_abort( $ignore );
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
}
