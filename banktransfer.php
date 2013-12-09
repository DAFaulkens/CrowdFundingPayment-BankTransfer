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
    
    protected   $log;
    protected   $logFile = "plg_crowdfunding_banktransfer.php";
    protected   $version = "1.7";
    
    public function __construct(&$subject, $config = array()) {
        
        parent::__construct($subject, $config);
        
        // Create log object
        $file = JPath::clean(JFactory::getApplication()->getCfg("log_path") .DIRECTORY_SEPARATOR. $this->logFile);
        
        $this->log = new CrowdFundingLog();
        $this->log->addWriter(new CrowdFundingLogWriterDatabase(JFactory::getDbo()));
        $this->log->addWriter(new CrowdFundingLogWriterFile($file));
        
        // Load language
        $this->loadLanguage();
    }
    
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
        
        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/banktransfer";
        
        // Load Twitter Bootstrap and its styles, 
        // because I am going to use them for a modal window.
        if(version_compare(JVERSION, "3", ">=")) {
            
            JHtml::_("jquery.framework");
            if($params->get("bootstrap_modal", false)) {
                JHtml::addIncludePath(ITPRISM_PATH_LIBRARY.'/ui/helpers');
                JHtml::_("bootstrap.framework");
                JHtml::_("itprism.ui.bootstrap_modal");
            }
            
        } else {
            if($params->get("bootstrap_modal", false)) {
                JHtml::addIncludePath(ITPRISM_PATH_LIBRARY.'/ui/helpers');
                JHtml::addIncludePath(JPATH_COMPONENT.'/helpers/html');
                JHtml::_("crowdfunding.bootstrap");
                JHtml::_("itprism.ui.bootstrap_modal");
            }
        }
        
        // Load the script that initializes the select element with banks.
        $doc->addScript($pluginURI."/js/plg_crowdfundingpayment_banktransfer.js?v=".urlencode($this->version));
        
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
            <button class="btn btn-primary" id="js-btbtn-yes" data-project-id="'.$item->id.'" data-amount="'.$item->amount.'">'.JText::_("JYES").'</button>
            <button class="btn" id="js-btbtn-no">'.JText::_("JNO").'</button>
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
    
        // Verify the service provider.
        $paymentGateway = str_replace(" ", "", JString::strtolower(JString::trim($item->service_provider)));
        if(strcmp("banktransfer", $paymentGateway) != 0) {
            return;
        }
        
        if(strcmp($oldStatus, "completed") == 0) { // Remove funds, if someone change the status from completed to other one.
            
            jimport("crowdfunding.project");
            $project = new CrowdFundingProject($item->project_id);
            
            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_DEBUG_BCSNC"), "BANKTRANSFER_PAYMENT_PLUGIN_DEBUG", $project->getProperties()) : null;
            
            $project->removeFunds($item->txn_amount);
            $project->store();
            
            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_DEBUG_ACSNC"), "BANKTRANSFER_PAYMENT_PLUGIN_DEBUG", $project->getProperties()) : null;
            
        } else if(strcmp($newStatus, "completed") == 0) { // Add funds, if someone change the status to completed
            
            jimport("crowdfunding.project");
            $project = new CrowdFundingProject($item->project_id);
            
            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_DEBUG_BCSTC"), "BANKTRANSFER_PAYMENT_PLUGIN_DEBUG", $project->getProperties()) : null;
            
            $project->addFunds($item->txn_amount);
            $project->store();
            
            // DEBUG DATA
            JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_DEBUG_ACSTC"), "BANKTRANSFER_PAYMENT_PLUGIN_DEBUG", $project->getProperties()) : null;
        }
        
    }
    
    public function onContentPreparePayment($context, $params) {
        
        $app = JFactory::getApplication();
        /** @var $app JSite **/
        
        if($app->isAdmin()) {
            return;
        }
        
        $doc     = JFactory::getDocument();
        /**  @var $doc JDocumentHtml **/
        
        // Check document type
        $docType = $doc->getType();
        if(strcmp("raw", $docType) != 0){
            return;
        }
         
        if(strcmp("com_crowdfunding.preparepayment.banktransfer", $context) != 0){
            return;
        }
        
        $projectId    = $app->input->getInt("project_id");
        $amount       = $app->input->getFloat("amount");
        
        $uri          = JUri::getInstance();
        
        // Get project
        jimport("crowdfunding.project");
        $project    = new CrowdFundingProject($projectId);
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_DEBUG_PROJECT_OBJECT"), "BANKTRANSFER_PAYMENT_PLUGIN_DEBUG", $project->getProperties()) : null;
        
        // Check for valid project
        if(!$project->getId()) {
        
            // Log data in the database
            $this->log->add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_ERROR_INVALID_PROJECT"),
                "BANKTRANSFER_PAYMENT_PLUGIN_ERROR",
                array(
                    "PROJECT OBJECT" => $project->getProperties(),
                    "REQUEST METHOD" => $app->input->getMethod(),
                    "_REQUEST"       => $_REQUEST
                )
            );
        
            // Send response to the browser
            $response = array(
                "success" => false,
                "title"   => JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_FAIL"),
                "text"    => JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_ERROR_INVALID_PROJECT")
            );
        
            return $response;
        }
        
        jimport("crowdfunding.currency");
        $currencyId = $params->get("project_currency");
        $currency   = CrowdFundingCurrency::getInstance($currencyId);
        
        // Prepare return URL
        $returnUrl = JString::trim($this->params->get('return_url'));
        if(!$returnUrl) {
            $returnUrl = $uri->toString(array("scheme", "host")).JRoute::_(CrowdFundingHelperRoute::getBackingRoute($project->getSlug(), $project->getCatslug(), "share"), false);
        }
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_DEBUG_RETURN_URL"), "BANKTRANSFER_PAYMENT_PLUGIN_DEBUG", $returnUrl) : null;
        
        // Intentions
        
        $userId       = JFactory::getUser()->get("id");
        $aUserId      = $app->getUserState("auser_id");
        
        // Reset anonymous user hash ID,
        // because the intention based in it will be removed when transaction completes.
        if(!empty($aUserId)) {
            $app->setUserState("auser_id", "");
        }
        
        $intention    = CrowdFundingHelper::getIntention($userId, $aUserId, $projectId);
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_DEBUG_INTENTION_OBJECT"), "BANKTRANSFER_PAYMENT_PLUGIN_DEBUG", $intention->getProperties()) : null;
        
        // Validate intention record
        if(!$intention->getId()) {
        
            // Log data in the database
            $this->log->add(
                JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_ERROR_INVALID_INTENTION"),
                "BANKTRANSFER_PAYMENT_PLUGIN_ERROR",
                $intention->getProperties()
            );
        
            // Send response to the browser
            $response = array(
                "success" => false,
                "title"   => JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_FAIL"),
                "text"    => JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_ERROR_INVALID_PROJECT")
            );
            
            return $response;
        
        }
        
        // Validate Reward
        // If the user is anonymous, the system will store 0 for reward ID.
        // The anonymous users can't select rewards.
        $rewardId = ($intention->isAnonymous()) ? 0 : (int)$intention->getRewardId();
        if(!empty($rewardId)) {
            $rewardId= $this->updateRewards($rewardId, $projectId, $amount);
        }
        
        // Prepare transaction data
        jimport("itprism.string");
        $transactinoId   = JString::strtoupper(ITPrismString::generateRandomString(12, "BT"));
        $transactionData = array(
            "txn_amount"   => $amount,
            "txn_currency" => $currency->getAbbr(),
            "txn_status"   => "pending",
            "txn_id"       => $transactinoId,
            "project_id"   => $projectId,
            "reward_id"    => $rewardId,
            "investor_id"  => (int)$userId,
            "receiver_id"  => (int)$project->getUserId(),
            "service_provider"  => "Bank Transfer"
        );
        
        // Auto complete transaction
        if($this->params->get("auto_complete", 0)) {
            $transactionData["txn_status"] = "completed";
            $project->addFunds($amount);
            $project->store();
        }
        
        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_DEBUG_TRANSACTION_DATA"), "BANKTRANSFER_PAYMENT_PLUGIN_DEBUG", $transactionData) : null;
        
        // Store transaction data
        jimport("crowdfunding.transaction");
        $transaction = new CrowdFundingTransaction();
        $transaction->bind($transactionData);
        
        $transaction->store();
        
        // Get properties and prepare the object.
        $transaction = $transaction->getProperties();
        $transaction = JArrayHelper::toObject($transaction);
        
        // Remove intention
        $intention->delete();
        
        // Send mails
        $this->sendMails($project, $transaction);
        
        // Return response
        $response = array(
            "success" => true,
            "title"   => JText::_('PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_SUCCESS'),
            "text"    => JText::sprintf('PLG_CROWDFUNDINGPAYMENT_PAYMENT_BANKTRANSFER_TRANSACTION_REGISTERED', $transaction->txn_id, $transaction->txn_id),
            "data"    => array(
                "return_url" => $returnUrl
            )
        );
        
        return $response;
    }
    
    /**
     * This method validates reward and update the number
     * of distributed units, if it is limited.
     *
     * @param integer $rewardId
     * @param integer $projectId
     * @param integer $amount
     *
     * @return integer If there is something wrong, return reward ID 0.
     */
    protected function updateRewards($rewardId, $projectId, $amount) {
    
        jimport("crowdfunding.reward");
    
        $keys = array(
            "id"         => (int)$rewardId,
            "project_id" => (int)$projectId
        );
    
        $reward = new CrowdFundingReward($rewardId);
    
        // Check for valid reward
        if(!$reward->getId()) {
            $rewardId = 0;
            return $rewardId;
        }
    
        // Check for valid amount between reward value and payed by user
        if($amount < $reward->getAmount()) {
            $rewardId = 0;
            return $rewardId;
        }
    
        // Verify the availability of rewards
        if($reward->isLimited() AND !$reward->getAvailable()) {
            $rewardId = 0;
            return $rewardId;
        }
    
        return $rewardId;
    }
    
    protected function sendMails($project, $transaction) {
        
        $app = JFactory::getApplication();
        /** @var $app JSite **/
        
        // Get website
        $uri     = JUri::getInstance();
        $website = $uri->toString(array("scheme", "host"));
        
        jimport("crowdfunding.email");
        
        $emailMode  = $this->params->get("email_mode", "plain");
        
        // Prepare data for parsing
        $data = array(
            "site_name"         => $app->getCfg("sitename"),
            "site_url"          => JUri::root(),
            "item_title"        => $project->getTitle(),
            "item_url"          => $website.JRoute::_(CrowdFundingHelperRoute::getDetailsRoute($project->getSlug(), $project->getCatSlug())),
            "amount"            => ITPrismString::getAmount($transaction->txn_amount, $transaction->txn_currency),
            "transaction_id"    => $transaction->txn_id
        );
        
        // Send mail to the administrator
        $emailId = $this->params->get("admin_mail_id", 0);
        if(!empty($emailId)) {
            
            $table    = new CrowdFundingTableEmail(JFactory::getDbo());
            $email    = new CrowdFundingEmail();
            $email->setTable($table);
            $email->load($emailId);
            
            if(!$email->getSenderName()) {
                $email->setSenderName($app->getCfg("fromname"));
            }
            if(!$email->getSenderEmail()) {
                $email->setSenderEmail($app->getCfg("mailfrom"));
            }
            
            $recipientName = $email->getSenderName();
            $recipientMail = $email->getSenderEmail();
            
            // Prepare data for parsing
            $data["sender_name"]     =  $email->getSenderName();
            $data["sender_email"]    =  $email->getSenderEmail();
            $data["recipient_name"]  =  $recipientName;
            $data["recipient_email"] =  $recipientMail;
            
            $email->parse($data);
            $subject    = $email->getSubject();
            $body       = $email->getBody($emailMode);
            
            $mailer  = JFactory::getMailer();
            if(strcmp("html", $emailMode) == 0) { // Send as HTML message
                $return  = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $recipientMail, $subject, $body, CrowdFundingEmail::MAIL_MODE_HTML);
            
            } else { // Send as plain text.
                $return  = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $recipientMail, $subject, $body, CrowdFundingEmail::MAIL_MODE_PLAIN);
            
            }
            
            // Check for an error.
            if ($return !== true) {
        
                // Log error
                $this->log->add(
                    JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_ERROR_MAIL_SENDING_ADMIN"),
                    "BANKTRANSFER_PAYMENT_PLUGIN_ERROR"
                );
        
            }
        
        }
        
        // Send mail to project owner
        $emailId = $this->params->get("creator_mail_id", 0);
        if(!empty($emailId)) {
        
            $table    = new CrowdFundingTableEmail(JFactory::getDbo());
            $email    = new CrowdFundingEmail();
            $email->setTable($table);
            $email->load($emailId);
            
            if(!$email->getSenderName()) {
                $email->setSenderName($app->getCfg("fromname"));
            }
            if(!$email->getSenderEmail()) {
                $email->setSenderEmail($app->getCfg("mailfrom"));
            }

            $user          = JFactory::getUser($transaction->receiver_id);
            $recipientName = $user->get("name");
            $recipientMail = $user->get("email");
            
            // Prepare data for parsing
            $data["sender_name"]     =  $email->getSenderName();
            $data["sender_email"]    =  $email->getSenderEmail();
            $data["recipient_name"]  =  $recipientName;
            $data["recipient_email"] =  $recipientMail;
            
            $email->parse($data);
            $subject    = $email->getSubject();
            $body       = $email->getBody($emailMode);
            
            $mailer  = JFactory::getMailer();
            if(strcmp("html", $emailMode) == 0) { // Send as HTML message
                $return  = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $recipientMail, $subject, $body, CrowdFundingEmail::MAIL_MODE_HTML);
            
            } else { // Send as plain text.
                $return  = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $recipientMail, $subject, $body, CrowdFundingEmail::MAIL_MODE_PLAIN);
            
            }
            
            // Check for an error.
            if ($return !== true) {
        
                // Log error
                $this->log->add(
                    JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_ERROR_MAIL_SENDING_PROJECT_OWNER"),
                    "BANKTRANSFER_PAYMENT_PLUGIN_ERROR"
                );
        
            }
        }
        
        // Send mail to backer
        $emailId    = $this->params->get("user_mail_id", 0);
        $investorId = $transaction->investor_id;
        if(!empty($emailId) AND !empty($investorId)) {
        
            $table    = new CrowdFundingTableEmail(JFactory::getDbo());
            $email    = new CrowdFundingEmail();
            $email->setTable($table);
            $email->load($emailId);
            
            if(!$email->getSenderName()) {
                $email->setSenderName($app->getCfg("fromname"));
            }
            if(!$email->getSenderEmail()) {
                $email->setSenderEmail($app->getCfg("mailfrom"));
            }

            $user          = JFactory::getUser($investorId);
            $recipientName = $user->get("name");
            $recipientMail = $user->get("email");
            
            // Prepare data for parsing
            $data["sender_name"]     =  $email->getSenderName();
            $data["sender_email"]    =  $email->getSenderEmail();
            $data["recipient_name"]  =  $recipientName;
            $data["recipient_email"] =  $recipientMail;
            
            $email->parse($data);
            $subject    = $email->getSubject();
            $body       = $email->getBody($emailMode);
            
            $mailer  = JFactory::getMailer();
            if(strcmp("html", $emailMode) == 0) { // Send as HTML message
                $return  = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $recipientMail, $subject, $body, CrowdFundingEmail::MAIL_MODE_HTML);
            
            } else { // Send as plain text.
                $return  = $mailer->sendMail($email->getSenderEmail(), $email->getSenderName(), $recipientMail, $subject, $body, CrowdFundingEmail::MAIL_MODE_PLAIN);
            
            }
            
            // Check for an error.
            if ($return !== true) {
        
                // Log error
                $this->log->add(
                    JText::_("PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_ERROR_MAIL_SENDING_USER"),
                    "BANKTRANSFER_PAYMENT_PLUGIN_ERROR"
                );
        
            }
        
        }
        
    }
    
}