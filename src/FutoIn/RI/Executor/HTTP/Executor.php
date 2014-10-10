<?php
/**
 * FutoIn HTTP Executor - Reference Implementation
 *
 * @package FutoIn\Core\PHP\RI\Executor
 * @copyright 2014 FutoIn Project (http://futoin.org)
 * @author Andrey Galkin
 */

namespace FutoIn\RI\Executor\HTTP;

use \FutoIn\RI\Executor\RequestInfo;

/**
 * FutoIn HTTP Communication Channel Context - Reference Implementation 
 * for standard PHP process
 *
 * NOTE: this implementation must be used only in standard
 *  one-request=one-process/thread PHP mode, utlizing standard input parameter
 *  parsing features of PHP.
 *
 * @see http://specs.futoin.org/final/preview/ftn5_iface_http_integration-1.html
 * @api
 */
class Executor
    extends \FutoIn\RI\Executor\Executor
{
    /** URL sub-path */
    const OPT_SUBPATH = 'HTTP:SUBPATH';
    /** Force Secure Channel, avoiding auto-detection*/
    const OPT_FORCE_SECURE = "HTTP:FORCE_SECURE";
    
    private $subpath = '/';
    private $force_secure = false;

    public function __construct( \FutoIn\RI\Invoker\AdvancedCCM $ccm, array $options )
    {
        parent::__construct( $ccm, $options );
        
        if ( isset( $options[self::OPT_SUBPATH] ) )
        {
            $subpath = $options[self::OPT_SUBPATH];
            if ( substr( $subpath, -1 ) !== '/' )
            {
                $subpath .= '/';
            }
            
            $this->subpath = $subpath;
        }
    }
    
    public function handleRequest()
    {
        ini_set( 'display_errors', '0' );
        
        $as = new \FutoIn\RI\ScopedSteps;
        $as->add(
            function($as){
                $reqinfo = $this->getBaseRequestInfo( $as );
                $this->configureRequestInfo( $as, $reqinfo );
                
                $as->reqinfo = $reqinfo;
                
                $this->process( $as );
                
                $as->add(function($as){
                    $reqinfo = $as->reqinfo;
                    $reqinfo_info = $reqinfo->info();
                    
                    if ( !is_null( $reqinfo_info->{RequestInfo::INFO_RAW_RESPONSE} ) )
                    {
                        header('Content-Type: application/futoin+json');
                        echo $reqinfo_info->{RequestInfo::INFO_RAW_RESPONSE};
                    }
                    
                    if ( function_exists('fastcgi_finish_request') )
                    {
                        fastcgi_finish_request();
                    }
                    
                    $as->success();
                });
            },
            function($as,$err){
                error_log( "$err: ".$as->error_info );
                http_response_code( 500 );
                header( 'Content-Type: application/futoin+json' );
                echo '{"e":"InvalidRequest"}';
            }
        );
        $as->run();
    }
    
    private function getBaseRequestInfo( $as )
    {
        $have_upload = false;
        
        $uri = parse_url( $_SERVER['REQUEST_URI'] );
        $path_info = $uri['path'];
        
        if ( substr( $path_info, -1 ) !== '/' )
        {
            $path_info .= '/';
        }
        
        $p_subpath = preg_quote( $this->subpath, '#' );
        
        if ( preg_match( '#^'.$p_subpath.'$#', $path_info ) )
        {
            if ( $_SERVER['REQUEST_METHOD'] === 'POST' )
            {
                $jsonreq = file_get_contents(
                    'php://input',
                    false,
                    null,
                    0,
                    self::SAFE_JSON_MESSAGE_LIMIT
                );
            }
            else
            {
                $as->error( \FutoIn\Error::InvalidRequest, "Invalid request method" );
            }
        }
        elseif ( preg_match( '#^'.$p_subpath.'([^/]+)/([^/]+)/([^/]+)(/([^/]+))?/?$#', $path_info, $m ) )
        {
            $iface = $m[1];
            $ver = $m[2];
            $func = $m[3];
            $sec = isset( $m[5] ) ? $m[5] : null;
            
            $jsonreq = new \StdClass;
            $jsonreq->f = "$iface:$ver:$func";
            
            if ( isset( $uri['query'] ) )
            {
                parse_str( $uri['query'], $get_params );
                $jsonreq->p = json_decode(json_encode($get_params));
            }
            
            if ( isset( $m[4] ) )
            {
                $jsonreq->sec = $m[4];
            }
            
            if ( $_SERVER['REQUEST_METHOD'] === 'POST' )
            {
                $have_upload = true;
            }
        }
        else
        {
            $as->error( \FutoIn\Error::InvalidRequest, "Invalid request" );
        }
        
        $reqinfo = new RequestInfo( $this, $jsonreq );
        
        $reqinfo->{RequestInfo::INFO_HAVE_RAW_UPLOAD} = $have_upload;
        $reqinfo->{RequestInfo::INFO_REQUEST_TIME_FLOAT} = $_SERVER['REQUEST_TIME_FLOAT'];
        
        return $reqinfo;
    }
    
    private function configureRequestInfo( $as, $reqinfo )
    {
        if ( $this->force_secure )
        {
            $is_secure = true;
        }
        elseif ( isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] )
        {
            $is_secure = true;
        }
        else
        {
            $is_secure = false;
        }
        
        $channel_ctx = new \FutoIn\RI\Executor\HTTP\ChannelContext( $is_secure );
        
        $saddr = new \FutoIn\RI\Executor\SourceAddress(
            null,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['REMOTE_PORT']
        );
        
        $innerinfo = $reqinfo->info();
        // TODO:
        //$innerinfo->{self::INFO_X509_CN} 
        $innerinfo->{RequestInfo::INFO_CLIENT_ADDR} = $saddr;
        $innerinfo->{RequestInfo::INFO_SECURE_CHANNEL} = $is_secure;
        $innerinfo->{RequestInfo::INFO_CHANNEL_CONTEXT} = $channel_ctx;
    }
}
