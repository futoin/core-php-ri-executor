<?php

/**
 * Executor Reference Implementation
 *
 * @package FutoIn\Core\PHP\RI\Executor
 * @copyright 2014 FutoIn Project (http://futoin.org)
 * @author Andrey Galkin
 */

namespace FutoIn\RI\Executor;

use \FutoIn\RI\Executor\RequestInfo;
use \FutoIn\RI\Invoker\Details\SpecTools;
use \FutoIn\RI\Invoker\Details\RegistrationInfo;

class Executor
    implements \FutoIn\Executor\Executor
{
    const SAFE_JSON_MESSAGE_LIMIT = 65536;

    /** Secure Vault instance, if Master Service/Client model is used */
    const OPT_VAULT = 'vault';
    
    /** array of directories where to search for iface specs named by FTN3 standard
        
        Note: It can be URL, if supported by file_get_contents(), but discouraged
    */
    const OPT_SPEC_DIRS = 'specdirs';
    
    /** Disable extra sanity checks for production mode performance */
    const OPT_PROD_MODE = 'prodmode';

    private $ccm;
    private $ifaces = [];
    private $impls;

    private $vault = null;
    private $development_checks = true;
    private $specdirs = array();

    public function __construct( \FutoIn\RI\Invoker\AdvancedCCM $ccm, array $options )
    {
        $this->ccm = $ccm;
        
        if ( isset( $options[self::OPT_VAULT] ) )
        {
            $this->vault = $options[self::OPT_VAULT];
        }
        
        if ( isset( $options[self::OPT_SPEC_DIRS] ) )
        {
            $this->specdirs = (array)$options[self::OPT_SPEC_DIRS];
        }
        
        if ( isset( $options[self::OPT_PROD_MODE] ) )
        {
            $this->development_checks = !$options[self::OPT_PROD_MODE];
        }
    }
    
    /**
     * Get associated Connection and Credential manager
     * @return \FutoIn\Invoker\AdvancedCCM
     */
    public function ccm()
    {
        return $this->ccm;
    }
    
    /**
     * Register implementation of specific FutoIn interface with specific version
     * @param string $ifacever "iface:version" pair as per the spec
     * @param string|InterfaceImplementation $impl Either class name for lazy loading or already instantiated object
     * @return void
     */
    public function register( \FutoIn\AsyncSteps $as, $ifacever, $impl )
    {
        if ( ! preg_match('/^([a-z][a-z0-9]*)(\\.[a-z][a-z0-9]*)+:[0-9]+\\.[0-9]+$/i', $ifacever ) )
        {
            $as->error( \FutoIn\Error::InternalError, "Invalid ifacever" );
        }
    
        $ifacever = explode( ':', $ifacever );
        $mjrmnr = explode( '.', $ifacever[1] );
        $name = $ifacever[0];
        $mjr = $mjrmnr[0];
        
        // Double registration is not allowed
        if ( isset( $this->ifaces[$name][$mjr] ) )
        {
            $as->error( \FutoIn\Error::InternalError, "Already registered" );
        }
        
        if ( !is_string( $impl ) &&
             !( $impl instanceof \FutoIn\Executor\InterfaceImplementation ) &&
             !( $impl instanceof Closure ) &&
             !is_callable( $impl ) )
        {
            $as->error( \FutoIn\Error::InternalError, "Impl is not Object/String/Callable" );
        }
        
        if ( !isset( $this->ifaces[$name] ) )
        {
            $this->ifaces[$name] = [];
            $this->impls[$name] = [];
        }
        
        $info = new RegistrationInfo;
        $info->iface = $name;
        $info->version = $ifacever[1];
        $info->mjrver = $mjr;
        $info->mnrver = $mjrmnr[1];

        SpecTools::loadSpec( $as, $info, $this->specdirs );

        $this->ifaces[$name][$mjr] = $info;
        $this->impls[$name][$mjr] = $impl;
        
        // Create aliases of super interfaces for runtime lookup
        foreach ( $info->inherits as $sup )
        {
            $sup = explode( ':', $sup );
            $supmjrmnr = explode( '.', $sup[1] );
            $supname = $sup[0];
            $supmjr = $supmjrmnr[0];
            
            $supinfo = new RegistrationInfo;
            $supinfo->iface = $supname;
            $supinfo->version = $sup[1];
            $supinfo->mjrver = $supmjr;
            $supinfo->mnrver = $supmjrmnr[1];
            $supinfo->derived = $info;
            
            if ( isset( $this->ifaces[$supname][$supmjr] ) )
            {
                unset( $this->ifaces[$name][$mjr] );
                $as->error( \FutoIn\Error::InternalError, "Conflict with inherited interfaces" );
            }

            $this->ifaces[$supname][$supmjr] = $supinfo;
        }
    }
    
    /**
     * Process request, received for arbitrary channel, including unit-test generated
     * @param $as - AsyncSteps interface
     * @return void
     */
    public function process( \FutoIn\AsyncSteps $as )
    {
        // Fundamental error
        if ( !isset( $as->reqinfo ) ||
             isset( $as->_futoin_func_info ) )
        {
            $as->error( \FutoIn\Error::InternalError, "Missing reqinfo" );
        }

        $as->add(
            // Standard processing
            //---
            function($as){
                $reqinfo = $as->reqinfo;

                // Step 1. Parsing interface and function info
                //---
                $this->getInfo( $as, $reqinfo );

                // Step 2. Security
                //---
                $as->add(function($as) use ($reqinfo) {
                    // TODO: check "sec" for HMAC -> MasterService
                    // TODO: check for credentials auth -> 
                    $as->successStep();
                });
                
                // Step 3. Check constraints and function parameters
                //---
                $as->add(function($as) use ($reqinfo) {
                    $this->checkConstraints( $as, $reqinfo );
                    $this->checkParams( $as, $reqinfo );
                    $as->successStep();
                });
                
                // Step 4. Invoke implementation
                //---
                $as->add(function($as) use ($reqinfo) {
                    $func = $as->_futoin_func;
                    $impl = $this->getImpl( $as, $reqinfo );
                    
                    if ( !method_exists( $impl, $func ) )
                    {
                        $as->error( \FutoIn\Error::InternalError, "Missing function implementation" );
                    }
                    
                    if ( $impl instanceof \FutoIn\Executor\AsyncImplementation )
                    {
                        $impl->$func( $as, $reqinfo );
                    }
                    else
                    {
                        // TODO: run in separate task
                        $result = $impl->$func( $reqinfo );
                        $as->success( $result );
                    }
                });
                
                // Step 5. Gather result and sign succeeded response
                //---
                $as->add(function($as,$result=null) use ($reqinfo) {

                    if ( $result !== null )
                    {
                        $r = $reqinfo->result();
                        
                        foreach( $result as $k => $v )
                        {
                            $r->$k = $v;
                        }
                    }
                    
                    $this->checkResult( $as, $reqinfo );

                    $this->signResponse( $as, $reqinfo );
                    $this->packResponse( $as, $reqinfo );
                    $as->successStep();
                });
                
            },
            // Overall error catcher
            //---
            function($as,$err){
                $reqinfo = $as->reqinfo;
                
                if ( !isset( SpecTools::$standard_errors[$err] ) &&
                     ( !isset( $as->_futoin_func_info ) ||
                       !isset( $as->_futoin_func_info->throws[$err] ) ) )
                {
                    $err = \FutoIn\Error::InternalError;
                }
                
                $rawrsp = $reqinfo->{RequestInfo::INFO_RAW_RESPONSE};
                $rawrsp->e = $err;
                unset( $rawrsp->r );
                
                // Even though request itself fails, send response
                $this->signResponse( $as, $reqinfo );
                $this->packError( $as, $reqinfo );
                $as->success();
            }
        );
    }
    
    protected function getInfo( \FutoIn\AsyncSteps $as, RequestInfo $reqinfo )
    {
        $reqinfo_info = $reqinfo->info();
        
        if ( !isset( $reqinfo_info->{RequestInfo::INFO_RAW_REQUEST}->f ) )
        {
            $as->error( \FutoIn\Error::InvalidRequest, "Missing req->f" );
        }
        
        //
        $f = explode(':', (string)$reqinfo_info->{RequestInfo::INFO_RAW_REQUEST}->f);
        
        if ( count( $f ) !== 3 )
        {
            $as->error( \FutoIn\Error::InvalidRequest, "Invalid req->f" );
        }
        
        $iface = $f[0];
        $func = $f[2];
        
        //
        $v = explode('.', $f[1]);
        
        if ( ( count( $v ) !== 2 ) ||
                !is_numeric( $v[0] ) ||
                !is_numeric( $v[1] ) )
        {
            $as->error( \FutoIn\Error::InvalidRequest, "Invalid req->f (version)" );
        }
        
        //
        if ( !isset( $this->ifaces[$iface] ) )
        {
            $as->error( \FutoIn\Error::UnknownInterface, "Unknown Interface" );
        }
        
        if ( !isset( $this->ifaces[$iface][$v[0]] ) )
        {
            $as->error( \FutoIn\Error::NotSupportedVersion, "Different major version" );
        }
        
        $iface_info = $this->ifaces[$iface][$v[0]];
        
        if ( (int)$iface_info->mnrver < (int)$v[1] )
        {
            $as->error( \FutoIn\Error::NotSupportedVersion, "Iface version is too old" );
        }
        
        // Jump to actual implementation
        if ( isset( $iface_info->derived ) )
        {
            $iface_info = $iface_info->derived;
        }
        
        if ( !isset( $iface_info->funcs[$func] ) )
        {
            $as->error( \FutoIn\Error::InvalidRequest, "Not defined interface function" );
        }
        
        $finfo = $iface_info->funcs[$func];
        
        $as->_futoin_iface_info = $iface_info;
        $as->_futoin_func = $func;
        $as->_futoin_func_info = $finfo;
    }

    protected function checkConstraints( \FutoIn\AsyncSteps $as, RequestInfo $reqinfo )
    {
        $constraints = $as->_futoin_iface_info->constraints;
    
        if ( isset( $constraints['SecureChannel'] ) &&
             !$reqinfo->{RequestInfo::INFO_SECURE_CHANNEL} )
        {
            $as->error( \FutoIn\Error::SecurityError, "Insecure channel" );
        }
        
        if ( !isset( $constraints['AllowAnonymous'] ) &&
             !$reqinfo->{RequestInfo::INFO_USER_INFO} )
        {
            $as->error( \FutoIn\Error::SecurityError, "Anonymous not allowed" );
        }

    }
    
    protected function checkParams( \FutoIn\AsyncSteps $as, RequestInfo $reqinfo )
    {
        $rawreq = $reqinfo->{RequestInfo::INFO_RAW_REQUEST};
        $finfo = $as->_futoin_func_info;
    
        if ( $reqinfo->{RequestInfo::INFO_HAVE_RAW_UPLOAD} &&
             !$finfo->rawupload )
        {
            $as->error( \FutoIn\Error::InvalidRequest, "Raw upload is not allowed" );
        }
        
        if ( isset( $rawreq->p ) )
        {
            $reqparams = $rawreq->p;
            
            // Check params
            foreach ( $reqparams as $k => $v )
            {
                if ( !isset( $finfo->params[$k] ) )
                {
                    $as->error( \FutoIn\Error::InvalidRequest, "Unknown parameter" );
                }
                
                SpecTools::checkFutoInType( $as, $finfo->params[$k]->type, $k, $v );
            }
            
            // Check missing params
            foreach ( $finfo->params as $k => $v )
            {
                if ( !isset( $reqparams->$k ) )
                {
                    if ( property_exists( $v, 'default' ) )
                    {
                        $reqparams->$k = $v->{"default"};
                    }
                    else
                    {
                        $as->error( \FutoIn\Error::InvalidRequest, "Missing parameter" );
                    }
                }
            }
        }
        elseif ( !empty( $finfo->params ) )
        {
            $as->error( \FutoIn\Error::InvalidRequest, "Missing parameter" );
        }
    }
    
    protected function getImpl( \FutoIn\AsyncSteps $as, RequestInfo $reqinfo )
    {
        $iface_info = $as->_futoin_iface_info;
        
        $iname = $iface_info->iface;
        $imjr = $iface_info->mjrver;
        $impl = $this->impls[$iname][$imjr];
        
        if ( ! ( $impl instanceof \FutoIn\Executor\InterfaceImplementation ) )
        {
            if ( is_string( $impl ) )
            {
                if ( !class_exists( $impl, true ) )
                {
                    $as->error( \FutoIn\Error::InternalError, "Implementation class not found" );
                }
                
                $impl = new $impl( $this );
            }
            elseif( $impl instanceof Closure )
            {
                $impl = $impl( $this );
            }
            elseif( is_callable( $impl ) )
            {
                $impl = call_user_func( $impl, $this );
            }
            else
            {
                $as->error( \FutoIn\Error::InternalError, "Invalid implementation type" );
            }
            
            if ( ! ( $impl instanceof \FutoIn\Executor\InterfaceImplementation ) )
            {
                $as->error( \FutoIn\Error::InternalError, "Implementation does not implement InterfaceImplementation" );
            }
            
            $this->impls[$iname][$imjr] = $impl;
        }

        return $impl;
    }

    protected function checkResult( \FutoIn\AsyncSteps $as, RequestInfo $reqinfo )
    {
        $rsp = $reqinfo->{RequestInfo::INFO_RAW_RESPONSE};
        $finfo = $as->_futoin_func_info;

        // Check raw result
        if ( $finfo->rawresult )
        {
            if ( count( get_object_vars( $rsp->r ) ) )
            {
                $as->error( \FutoIn\Error::InternalError, "Raw result is expected" );
            }
            
            return;
        }
        
        // check result variables
        if ( isset( $finfo->result ) )
        {
            $resvars = $finfo->result;
            
            // NOTE: the must be no unknown result variables on executor side as exactly the
            // specified interface version must be implemented
            foreach ( $rsp->r as $k => $v )
            {
                if ( !isset( $resvars[$k] ) )
                {
                    $as->error( \FutoIn\Error::InternalError, "Unknown result variable '$k'" );
                }
                
                SpecTools::checkFutoInType( $as, $resvars[$k]->type, $k, $v );
                unset( $resvars[$k] );
            }
            
            if ( count( $resvars ) )
            {
                $as->error( \FutoIn\Error::InternalError, "Missing result variables" );
            }
        }
        elseif ( count( get_object_vars( $rsp->r ) ) )
        {
            $as->error( \FutoIn\Error::InternalError, "No result variables are expected" );
        }
    }

    
    protected function signResponse( \FutoIn\AsyncSteps $as, RequestInfo $reqinfo )
    {
        if ( !$reqinfo->{RequestInfo::INFO_DERIVED_KEY} )
        {
            return;
        }

        // TODO :
    }
    
    protected function packResponse( \FutoIn\AsyncSteps $as, RequestInfo $reqinfo )
    {
        $reqinfo_info = $reqinfo->info();
        
        $finfo = $as->_futoin_func_info;
    
        if ( $finfo->rawresult )
        {
            $reqinfo_info->{RequestInfo::INFO_RAW_RESPONSE} = null;
            return;
        }
        
        if ( !isset( $finfo->result ) &&
            ( !isset( $reqinfo_info->{RequestInfo::INFO_RAW_REQUEST}->forcersp ) ||
            !$reqinfo_info->{RequestInfo::INFO_RAW_REQUEST}->forcersp )
        )
        {
            $reqinfo_info->{RequestInfo::INFO_RAW_RESPONSE} = null;
            return;
        }
        
        $reqinfo_info->{RequestInfo::INFO_RAW_RESPONSE} = json_encode(
            $reqinfo_info->{RequestInfo::INFO_RAW_RESPONSE},
            JSON_UNESCAPED_UNICODE
        );
    }
    
    protected function packError( \FutoIn\AsyncSteps $as, RequestInfo $reqinfo )
    {
        $reqinfo_info = $reqinfo->info();
        
        $reqinfo_info->{RequestInfo::INFO_RAW_RESPONSE} = json_encode(
            $reqinfo_info->{RequestInfo::INFO_RAW_RESPONSE},
            JSON_UNESCAPED_UNICODE
        );
    }
    
    /**
     * A shortcut to check access through #acl interface
     * @param $as - AsyncSteps interface
     * @param $acd - Access Control Descriptor
     * @return void
     */
    public function checkAccess( \FutoIn\AsyncSteps $as, array $acd )
    {
        $as->error( \FutoIn\Error::NotImplemented );
    }
    
    
    /**
     * initialized from cache (no need to register interfaces)
     * @param $as AsyncSteps instance
     */
    public function initFromCache( \FutoIn\AsyncSteps $as )
    {
        $as->error( \FutoIn\Error::NotImplemented );
    }
    
    /**
     * Call after all registrations are done to cache them
     * @param $as AsyncSteps instance
     */
    public function cacheInit( \FutoIn\AsyncSteps $as )
    {
        $as->error( \FutoIn\Error::NotImplemented );
    }
    
    
    /** @internal */
    public function __clone()
    {
        throw new \FutoIn\Error( \FutoIn\Error::InternalError );
    }
}