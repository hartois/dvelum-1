<?php

class Ext_Object_Instance extends Ext_Object
{
    /**
     * Real object
     * @var Ext_Object
     */
    protected $_object;
    protected $related = '';
    protected $relatedNs = '';

    /**
     * Always not extended
     * @var bool _isExtended
     */
    protected $_isExtended = false;

    protected function _loadConfig()
    {
        $this->_config = new Ext_Config(Ext::getPropertyClass('Instance'));
    }

    public function setRelated($related){
        $this->related = $related;
    }

    public function getRelated(){
        return $this->related;
    }

    public function setRelatedNs($namespace){
        $this->relatedNs = $namespace;
    }

    public function getRelatedNs(){
        return $this->relatedNs;
    }

    /**
     * (non-PHPdoc)
     * @see Ext_Object::getClass()
     */
    public function getClass()
    {
        return 'Object_Instance';
    }

    /**
     * Set object to instantiate
     * @param Ext_Object $object
     */
    public function setObject(Ext_Object $object)
    {
        $this->_object = $object;
    }

    /**
     * Gen defined object
     * @return Ext_Object
     */
    public function getObject()
    {
        return $this->_object;
    }

    /**
     * (non-PHPdoc)
     * @see Ext_Object::isExtendedComponent()
     */
    public function isExtendedComponent()
    {
        return false;
    }

    /**
     * (non-PHPdoc)
     * @see Ext_Object::extendedComponent()
     */
    public function extendedComponent($flag)
    {
        // always not extended
    }

    /**
     * (non-PHPdoc)
     * @see Ext_Object::isInstance()
     */
    public function isInstance()
    {
        return true;
    }

    public function getDefineJs($namespace = false){
        if(empty($namespace) && !empty($this->getRelatedNs()))
            $namespace = $this->getRelatedNs();

        parent::getDefineJs($namespace);
    }

    /**
     * (non-PHPdoc)
     * @see Ext_Object::__toString()
     */
    public function __toString()
    {
        $this->_convertListeners();
        $listeners = $this->_config->listeners;
        $config = $this->_config->config;

        $delim = ',';

        if (empty($config) || $config == '{}') {
            $config = '{}';
            $delim = '';
        }

        if (!empty($listeners)) {
            $pos = strrpos($config, '}');
            $config = substr($config, 0, $pos) . $delim . ' listeners:' . $listeners . '}';
        }
        return $config;
    }
}