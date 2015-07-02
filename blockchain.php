<?php
/**
 * @package         Crowdfunding
 * @subpackage      Plugins
 * @author          Todor Iliev
 * @copyright       Copyright (C) 2015 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license         http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

jimport("Prism.init");
jimport("Crowdfunding.init");
jimport("EmailTemplates.init");

/**
 * Crowdfunding Blockchain payment plugin
 *
 * @package        Crowdfunding
 * @subpackage     Plugins
 */
class plgCrowdfundingPaymentBlockchain extends Crowdfunding\Payment\Plugin
{
    protected $paymentService = "blockchain";

    protected $textPrefix     = "PLG_CROWDFUNDINGPAYMENT_BLOCKCHAIN";
    protected $debugType      = "BLOCKCHAIN_PAYMENT_PLUGIN_DEBUG";

    protected $extraDataKeys  = array("value", "input_address", "confirmations", "transaction_hash", "input_transaction_hash", "destination_address", "anonymous");

    /**
     * @var JApplicationSite
     */
    protected $app;

    /**
     * This method prepare and return address to Blockchain,
     * where the user have to go to make a donation.
     *
     * @param string $context
     * @param object $item
     *
     * @return null|string
     */
    public function onProjectPayment($context, &$item)
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

        // This is a URI path to the plugin folder
        $pluginURI = "plugins/crowdfundingpayment/blockchain";

        $html   = array();
        $html[] = '<div class="well">'; // Open "well".

        $html[] = '<h4><img src="' . $pluginURI . '/images/blockchain_icon.png" width="38" height="32" /> ' . JText::_($this->textPrefix . "_TITLE") . '</h4>';

        // Check for valid data.
        $receivingAddress   = Joomla\String\String::trim($this->params->get("receiving_address"));
        $callbackUrl        = $this->getCallbackUrl();

        if (!$receivingAddress or !$callbackUrl) {
            $html[] = '<div class="bg-warning mtb-10"><span class="glyphicon glyphicon-warning-sign"></span> ' . JText::_($this->textPrefix . "_ERROR_PLUGIN_NOT_CONFIGURED") . '</div>';
            $html[] = '</div>'; // Close "well".

            return implode("\n", $html);
        }

        // Get payment session
        $paymentSessionContext    = Crowdfunding\Constants::PAYMENT_SESSION_CONTEXT . $item->id;
        $paymentSessionLocal      = $this->app->getUserState($paymentSessionContext);

        $paymentSession = $this->getPaymentSession(array(
            "session_id"    => $paymentSessionLocal->session_id
        ));

        // Prepare transaction ID.
        $txnId         = new Prism\String();
        $txnId->generateRandomString();
        $txnId = Joomla\String\String::strtoupper($txnId);

        // Store the unique key.
        $paymentSession->setUniqueKey($txnId);
        $paymentSession->storeUniqueKey();

        // Prepare callback URL data.
        $callbackUrl .= "&payment_session_id=".(int)$paymentSession->getId()."&txn_id=".$txnId;

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_CALLBACK_URL"), $this->debugType, $callbackUrl) : null;

        // Send request for button
        jimport("Prism.Payment.Blockchain.Blockchain");

        $blockchain = new Blockchain();
        $response   = $blockchain->Receive->generate($receivingAddress, $callbackUrl);

        // DEBUG DATA
        $responseResult = @var_export($response, true);
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RECEIVE_GENERATE_RESPONSE"), $this->debugType, $responseResult) : null;


        // Check for test mode.
        if ($this->params->get("test_mode", 1)) {
            $html[] = '<div class="bg-info p-10-5"><span class="glyphicon glyphicon-info-sign"></span> ' . JText::_($this->textPrefix . "_ERROR_TEST_MODE") . '</div>';

            $html[] = '<label for="blockchain_callback_url">'.JText::_($this->textPrefix . "_CALLBACK_URL").'</label>';
            $html[] = '<textarea name="callback_url" id="blockchain_callback_url" class="form-control">'.$callbackUrl.'</textarea>';

        } else {

            $html[] = '<div class="form-group">';
            $html[] = '<label for="blockchain_receiving_address">' . JText::_($this->textPrefix . "_RECEIVING_ADDRESS") . '</label>';
            $html[] = '<input class="form-control input-lg" type="text" value="' . $response->address . '" id="blockchain_receiving_address"/>';
            $html[] = '</div>';

            $html[] = '<p class="bg-info p-10-5 mt-10"><span class="glyphicon glyphicon-info-sign"></span> ' . JText::sprintf($this->textPrefix . "_SEND_COINS_TO_ADDRESS", $item->amountFormated) . '</p>';
            $html[] = '<a class="btn btn-primary" href="'.JRoute::_(CrowdfundingHelperRoute::getBackingRoute($item->slug, $item->catslug, "share")).'"><span class="glyphicon glyphicon-chevron-right"></span> ' . JText::_($this->textPrefix . "_CONTINUE_NEXT_STEP") . '</a>';
        }

