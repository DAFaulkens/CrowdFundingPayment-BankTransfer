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

jimport('crowdfunding.init');
jimport('crowdfunding.payment.plugin');

/**
 * CrowdFunding Bank Transfer Payment Plugin
 *
 * @package      CrowdFunding
 * @subpackage   Plugins
 */
class plgCrowdFundingPaymentBankTransfer extends CrowdFundingPaymentPlugin
{
    protected $paymentService = "banktransfer";
    protected $version = "1.10";

    protected $textPrefix = "PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER";
    protected $debugType = "BANKTRANSFER_PAYMENT_PLUGIN_DEBUG";

    /**
     * @var JApplicationSite
     */
    protected $app;

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

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("html", $docType) != 0) {
            return null;
        }

        // Load Twitter Bootstrap and its styles,
        // because I am going to use them for a modal window.
        if ($params->get("bootstrap_modal", false)) {
            JHtml::_("bootstrap.framework");
            JHtml::_("itprism.ui.bootstrap_modal");
        }

        // Get the path for the layout file
        $path = JPath::clean(JPluginHelper::getLayoutPath('crowdfundingpayment', 'banktransfer'));

        ob_start();
        include $path;
        $html = ob_get_clean();

        return $html;

    }

    /**
     * This method performs the transaction.
     *
     * @param string $context
     * @param Joomla\Registry\Registry $params
     *
     * @return null|array
     */
    public function onPaymentNotify($context, &$params)
    {
        if (strcmp("com_crowdfunding.notify.banktransfer", $context) != 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp("raw", $docType) != 0) {
            return null;
        }

        $projectId = $this->app->input->getInt("pid");
        $amount    = $this->app->input->getFloat("amount");

        // Prepare the array that will be returned by this method
        $result = array(
            "project"         => null,
            "reward"          => null,
            "transaction"     => null,
            "payment_session" => null,
            "redirect_url"    => null,
            "message"         => null
        );

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
                    "REQUEST METHOD" => $this->app->input->getMethod(),
                    "_REQUEST"       => $_REQUEST
                )
            );

            return null;
        }

        jimport("crowdfunding.currency");
        $currencyId = $params->get("project_currency");
        $currency   = CrowdFundingCurrency::getInstance(JFactory::getDbo(), $currencyId, $params);

        // Prepare return URL
        $filter = JFilterInput::getInstance();

        $uri = JUri::getInstance();
        $domain = $filter->clean($uri->toString(array("scheme", "host")));

        $result["redirect_url"] = JString::trim($this->params->get('return_url'));
        if (!$result["redirect_url"]) {
            $result["redirect_url"] = $domain . JRoute::_(CrowdFundingHelperRoute::getBackingRoute($project->getSlug(), $project->getCatslug(), "share"), false);
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RETURN_URL"), $this->debugType, $result["redirect_url"]) : null;

        // Intentions

        $userId  = JFactory::getUser()->get("id");
        $aUserId = $this->app->getUserState("auser_id");

        // Reset anonymous user hash ID,
        // because the intention based in it will be removed when transaction completes.
        if (!empty($aUserId)) {
            $this->app->setUserState("auser_id", "");
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

        // Generate data object, based on the intention properties.
        $properties       = $intention->getProperties();
        $result["payment_session"] = JArrayHelper::toObject($properties);

        // Set message to the user.
        $result["message"] = JText::sprintf($this->textPrefix . "_TRANSACTION_REGISTERED", $transaction->getTransactionId(), $transaction->getTransactionId());

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESULT_DATA"), $this->debugType, $result) : null;

        return $result;
    }

    /**
     * This method is invoked after complete payment.
     * It is used to be sent mails to user and administrator
     *
     * @param object $context
     * @param object $transaction Transaction data
     * @param Joomla\Registry\Registry $params Component parameters
     * @param object $project Project data
     * @param object $reward Reward data
     * @param object $paymentSession Payment session data.
     *
     */
    public function onAfterPayment($context, &$transaction, &$params, &$project, &$reward, &$paymentSession)
    {
        if (strcmp("com_crowdfunding.notify.banktransfer", $context) != 0) {
            return;
        }

        if ($this->app->isAdmin()) {
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
