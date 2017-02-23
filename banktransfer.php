<?php
/**
 * @package      Crowdfunding
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2017 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      GNU General Public License version 3 or later; see LICENSE.txt
 */

use Crowdfunding\Transaction\Transaction;
use Crowdfunding\Transaction\TransactionManager;
use Crowdfunding\Payment;
use Joomla\Registry\Registry;
use Prism\Payment\Result as PaymentResult;

// no direct access
defined('_JEXEC') or die;

jimport('Prism.init');
jimport('Crowdfunding.init');
jimport('Emailtemplates.init');

JObserverMapper::addObserverClassToClass(Crowdfunding\Observer\Transaction\TransactionObserver::class, Crowdfunding\Transaction\TransactionManager::class, array('typeAlias' => 'com_crowdfunding.payment'));

/**
 * Crowdfunding Bank Transfer Payment Plugin
 *
 * @package      Crowdfunding
 * @subpackage   Plugins
 */
class plgCrowdfundingPaymentBankTransfer extends Payment\Plugin
{
    protected $payout;
    protected $version = '2.5';

    protected $iban;
    protected $bankAccount;

    public function __construct(&$subject, $config = array())
    {
        $this->serviceProvider = 'Bank Transfer';
        $this->serviceAlias    = 'banktransfer';

        parent::__construct($subject, $config);
    }

