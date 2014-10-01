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
        
        // Unregister First
        if ( isset( $this->iface_info[$name][$mjr] ) )
        {
            $as->error( \FutoIn\Error::InternalError, "Already registered" );
        }
        
        if ( !( $impl instanceof InterfaceImplementation ) &&
             !is_string( $impl ) &&
             !is_callable( $impl ) )
        {
            $as->error( \FutoIn\Error::InternalError, "Impl is not Object/String/Callable" );
        }
        
        if ( !isset( $this->iface_info[$name] ) )
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
            $supinfo->iface = $info->iface; // NOTE: this one is critical for implementation resolution
            $supinfo->version = $sup[1];
            $supinfo->mjrver = $supmjr;
            $supinfo->mnrver = $supmjrmnr[1]; // NOTE: super minor version may no match subclass

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
                    $as->checkConstraints( $as, $reqinfo );
                    $as->checkParams( $as, $reqinfo );
                    $as->successStep();
                });
                
                // Step 4. Invoke implementation
                //---
                $as->add(function($as) use ($reqinfo) {
                    $func = $as->_futoin_func;
                    $impl = $this->getImpl( $as, $reqinfo );
                    
                    if ( $impl instanceof \FutoIn\Executor\AsyncImplementation )
                    {
                        $impl->$func( $as, $reqinfo );
                        $as->successStep();
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
                $as->successStep();
            }
        );
    }
    
    protected function getInfo( \FutoIn\AsyncSteps $as, RequestInfo $reqinfo )
    {
        if ( !isset( $reqinfo->f ) )
        {
            $as->error( \FutoIn\Error::InvalidRequest, "Missing req->f" );
        }
        
        //
        $f = explode(':', (string)$reqinfo->f);
        
        if ( count( $f ) !== 3 )
        {
            $as->error( \FutoIn\Error::InvalidRequest, "Invalid req->f" );
        }
        
        $iface = $f[0];
        $func = $f[2];
        
        //
        $v = explode('.', $f[1]);
        
        if ( ( count( $f ) !== 2 ) ||
                !is_numeric( $v[0] ) ||
                !is_numeric( $v[1] ) )
        {
            $as->error( \FutoIn\Error::InvalidRequest, "Invalid req->f (version)" );
        }
        
        //
        if ( !isset( $this->ifaces[$iface] ) )
        {
            $as->error( \FutoIn\Error::UnknownInterface );
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
             ( !isset( $reqinfo->{RequestInfo::INFO_SECURE_CHANNEL} ) ||
               !$reqinfo->{RequestInfo::INFO_SECURE_CHANNEL} ) )
        {
            $as->error( \FutoIn\Error::SecurityError, "Insecure channel" );
        }
        
        if ( isset( $constraints['AllowAnonymous'] ) &&
             ( !isset( $reqinfo->{RequestInfo::INFO_USER_INFO} ) ||
               !$reqinfo->{RequestInfo::INFO_USER_INFO} ) )
        {
            $as->error( \FutoIn\Error::SecurityError, "Insecure channel" );
        }

    }
    
    protected function checkParams( \FutoIn\AsyncSteps $as, RequestInfo $reqinfo )
    {
        $rawreq = $reqinfo->{RequestInfo::INFO_RAW_REQUEST};
        $finfo = $as->_futoin_func_info;
    
        if ( $ctx->upload_data &&
             !$finfo->rawupload )
        {
            $as->error( \FutoIn\Error::InvalidRequest, "Raw upload is not allowed" );
        }
        
        if ( empty( $finfo->params ) && count( get_object_vars( $rawreq->p ) ) )
        {
            $as->error( \FutoIn\Error::InvalidRequest, "No params are defined" );
        }
        
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
                if ( isset( $v->{"default"} ) )
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
    
    protected function getImpl( \FutoIn\AsyncSteps $as, RequestInfo $reqinfo )
    {
        // NOTE: this one is critical, if called by inheritted interface, see register()
        $iname = $as->_futoin_iface_info->iface;
        $impl = $this->impls[$iname];
        
        if ( !is_object( $impl ) )
        {
            if ( is_string( $impl ))
            {
                $impl = new $impl( $this );
            }
            elseif( is_callable( $impl ) )
            {
                $impl = $impl( $this );
            }
            else
            {
                $as->error( \FutoIn\Error::InternalError, "Invalid implementation type" );
            }
            
            $this->impls[$iname] = $impl;
        }
        
        return $impl;
    }

    protected function checkResult( \FutoIn\AsyncSteps $as, RequestInfo $reqinfo )
    {
    }

    
    protected function signResponse( \FutoIn\AsyncSteps $as, RequestInfo $reqinfo )
    {
        if ( !isset( $reqinfo->{RequestInfo::INFO_DERIVED_KEY} ) )
        {
            return;
        }

        // TODO :
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
}