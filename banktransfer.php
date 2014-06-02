<?php
/**
 * @package      CrowdFunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2014 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

jimport('crowdfunding.payment.plugin');

/**
 * CrowdFunding Bank Transfer Payment Plugin
 *
 * @package      CrowdFunding
 * @subpackage   Plugins
 *
 * @todo         Use $this->app and $autoloadLanguage to true, when Joomla! 2.5 is not actual anymore.
 */
class plgCrowdFundingPaymentBankTransfer extends CrowdFundingPaymentPlugin
{
    protected $paymentService = "banktransfer";
    protected $version = "1.7";

    protected $textPrefix = "PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER";
    protected $debugType = "BANKTRANSFER_PAYMENT_PLUGIN_DEBUG";

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param object    $item    A project data.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|string
     */
    public function onProjectPayment($context, &$item, &$params)
    {
        if (strcmp("com_crowdfunding.payment", $context) != 0) {
            return null;
        }

        $app = JFactory::getApplication();
        /** @var $app JApplicationSite */

        if ($app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml * */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("html", $docType) != 0) {
            return null;
        }

        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/banktransfer";

        // Load Twitter Bootstrap and its styles,
        // because I am going to use them for a modal window.
        if (version_compare(JVERSION, "3", ">=")) {

            JHtml::_("jquery.framework");
            if ($params->get("bootstrap_modal", false)) {
                JHtml::addIncludePath(ITPRISM_PATH_LIBRARY . '/ui/helpers');
                JHtml::_("bootstrap.framework");
                JHtml::_("itprism.ui.bootstrap_modal");
            }

        } else {
            if ($params->get("bootstrap_modal", false)) {
                JHtml::addIncludePath(ITPRISM_PATH_LIBRARY . '/ui/helpers');
                JHtml::addIncludePath(JPATH_COMPONENT . '/helpers/html');
                JHtml::_("crowdfunding.bootstrap");
                JHtml::_("itprism.ui.bootstrap_modal");
            }
        }

        // Load the script that initializes the select element with banks.
        $doc->addScript($pluginURI . "/js/plg_crowdfundingpayment_banktransfer.js?v=" . rawurlencode($this->version));

        $html   = array();
        $html[] = '<h4><img src="' . $pluginURI . '/images/bank_icon.png" width="30" height="26" />' . JText::_($this->textPrefix . "_TITLE") . '</h4>';
        $html[] = '<div>' . nl2br($this->params->get("beneficiary")) . '</div>';

        // Check for valid beneficiary information. If missing information, display error message.
        $beneficiaryInfo = JString::trim(strip_tags($this->params->get("beneficiary")));
        if (!$beneficiaryInfo) {
            $html[] = '<div class="alert">' . JText::_($this->textPrefix . "_ERROR_PLUGIN_NOT_CONFIGURED") . '</div>';

            return implode("\n", $html);
        }

        if ($this->params->get("display_additional_info", 1)) {
            $additionalInfo = JString::trim($this->params->get("additional_info"));
            if (!empty($additionalInfo)) {
                $html[] = '<p class="sticky">' . htmlspecialchars($additionalInfo, ENT_QUOTES, "UTF-8") . '</p>';
            } else {
                $html[] = '<p class="sticky">' . JText::_($this->textPrefix . "_INFO") . '</p>';
            }
        }

        $html[] = '<div class="alert hide" id="js-bt-alert"></div>';

        $html[] = '<div class="clearfix"></div>';
        $html[] = '<a href="#" class="btn btn-primary" id="js-register-bt">' . JText::_($this->textPrefix . "_MAKE_BANK_TRANSFER") . '</a>';
        $html[] = '<a href="#" class="btn btn-success hide" id="js-continue-bt">' . JText::_($this->textPrefix . "_CONTINUE_NEXT_STEP") . '</a>';

        $html[] = '    
    <div class="modal hide fade" id="js-banktransfer-modal">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
            <h3>' . JText::_($this->textPrefix . "_TITLE") . '</h3>
        </div>
        <div class="modal-body">
            <p>' . JText::_($this->textPrefix . "_REGISTER_TRANSACTION_QUESTION") . '</p>
        </div>
        <div class="modal-footer">
            <img src="media/com_crowdfunding/images/ajax-loader.gif" width="16" height="16" id="js-banktransfer-ajax-loading" style="display: none;" />
            <button class="btn btn-primary" id="js-btbtn-yes" data-project-id="' . $item->id . '" data-amount="' . $item->amount . '">' . JText::_("JYES") . '</button>
            <button class="btn" id="js-btbtn-no">' . JText::_("JNO") . '</button>
        </div>
    </div>';

        return implode("\n", $html);

    }

    /**
     * This method performs the transaction.
     *
     * @param string $context
     * @param Joomla\Registry\Registry $params
     *
     * @return null|array
     */
    public function onPaymenNotify($context, &$params)
    {
        if (strcmp("com_crowdfunding.notify.banktransfer", $context) != 0) {
            return null;
        }

        $app = JFactory::getApplication();
        /** @var $app JApplicationSite */

        if ($app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return null;
        }

        $projectId = $app->input->getInt("pid");
        $amount    = $app->input->getFloat("amount");

        // Prepare the array that will be returned by this method
        $result = array(
            "project"         => null,
            "reward"          => null,
            "transaction"     => null,
            "redirect_url"    => null,
            "message"         => null
        );

        $uri = JUri::getInstance();

        // Get project
        jimport("crowdfunding.project");
        $project = new CrowdFundingProject(JFactory::getDbo());
        $project->load($projectId);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PROJECT_OBJECT"), $this->debugType, $project->getProperties()) : null;

        // Check for valid project
        if (!$project->getId()) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_PROJECT"),
                $this->debugType,
                array(
                    "PROJECT OBJECT" => $project->getProperties(),
                    "REQUEST METHOD" => $app->input->getMethod(),
                    "_REQUEST"       => $_REQUEST
                )
            );

