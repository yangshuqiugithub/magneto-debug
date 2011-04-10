<?php
class Magneto_Debug_IndexController extends Mage_Core_Controller_Front_Action
{
	private function _debugPanel($title, $content) {
         $block = new Mage_Core_Block_Template(); //Is this the correct way?
        $block->setTemplate('debug/simplepanel.phtml');
        $block->assign('title', $title);
        $block->assign('content', $content);
        return $block->toHtml();
    }

	public function viewTemplateAction()
	{
		$fileName = $this->getRequest()->get('template');
		$absoluteFilepath = realpath(Mage::getBaseDir('design') . DS . $fileName);
		$source =  highlight_string(file_get_contents($absoluteFilepath), true) ;
		
		echo $this->_debugPanel("Template Source: <code>$fileName</code>", ''.$source.'');
	}

    public function viewSqlSelectAction()
    {
        $con = Mage::getSingleton('core/resource')->getConnection('core_write');
        $query = $this->getRequest()->getParam('sql');
        $queryParams = $this->getRequest()->getParam('params');

        $result = $con->query($query, $queryParams);

        $items = array();
        $headers = array();
        while( $row=$result->fetch(PDO::FETCH_ASSOC) ){
            $items[]=$row;
            
            if( empty($headers) ){
                $headers = array_keys($row);
            }
        }

        $block = new Mage_Core_Block_Template(); //Is this the correct way?
        $block->setTemplate('debug/arrayformat.phtml');
        $block->assign('title', 'SQL Select');
        $block->assign('headers', $headers);
        $block->assign('items', $items);
        $block->assign('query', $query);
        echo $block->toHtml();
    }


    public function viewSqlExplainAction()
    {
        $con = Mage::getSingleton('core/resource')->getConnection('core_write');
        $query = $this->getRequest()->getParam('sql');
        $queryParams = $this->getRequest()->getParam('params');

        $result = $con->query("EXPLAIN {$query}", $queryParams);

        $items = array();
        $headers = array();
        while( $row=$result->fetch(PDO::FETCH_ASSOC) ){
            $items[]=$row;
            
            if( empty($headers) ){
                $headers = array_keys($row);
            }
        }

        $block = new Mage_Core_Block_Template(); //Is this the correct way?
        $block->setTemplate('debug/arrayformat.phtml');
        $block->assign('title', 'SQL Explain');
        $block->assign('headers', $headers);
        $block->assign('items', $items);
        $block->assign('query', $query);
        echo $block->toHtml();
    }
	
	
	public function clearCacheAction() {
        $content = Mage::helper('debug')->cleanCache();
        Mage::getSingleton('core/session')->addSuccess("Magento's caches were cleared.");
        $this->_redirectReferer();
	}

    public function toggleTemplateHintsAction() {
        $currentStatus = Mage::getStoreConfig('dev/debug/template_hints');
        $newStatus = !$currentStatus;

        $config = Mage::getModel('core/config');
        $config->saveConfig('dev/debug/template_hints', $newStatus, 'websites', Mage::app()->getStore()->getWebsiteId());
        $config->saveConfig('dev/debug/template_hints_blocks', $newStatus, 'websites', Mage::app()->getStore()->getWebsiteId());

        Mage::app()->cleanCache();

        Mage::getSingleton('core/session')->addSuccess('Template hints set to ' . var_export($newStatus, true));
        $this->_redirectReferer(); 
    }

    public function toggleModuleStatusAction()
    {
        $title = "Toggle Module Status";
        $moduleName = $this->getRequest()->getParam('module');
        if( !$moduleName ){
            echo $this->_debugPanel($title, "Invalid module name supplied. ");
            return;
        }
        $config = Mage::getConfig();

        $moduleConfig = Mage::getConfig()->getModuleConfig($moduleName);
        if( !$moduleConfig  ) {
            echo $this->_debugPanel($title, "Unable to load supplied module. ");
            return;
        }

    
        $moduleCurrentStatus = $moduleConfig->is('active');
        $moduleNewStatus = !$moduleCurrentStatus;
        $moduleConfigFile = $config->getOptions()->getEtcDir() . DS . 'modules' . DS . $moduleName . '.xml';
        $configContent = file_get_contents($moduleConfigFile);

        function strbool($value)
        {
            return $value ? 'true' : 'false';
        }

        $contents = "<br/>Active status switched to " . strbool($moduleNewStatus) . " for module {$moduleName} in file {$moduleConfigFile}:";
        $contents .= "<br/><code>" . htmlspecialchars($configContent) . "</code>";
        $configContent = str_replace("<active>" . strbool($moduleCurrentStatus) ."</active>", "<active>" . strbool($moduleNewStatus) . "</active>", $configContent);

        if( file_put_contents($moduleConfigFile, $configContent) === FALSE ) {
            echo $this->_debugPanel($title, "Failed to write configuration. (Web Service permissions for {$moduleConfigFile}?!)");
            return $this;
        }

        Mage::helper('debug')->cleanCache();

        $contents .= "<br/><code>" . htmlspecialchars($configContent) . "</code>";
        $contents .= "<br/><br/><i>WARNING: This feature doesn't support usage of multiple frontends.</i>";

        echo $this->_debugPanel($title, $contents);
    }


    public function downloadConfigAction()
    {
        header("Content-type: text/xml");
        echo Mage::app()->getConfig()->getNode()->asXML();
    }


    public function showSqlProfilerAction()
    {
        $config = Mage::getConfig()->getNode('global/resources/default_setup/connection/profiler');
        Mage::getSingleton('core/resource')->getConnection('core_write')->getProfiler()->setEnabled(false);
        var_dump($config);
    }


    // FIXME: This needs to be corrected
    public function toggleSqlProfilerAction()
    {
        $localConfigFile = Mage::getBaseDir('etc').DS.'local.xml';
        $localConfigBackupFile = Mage::getBaseDir('etc').DS.'local-magneto.xml';

        $configContent = file_get_contents($localConfigFile);
        $xml = new SimpleXMLElement($configContent);
        $profiler = $xml->global->resources->default_setup->connection->profiler;
        if( (int)$xml->global->resources->default_setup->connection->profiler !=1 ){
            $xml->global->resources->default_setup->connection->addChild('profiler', 1);
        } else {
            unset($xml->global->resources->default_setup->connection->profiler);
        }
        
        // backup config file
        if( file_put_contents($localConfigBackupFile, $configContent)===FALSE ){
            Mage::getSingleton('core/session')->addError("Operation aborted: couldn't create backup for config file");
            $this->_redirectReferer();
        }

        if( $xml->saveXML($localConfigFile) === FALSE ) {
            Mage::getSingleton('core/session')->addError("Couldn't save {$localConfigFile}: check write permissions.");
            $this->_redirectReferer();
        }
        Mage::getSingleton('core/session')->addSuccess("SQL profiler status changed in local.xml");

        Mage::helper('debug')->cleanCache();
        $this->_redirectReferer();
    }
	
    public function indexAction()
    {	
		$this->loadLayout();     
		$this->renderLayout();
    }

    public function findURIAction()
    {
        $uri = $this->getRequest()->getParam('uri');
        $groupType = 'model'; //model, block, helper
        if( $uri) {
            $class = Mage::getConfig()->getGroupedClassName($groupType, $uri);
        }
        var_dump($class);
    }
}
