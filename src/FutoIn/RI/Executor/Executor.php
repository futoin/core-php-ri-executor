<?php

/**
 * Executor Reference Implementation
 *
 * @package FutoIn\Core\PHP\RI\Executor
 * @copyright 2014 FutoIn Project (http://futoin.org)
 * @author Andrey Galkin
 */

namespace FutoIn\RI\Executor;

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
        
        $info = new \FutoIn\RI\Invoker\Details\RegistrationInfo;
        $info->iface = $name;
        $info->version = $ifacever[1];
        $info->mjrver = $mjrmnr[0];
        $info->mnrver = $mjrmnr[1];

        \FutoIn\RI\Invoker\Details\SpecTools::loadSpec( $as, $info, $this->specdirs );

        $this->ifaces[$name][$mjr] = $info;
        $this->impls[$name][$mjr] = $impl;
    }
    
    /**
     * Process request, received for arbitrary channel, including unit-test generated
     * @param $async_completion - asynchronous completion interface
     * @return void
     */
    public function process( \FutoIn\Executor\AsyncCompletion $asc )
    {
    }
    
    /**
     * A shortcut to check access through #acl interface
     * @param $async_completion - asynchronous completion interface
     * @param $acd - Access Control Descriptor
     * @return void
     */
    public function checkAccess( \FutoIn\Executor\AsyncCompletion $asc, array $acd )
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