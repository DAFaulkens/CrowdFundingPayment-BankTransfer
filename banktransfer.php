<?php
/**
 * @package      CrowdFunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2013 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

jimport('joomla.plugin.plugin');

/**
 * CrowdFunding Bank Transfer Payment Plugin
 *
 * @package      CrowdFunding
 * @subpackage   Plugins
 * 
 * @todo Use $this->app and $autoloadLanguage to true, when Joomla! 2.5 is not actual anymore.
 */
class plgCrowdFundingPaymentBankTransfer extends JPlugin {
    
    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string 	$context	This string gives information about that where it has been executed the trigger.
     * @param object 	$item	    A project data.
     * @param JRegistry $params	    The parameters of the component
     */
    public function onProjectPayment($context, $item, $params) {
        
        $app = JFactory::getApplication();
        /** @var $app JSite **/

        if($app->isAdmin()) {
            return;
        }

        $doc     = JFactory::getDocument();
        /**  @var $doc JDocumentHtml **/
        
        // Check document type
        $docType = $doc->getType();
        if(strcmp("html", $docType) != 0){
            return;
        }
       
        if(strcmp("com_crowdfunding.payment", $context) != 0){
            return;
        }
        
        // Load language
        $this->loadLanguage();
        
        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/banktransfer";
        
        // Load Twitter Bootstrap and its styles, 
        // because I am going to use them for a modal window.
        if(version_compare(JVERSION, "3", ">=")) {
            JHtml::_("bootstrap.framework");
            JHtml::_("bootstrap.loadCss");
        } else {
            
            JHtml::addIncludePath(JPATH_COMPONENT.'/helpers/html');
            JHtml::_("crowdfunding.bootstrap");
            
            $doc->addStyleSheet($pluginURI."/css/bootstrap-modal.min.css");
            $doc->addScript($pluginURI."/js/bootstrap-modal.min.js");
            
        }
        
        // Load the script that initializes the select element with banks.
        $doc->addScript($pluginURI."/js/plg_crowdfundingpayment_banktransfer.js");
        
        $html   =  array();
        $html[] = '<h4><img src="'.$pluginURI.'/images/bank_icon.png" width="30" height="26" />'.JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_TITLE").'</h4>';
        $html[] = '<div>'.nl2br($this->params->get("beneficiary")).'</div>';
        
        // Check for valid beneficiary information. If missing information, display error message.
        $beneficiaryInfo = JString::trim( strip_tags($this->params->get("beneficiary")) );
        if(!$beneficiaryInfo) {
            $html[] = '<div class="alert">'.JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_ERROR_PLUGIN_NOT_CONFIGURED").'</div>';
            return implode("\n", $html);
        }
        
        if($this->params->get("display_additional_info", 1)) {
            $additionalInfo = JString::trim($this->params->get("additional_info"));
            if(!empty($additionalInfo)) {
                $html[] = '<p class="sticky">'.htmlspecialchars($additionalInfo, ENT_QUOTES, "UTF-8").'</p>';
            } else {
                $html[] = '<p class="sticky">'.JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_INFO").'</p>';
            }
        }
        
        $html[] = '<div class="alert hide" id="js-bt-alert"></div>';
            
        $html[] = '<div class="clearfix"></div>';
        $html[] = '<a href="#" class="btn btn-primary" id="js-register-bt">'.JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_MAKE_BANK_TRANSFER").'</a>';
        $html[] = '<a href="#" class="btn btn-success hide" id="js-continue-bt">'.JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_CONTINUE_NEXT_STEP").'</a>';
        
        $html[] = '    
    <div class="modal hide fade" id="js-banktransfer-modal">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
            <h3>'.JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_TITLE").'</h3>
        </div>
        <div class="modal-body">
            <p>'.JText::_("PLG_CROWDFUNDINGPAYMENT_REGISTER_TRANSACTION_QUESTION").'</p>
        </div>
        <div class="modal-footer">
            <img src="media/com_crowdfunding/images/ajax-loader.gif" width="16" height="16" id="js-banktransfer-ajax-loading" style="display: none;" />
            <a href="javascript: void(0);" class="btn btn-primary" id="js-btbtn-yes" data-project-id="'.$item->id.'" data-amount="'.$item->amount.'">'.JText::_("JYES").'</a>
            <a href="javascript: void(0);" class="btn" id="js-btbtn-no">'.JText::_("JNO").'</a>
        </div>
    </div>';
        
        return implode("\n", $html);
        
    }
    
    
    /**
     * This method is invoked when the administrator changes transaction status from the backend.
     *
     * @param string 	This string gives information about that where it has been executed the trigger.
     * @param object 	A transaction data.
     * @param string    Old staus
     * @param string    New staus
     * 
     * @return void
     */
    public function onTransactionChangeStatus($context, $item, $oldStatus, $newStatus) {
    
        $app = JFactory::getApplication();
        /** @var $app JSite **/
    
        if($app->isSite()) {
            return;
        }
    
        $doc     = JFactory::getDocument();
        /**  @var $doc JDocumentHtml **/
    
        // Check document type
        $docType = $doc->getType();
        if(strcmp("html", $docType) != 0){
            return;
        }
         
        if(strcmp("com_crowdfunding.transaction", $context) != 0){
            return;
        }
    
        // Load language
        $this->loadLanguage();
        
        if(strcmp($oldStatus, "completed") == 0) { // Remove funds, if someone change the status from completed to other one.
            
            jimport("crowdfunding.project");
            $project = new CrowdFundingProject($item->project_id);
            
            // DEBUG DATA
            JDEBUG ? CrowdFundingLog::add(JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_DEBUG_BCSNC"), "BANKTRANSFER_PAYMENT_PLUGIN_DEBUG", $project->getProperties()) : null;
            
            $project->removeFunds($item->txn_amount);
            $project->store();
            
            // DEBUG DATA
            JDEBUG ? CrowdFundingLog::add(JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_DEBUG_ACSNC"), "BANKTRANSFER_PAYMENT_PLUGIN_DEBUG", $project->getProperties()) : null;
            
        } else if(strcmp($newStatus, "completed") == 0) { // Add funds, if someone change the status to completed
            
            jimport("crowdfunding.project");
            $project = new CrowdFundingProject($item->project_id);
            
            // DEBUG DATA
            JDEBUG ? CrowdFundingLog::add(JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_DEBUG_BCSTC"), "BANKTRANSFER_PAYMENT_PLUGIN_DEBUG", $project->getProperties()) : null;
            
            $project->addFunds($item->txn_amount);
            $project->store();
            
            // DEBUG DATA
            JDEBUG ? CrowdFundingLog::add(JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_DEBUG_ACSTC"), "BANKTRANSFER_PAYMENT_PLUGIN_DEBUG", $project->getProperties()) : null;
        }
        
    }
    
}