        $html[] = '</div>'; // Close "well".

        return implode("\n", $html);
    }

    /**
     * This method processes transaction.
     *
     * @param string    $context This string gives information about that where it has been executed the trigger.
     * @param Joomla\Registry\Registry $params  The parameters of the component
     *
     * @return null|array
     */
    public function onPaymentNotify($context, &$params)
    {
        if (strcmp("com_crowdfunding.notify.blockchain", $context) != 0) {
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

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESPONSE"), $this->debugType, $_GET) : null;

        $result = array(
            "project"         => null,
            "reward"          => null,
            "transaction"     => null,
            "payment_session" => null,
            "payment_service" => "Blockchain",
            "response"        => "" // Response to the payment service.
        );

        // Get extension parameters
        $currencyId = $params->get("project_currency");
        $currency   = Crowdfunding\Currency::getInstance(JFactory::getDbo(), $currencyId, $params);

        // Get payment session data
        $paymentSessionId = $this->app->input->get->get("payment_session_id");
        $paymentSession = $this->getPaymentSession(array("id" => $paymentSessionId));

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PAYMENT_SESSION"), $this->debugType, $paymentSession->getProperties()) : null;

        // Validate transaction data
        $validData = $this->validateData($_GET, $currency->getCode(), $paymentSession);
        if (is_null($validData)) {
            return $result;
        }

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_VALID_DATA"), $this->debugType, $validData) : null;

        // Get project
        $projectId = Joomla\Utilities\ArrayHelper::getValue($validData, "project_id");
        $project   = Crowdfunding\Project::getInstance(JFactory::getDbo(), $projectId);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_PROJECT_OBJECT"), $this->debugType, $project->getProperties()) : null;

        // Check for valid project
        if (!$project->getId()) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_PROJECT"),
                $this->debugType,
                $validData
            );

            return $result;
        }

        // Set the receiver of funds
        $validData["receiver_id"] = $project->getUserId();

        // Save transaction data.
        // If it is not completed, return empty results.
        // If it is complete, continue with process transaction data
        $transactionData = $this->storeTransaction($validData, $project);
        if (is_null($transactionData)) {
            return $result;
        }

        // Update the number of distributed reward.
        $rewardId = Joomla\Utilities\ArrayHelper::getValue($transactionData, "reward_id");
        $reward   = null;
        if (!empty($rewardId)) {
            $reward = $this->updateReward($transactionData);

            // Validate the reward.
            if (!$reward) {
                $transactionData["reward_id"] = 0;
            }
        }

        //  Prepare the data that will be returned

        $result["transaction"] = Joomla\Utilities\ArrayHelper::toObject($transactionData);

        // Generate object of data based on the project properties
        $properties        = $project->getProperties();
        $result["project"] = Joomla\Utilities\ArrayHelper::toObject($properties);

        // Generate object of data based on the reward properties
        if (!empty($reward)) {
            $properties       = $reward->getProperties();
            $result["reward"] = Joomla\Utilities\ArrayHelper::toObject($properties);
        }

        // Generate data object, based on the payment session properties.
        $properties       = $paymentSession->getProperties();
        $result["payment_session"] = Joomla\Utilities\ArrayHelper::toObject($properties);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_RESULT_DATA"), $this->debugType, $result) : null;

        // Remove payment session.
        $txnStatus = (isset($result["transaction"]->txn_status)) ? $result["transaction"]->txn_status : null;
        $this->closePaymentSession($paymentSession, $txnStatus);

        if (strcmp("completed", $result["transaction"]->txn_status) == 0) {
            $result["response"] = "*ok*";
        }

        return $result;
    }

    /**
     * This metod is executed after complete payment.
     * It is used to be sent mails to user and administrator
     *
     * @param string $context
     * @param object $transaction Transaction data
     * @param Joomla\Registry\Registry $params Component parameters
     * @param object $project Project data
     * @param object $reward Reward data
     * @param object $paymentSession Payment session data.
     */
    public function onAfterPayment($context, &$transaction, &$params, &$project, &$reward, &$paymentSession)
    {
        if (strcmp("com_crowdfunding.notify.blockchain", $context) != 0) {
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

    /**
     * Validate transaction data.
     *
     * @param array                 $data
     * @param string                $currency
     * @param Crowdfunding\Payment\Session $paymentSession
     *
     * @return null|array
     */
    protected function validateData($data, $currency, $paymentSession)
    {
        // Get transaction ID.
        $txnId     = Joomla\Utilities\ArrayHelper::getValue($data, "txn_id");

        // Prepare transaction amount.
        $amount    = Joomla\Utilities\ArrayHelper::getValue($data, "value", 0.000, "float");
        $amount    = $amount / 100000000;

        // Transaction date.
        $date      = new JDate();

        // Get transaction status
        $status        = "pending";
        $confirmations = Joomla\Utilities\ArrayHelper::getValue($data, "confirmations", 0, "int");
        if ($confirmations >= 6) {
            $status = "completed";
        }

        // If the transaction has been made by anonymous user, reset reward. Anonymous users cannot select rewards.
        $rewardId = ($paymentSession->isAnonymous()) ? 0 : (int)$paymentSession->getRewardId();

        // Get additional information from transaction.
        $extraData = $this->prepareExtraData($data);

        // Prepare transaction data
        $transaction = array(
            "investor_id"      => (int)$paymentSession->getUserId(),
            "project_id"       => (int)$paymentSession->getProjectId(),
            "reward_id"        => (int)$rewardId,
            "service_provider" => "Blockchain",
            "txn_id"           => $txnId,
            "txn_amount"       => (float)$amount,
            "txn_currency"     => $currency,
            "txn_status"       => $status,
            "txn_date"         => $date->toSql(),
            "extra_data"       => $extraData
        );

        // Check User Id, Project ID and Transaction ID
        if (!$transaction["txn_amount"]) {

            // Log data in the database
            $this->log->add(
                JText::_($this->textPrefix . "_ERROR_INVALID_TRANSACTION_DATA"),
                $this->debugType,
                $transaction
            );

            return null;
        }

        return $transaction;
    }

    /**
     * Save transaction
     *
     * @param array               $transactionData The data about transaction from the payment gateway.
     * @param Crowdfunding\Project $project
     *
     * @return null|array
     */
    public function storeTransaction($transactionData, $project)
    {
        // Get transaction by txn ID
        $keys        = array(
            "txn_id" => Joomla\Utilities\ArrayHelper::getValue($transactionData, "txn_id")
        );
        $transaction = new Crowdfunding\Transaction(JFactory::getDbo());
        $transaction->load($keys);

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_TRANSACTION_OBJECT"), $this->debugType, $transaction->getProperties()) : null;

        // Check for existed transaction
        if ($transaction->getId()) {

            // If the current status if completed,
            // stop the process.
            if ($transaction->isCompleted()) {
                return null;
            }

        }

        // Store the transaction data
        $transaction->bind($transactionData, array("extra_data"));
        $transaction->addExtraData($transactionData["extra_data"]);
        $transaction->store();

        // DEBUG DATA
        JDEBUG ? $this->log->add(JText::_($this->textPrefix . "_DEBUG_TRANSACTION_OBJECT_AFTER_STORED_DATA"), $this->debugType, $transaction->getProperties()) : null;

        // If it is not completed (it might be pending or other status),
        // stop the process. Only completed transaction will continue
        // and will process the project, rewards,...
        if (!$transaction->isCompleted()) {
            return null;
        }

        // Set transaction ID.
        $transactionData["id"] = $transaction->getId();

        // Update project funded amount
        $amount = Joomla\Utilities\ArrayHelper::getValue($transactionData, "txn_amount");
        $project->addFunds($amount);
        $project->storeFunds();

        return $transactionData;
    }
}