            return null;
        }

        jimport("crowdfunding.currency");
        $currencyId = $params->get("project_currency");
        $currency   = CrowdFundingCurrency::getInstance(JFactory::getDbo(), $currencyId, $params);

        // Prepare return URL
        $result["redirect_url"] = JString::trim($this->params->get('return_url'));
        if (!$result["redirect_url"]) {
            $result["redirect_url"] = $uri->toString(array("scheme", "host")) . JRoute::_(CrowdFundingHelperRoute::getBackingRoute($project->getSlug(), $project->getCatslug(), "share"), false);
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RETURN_URL"), $this->debugType, $result["redirect_url"]) : null;

        // Intentions

        $userId  = JFactory::getUser()->get("id");
        $aUserId = $app->getUserState("auser_id");

        // Reset anonymous user hash ID,
        // because the intention based in it will be removed when transaction completes.
        if (!empty($aUserId)) {
            $app->setUserState("auser_id", "");
        }

        $intention = $this->getIntention(array(
            "user_id"    => $userId,
            "auser_id"   => $aUserId,
            "project_id" => $projectId
        ));

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_INTENTION_OBJECT"), $this->debugType, $intention->getProperties()) : null;

        // Validate intention record
        if (!$intention->getId()) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_INTENTION"),
                $this->debugType,
                $intention->getProperties()
            );

            // Send response to the browser
            $response = array(
                "success" => false,
                "title"   => JText::_($this->textPrefix . "_FAIL"),
                "text"    => JText::_($this->textPrefix . "_ERROR_INVALID_PROJECT")
            );

            return $response;
        }

        // Validate a reward and update the number of distributed ones.
        // If the user is anonymous, the system will store 0 for reward ID.
        // The anonymous users can't select rewards.
        $rewardId = ($intention->isAnonymous()) ? 0 : (int)$intention->getRewardId();
        if (!empty($rewardId)) {

            $validData = array(
                "reward_id"  => $rewardId,
                "project_id" => $projectId,
                "txn_amount" => $amount
            );

            $reward  = $this->updateReward($validData);

            // Validate the reward.
            if (!$reward) {
                $rewardId = 0;
            }
        }

        // Prepare transaction data
        jimport("itprism.string");
        $transactionId = new ITPrismString();
        $transactionId->generateRandomString(12, "BT");

        $transactionId   = JString::strtoupper($transactionId);
        $transactionData = array(
            "txn_amount"       => $amount,
            "txn_currency"     => $currency->getAbbr(),
            "txn_status"       => "pending",
            "txn_id"           => $transactionId,
            "project_id"       => $projectId,
            "reward_id"        => $rewardId,
            "investor_id"      => (int)$userId,
            "receiver_id"      => (int)$project->getUserId(),
            "service_provider" => "Bank Transfer"
        );

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_TRANSACTION_DATA"), $this->debugType, $transactionData) : null;

        // Auto complete transaction
        if ($this->params->get("auto_complete", 0)) {
            $transactionData["txn_status"] = "completed";
            $project->addFunds($amount);
            $project->updateFunds();
        }

        // Store transaction data
        jimport("crowdfunding.transaction");
        $transaction = new CrowdFundingTransaction(JFactory::getDbo());
        $transaction->bind($transactionData);

        $transaction->store();

        // Generate object of data, based on the transaction properties.
        $properties = $transaction->getProperties();
        $result["transaction"] = JArrayHelper::toObject($properties);

        // Generate object of data, based on the project properties.
        $properties        = $project->getProperties();
        $result["project"] = JArrayHelper::toObject($properties);

        // Generate object of data, based on the reward properties.
        if (!empty($reward)) {
            $properties       = $reward->getProperties();
            $result["reward"] = JArrayHelper::toObject($properties);
        }

        // Remove intention
        $intention->delete();

        // Set message to the user.
        $result["message"] = JText::sprintf($this->textPrefix . "_TRANSACTION_REGISTERED", $transaction->getTransactionId(), $transaction->getTransactionId());

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESULT_DATA"), $this->debugType, $result) : null;

        return $result;
    }

    /**
     * This method is executed after complete payment.
     * It is used to be sent mails to user and administrator
     *
     * @param object $context
     * @param object $transaction Transaction data
     * @param Joomla\Registry\Registry $params Component parameters
     * @param object $project Project data
     * @param object $reward Reward data
     *
     */
    public function onAfterPayment($context, &$transaction, &$params, &$project, &$reward)
    {
        if (strcmp("com_crowdfunding.notify.banktransfer", $context) != 0) {
            return;
        }

        $app = JFactory::getApplication();
        /** @var $app JApplicationSite */

        if ($app->isAdmin()) {
            return;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return;
        }

        // Send mails
        $this->sendMails($project, $transaction, $params);
    }
}
