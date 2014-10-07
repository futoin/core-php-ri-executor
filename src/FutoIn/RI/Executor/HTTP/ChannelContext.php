<?php
/**
 * FutoIn HTTP Communication Channel Context - Reference Implementation
 *
 * @package FutoIn\Core\PHP\RI\Executor
 * @copyright 2014 FutoIn Project (http://futoin.org)
 * @author Andrey Galkin
 */

namespace FutoIn\RI\Executor\HTTP;

/**
 * FutoIn HTTP Communication Channel Context - Reference Implementation 
 * for standard PHP process
 *
 * @see http://specs.futoin.org/final/preview/ftn5_iface_http_integration-1.html
 * @api
 */
class ChannelContext
    extends \FutoIn\RI\Executor\ChannelContext
{
    protected $is_secure = false;
    private $tmp_options = null;

    public function __construct( $is_secure )
    {
        $this->is_secure = $is_secure;
    }

    /**
     * Get type of channel
     * HTTP
     * LOCAL
     * TCP
     * UDP
     * any other - as non-standard extension
     * @return string type identifier
     */
    public function type()
    {
        return self::TYPE_HTTP;
    }
    
    /**
     * Check if current communication channel between Invoker and Executor is stateful
     * @return boolean
     */
    public function isStateful()
    {
        return false;
    }
    
    /**
     * Get all request headers as map
     * @return array of name->value pairs
     */
    public function getRequestHeaders()
    {
        return getallheaders();
    }
    
    /**
     * @param $name Header name
     * @param $value Header value
     * @param $override Override previous headers with the same name
     * @return void
     */
    public function setResponseHeader( $name, $value, $override=true )
    {
        header( "$name: $value", $override );
    }
    
    /**
     * Set HTTP status code
     * @param $http_code HTTP status code
     * @return void
     */
    public function setStatusCode( $http_code )
    {
        http_response_code( $http_code );
    }
    
    /**
     * Set cookie value
     * @param $name Cookie name
     * @return cookie value or null
     */
    public function getCookie( $name )
    {
        if ( isset( $_COOKIE[$name] ) )
        {
            return $_COOKIE[$name];
        }
        
        return null;
    }
    
    /**
     * Set cookie
     * @param $name Cookie name
     * @param $value Cookie value
     * @param $options array|object with the following fields:
     *      options.http_only = true 
     *      options.secure = INFO_SECURE_CHANNEL
     *      options.domain = null
     *      options.path = null
     *      options.expires = null (date object or string)
     *      options.max_age = null (interval object or string)
     * @return void
     */
    public function setCookie( $name, $value, $options )
    {
        $http_only = true;
        $secure = $this->is_secure;
        $domain = null;
        $path = null;
        $expires = null;
        $max_age = null;
        
        $this->tmp_options = (array)$options;
        $extract_count = extract( $this->tmp_options, EXTR_IF_EXISTS|EXTR_REFS );
        $total_count = count( $this->tmp_options );
        unset( $this->tmp_options );

        if ( ( $extract_count !== $total_count ) ||
             ( !is_null( $max_age ) && !is_null( $expires ) ) ||
             ( !is_null( $max_age ) && !( $max_age instanceof \DateTimeInterface ) ) ||
             ( !is_null( $expires ) && !( $expires instanceof \DateInterval ) ) )
        {
            throw new \FutoIn\Error( \FutoIn\Error::InternalError, "Invalid options for setCookie" );
        }
        
        if( !is_null( $max_age ) )
        {
            $expires = new \DateTime;
            $expires->add( $max_age );
        }
        
        if ( !is_null( $expires ) )
        {
            $expires = $expires->getTimestamp();
        }
        else
        {
             $expires = 0;
        }
        
        setcookie( $name, $value, $expires, $path, $domain, $secure, $http_only );
    }
    
    /**
     * @ignore
     * @internal
     */
    public function _openRawInput()
    {
        return fopen( 'php://input', 'r' );
    }
    
    /**
     * @ignore
     * @internal
     */
    public function _openRawOutput()
    {
        return fopen( 'php://output', 'w' );;
    }

}