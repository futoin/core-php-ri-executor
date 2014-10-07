<?php
/**
 * FutoIn HTTP Executor - Reference Implementation
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
        $as = new \FutoIn\RI\ScopedSteps;
        $as->add(
            function($as){
                $reqinfo = $this->getBaseRequestInfo( $as );
                $this->configureRequestInfo( $as, $reqinfo );
                
                $as->reqinfo = $reqinfo;
                
                $this->process( $as );
                
                $as->add(function($as){
                    $reqinfo = $as->reqinfo;
                    
                    if ( !is_null( $reqinfo_info->{RequestInfo::INFO_RAW_RESPONSE} ) )
                    {
                        header('Content-Type: application/futoin+json');
                        echo $reqinfo_info->{RequestInfo::INFO_RAW_RESPONSE};
                    }
                    
                    $as->success();
                });
            },
            function($as,$err){
                error_log( "$err: ".$as->error_info );
                http_response_code( 500 );
                echo '{"e":"InvalidRequest"}';
            }
        );
        $as->run();
    }
    
    private function getBaseRequestInfo( $as )
    {
        $have_upload = false;
        $path_info = $_SERVER['PATH_INFO'];
        
        if ( substr( $path_info, -1 ) !== '/' )
        {
            $path_info .= '/';
        }
        
        if ( preg_match( "/^{$this->subpath}\$/", $path_info ) )
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
        elseif ( preg_match( "/^{$this->subpath}(\\/[^\\/]+){3,4}\\/?\$/", $path_info, $m ) )
        {
            $iface = $m[1];
            $ver = $m[2];
            $func = $m[3];
            $sec = isset( $m[4] ) ? $m[3] : null;
            
            $jsonreq = new \StdClass;
            $jsonreq->f = "$iface:$ver:$func";
            $jsonreq->p = json_decode(json_encode($_GET));
            
            if ( isset( $m[4] ) )
            {
                $jsonreq->sec = $m[4];
            }
            
            if ( $_SERVER['REQUEST_METHOD'] === 'POST' )
            {
                $have_upload = true;
            }
        }
        
        $reqinfo = new \FutoIn\RI\Executor\RequestInfo( $this, $jsonreq );
        
        $reqinfo->{INFO_HAVE_RAW_UPLOAD} = $have_upload;
        $reqinfo->{INFO_REQUEST_TIME_FLOAT} = $_SERVER['REQUEST_TIME_FLOAT'];
        
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
        
        $saddr = new \FutoIn\RI\SourceAddress(
            null,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['REMOTE_PORT']
        );
        
        $innerinfo = $reqinfo->info();
        // TODO:
        //$innerinfo->{self::INFO_X509_CN} 
        $innerinfo->{self::INFO_CLIENT_ADDR} = $saddr;
        $innerinfo->{self::INFO_SECURE_CHANNEL} = $is_secure;
        $innerinfo->{self::INFO_CHANNEL_CONTEXT} = $channel_ctx;
    }
}