    /**
     * This method prepares a payment gateway - buttons, forms,...
     * That gateway will be displayed on the summary page as a payment option.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param stdClass  $item    A project data.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @throws \InvalidArgumentException
     * @return null|string
     */
    public function onProjectPayment($context, $item, $params)
    {
        if (strcmp('com_crowdfunding.payment', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('html', $docType) !== 0) {
            return null;
        }

        $this->prepareBeneficiary($item->id);

        // Include IBAN to information about bank account.
        if ((bool)$this->params->get('show_iban', 0) and $this->iban !== '') {
            if (false !== strpos($this->bankAccount, '{IBAN}')) {
                $this->bankAccount = str_replace('{IBAN}', $this->iban, $this->bankAccount);
            } else {
                $this->bankAccount .= '<p><strong>'.JText::_($this->textPrefix . '_IBAN').'</strong>: '. $this->iban.'</p>';
            }
        }

        JHtml::_('jquery.framework');
        JText::script('PLG_CROWDFUNDINGPAYMENT_BANKTRANSFER_REGISTER_TRANSACTION_QUESTION');

        // Get the path for the layout file
        $path = JPath::clean(JPluginHelper::getLayoutPath('crowdfundingpayment', 'banktransfer'));

        ob_start();
        include $path;
        $html = ob_get_clean();

        return $html;
    }

    protected function prepareBeneficiary($itemId)
    {
        if (strcmp('project_owner', $this->params->get('payment_receiver', 'site_owner')) === 0) {
            if (JComponentHelper::isEnabled('com_crowdfundingfinance')) {
                if ($this->payout === null) {
                    $this->payout = new Crowdfundingfinance\Payout(JFactory::getDbo());
                    $this->payout->load(['project_id' => $itemId], ['secret_key' => $this->app->get('secret')]);
                }

                if (!$this->payout->getIban()) {
                    $this->log->add(JText::_($this->textPrefix . '_ERROR_CROWDFUNDING_FINANCE'), $this->errorType);
                    return '';
                }

                $this->iban        = trim((string)$this->payout->getIban());
                $this->bankAccount = (string)$this->payout->getBankAccount();
            } else {
                $this->log->add(JText::_($this->textPrefix . '_ERROR_CROWDFUNDING_FINANCE'), $this->errorType);
                return '';
            }
        } else {
            $this->iban        = trim((string)$this->params->get('iban', ''));
            $this->bankAccount = (string)$this->params->get('beneficiary');
        }
    }

    /**
     * This method performs the transaction.
     *
     * @param string $context
     * @param Joomla\Registry\Registry $params
     *
     * @throws \UnexpectedValueException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \OutOfBoundsException
     *
     * @return null|PaymentResult
     */
    public function onPaymentNotify($context, $params)
    {
        if (strcmp('com_crowdfunding.notify.banktransfer', $context) !== 0) {
            return null;
        }

        if ($this->app->isAdmin()) {
            return null;
        }

        $doc = JFactory::getDocument();
        /**  @var $doc JDocumentHtml */

        // Check document type
        $docType = $doc->getType();
        if (strcmp('raw', $docType) !== 0) {
            return null;
        }

        // Prepare the object that will be returned by this method.
        $paymentResult = new PaymentResult;

        $projectId = $this->app->input->getInt('pid');
        $amount    = $this->app->input->getFloat('amount');

        $containerHelper  = new Crowdfunding\Container\Helper();

        // Get project
        $project = $containerHelper->fetchProject($this->container, $projectId);

        // Check for valid project.
        if (!$project->getId()) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_PROJECT'), $this->errorType, array('REQUEST METHOD' => $this->app->input->getMethod(), '_REQUEST' => $_REQUEST));
            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PROJECT_OBJECT'), $this->debugType, $project->getProperties()) : null;

        // Payment Session

        // Get the payment session from database.
        $paymentSessionContext    = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $project->getId();
        $paymentSessionLocal      = $this->app->getUserState($paymentSessionContext);

        $paymentSessionRemote = $this->getPaymentSession(array(
            'session_id'    => $paymentSessionLocal->session_id
        ));

        // Validate payment session.
        if (!$paymentSessionRemote->getId()) {
            $this->log->add(JText::_($this->textPrefix . '_ERROR_INVALID_PAYMENT_SESSION'), $this->errorType, $paymentSessionRemote->getProperties());
            return null;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_PAYMENT_SESSION_OBJECT'), $this->debugType, $paymentSessionRemote->getProperties()) : null;

        // Prepare return URL
        $paymentResult->redirectUrl = trim($this->params->get('return_url'));
        if (!$paymentResult->redirectUrl) {
            $filter = JFilterInput::getInstance();

            $uri    = JUri::getInstance();
            $domain = $filter->clean($uri->toString(array('scheme', 'host')));

            $paymentResult->redirectUrl = $domain . JRoute::_(CrowdfundingHelperRoute::getBackingRoute($project->getSlug(), $project->getCatSlug(), 'share'), false);
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_RETURN_URL'), $this->debugType, $paymentResult->redirectUrl) : null;

        // Validate a reward and update the number of distributed ones.
        // If the user is anonymous, the system will store 0 for reward ID.
        // The anonymous users can't select rewards.
        $rewardId = $paymentSessionRemote->isAnonymous() ? 0 : (int)$paymentSessionRemote->getRewardId();
        if ($rewardId > 0) {
            $rewardRecord = new Crowdfunding\Validator\Reward\Record(JFactory::getDbo(), $rewardId, array('state' => Prism\Constants::PUBLISHED));
            if (!$rewardRecord->isValid()) {
                $rewardId = 0;
            }
        }

        $investorId          = JFactory::getUser()->get('id');
        $receiverId          = $project->getUserId();
        $anonymousUserId     = $this->app->getUserState('auser_id');

        $currency            = $containerHelper->fetchCurrency($this->container, $params);

        // Reset anonymous user hash ID,
        // because the payment session based on it will be removed when transaction completes.
        if ($anonymousUserId !== null and $anonymousUserId !== '') {
            $this->app->setUserState('auser_id', '');
        }

        // Prepare transaction data
        $transactionId   = Prism\Utilities\StringHelper::generateRandomString(12, 'BT');
        $transactionData = array(
            'txn_amount'       => $amount,
            'txn_currency'     => $currency->getCode(),
            'txn_status'       => $this->params->get('auto_complete', 0) ? 'completed' : 'pending',
            'txn_id'           => strtoupper($transactionId),
            'project_id'       => (int)$projectId,
            'reward_id'        => (int)$rewardId,
            'investor_id'      => (int)$investorId,
            'receiver_id'      => (int)$receiverId,
            'service_provider' => $this->serviceProvider,
            'service_alias'    => $this->serviceAlias
        );

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . '_DEBUG_TRANSACTION_DATA'), $this->debugType, $transactionData) : null;

        // Get reward object.
        $reward = null;
        if ($transactionData['reward_id']) {
            $reward = $containerHelper->fetchReward($this->container, $transactionData['reward_id'], $transactionData['project_id']);
        }

        // Store transaction data
        $transaction = new Transaction(JFactory::getDbo());
        $transaction->bind($transactionData);

        // Start database transaction.
        $db = JFactory::getDbo();

        try {
            $db->transactionStart();

            $options = array(
                'old_status' => null,
                'new_status' => $transactionData['txn_status']
            );

            $transactionManager = new TransactionManager(JFactory::getDbo());
            $transactionManager->setTransaction($transaction);
            $transactionManager->process('com_crowdfunding.payment', $options);

            $db->transactionCommit();
        } catch (Exception $e) {
            $db->transactionRollback();

            $this->log->add(JText::_($this->textPrefix . '_ERROR_TRANSACTION_PROCESS'), $this->errorType, $e->getMessage());
            return null;
        }

        // Generate object of data, based on the transaction properties.
        $paymentResult->transaction = $transaction;

        // Generate object of data based on the project properties.
        $paymentResult->project = $project;

        // Generate object of data based on the reward properties.
        if ($reward !== null and ($reward instanceof Crowdfunding\Reward)) {
            $paymentResult->reward = $reward;
        }

        // Generate data object, based on the payment session properties.
        $paymentResult->paymentSession = $paymentSessionRemote;

        // Set message to the user.
        $paymentResult->message = JText::sprintf($this->textPrefix . '_TRANSACTION_REGISTERED', $transaction->getTransactionId(), $transaction->getTransactionId());

        $this->removeIntention($paymentSessionRemote, $transaction);

        return $paymentResult;
    }

    /**
     * This method is executed after complete payment notification.
     * It is used to be sent mails to users and the administrator.
     *
     * <code>
     * $paymentResult->transaction;
     * $paymentResult->project;
     * $paymentResult->reward;
     * $paymentResult->paymentSession;
     * $paymentResult->serviceProvider;
     * $paymentResult->serviceAlias;
     * $paymentResult->response;
     * $paymentResult->returnUrl;
     * $paymentResult->message;
     * $paymentResult->triggerEvents;
     * $paymentResult->paymentData;
     * </code>
     *
     * @param string $context
     * @param PaymentResult $paymentResult  Object that contains Transaction, Reward, Project, PaymentSession, etc.
     * @param Registry $params Component parameters
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \OutOfBoundsException
     */
    public function onAfterPaymentNotify($context, $paymentResult, $params)
    {
        if (!preg_match('/com_crowdfunding\.(notify|payments)\.'.$this->serviceAlias.'/', $context)) {
            return;
        }

        if ($this->app->isAdmin()) {
            return;
        }

        // Check document type
        $docType = \JFactory::getDocument()->getType();
        if (!in_array($docType, array('raw', 'html'), true)) {
            return;
        }

        // Prepare payment data - IBAN and BankAccount.
        $project = $paymentResult->project;
        $this->prepareBeneficiary($project->getId());

        $paymentResult->paymentData['banktransfer'] = [
            'iban' => $this->iban,
            'bank_account' => $this->bankAccount
        ];

        // Send mails
        $this->sendMails($paymentResult, $params);
    }
}
